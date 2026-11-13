<?php

namespace Synth\Api\V2;

use Synth\Core\NarratorOutput;

require_once dirname(__DIR__, 2).'/core/contracts/narrator.php';
require_once dirname(__DIR__, 2).'/lib/response.php';

// Request-local NDJSON output and liveness for one v2 request. Lines use the shared wire encoders and route
// recorder; the body kept here is the replayable response stored with the request's first terminal outcome.
final class ProtocolOutput implements NarratorOutput
{
    private string $body = '';
    private bool $started = false;
    private bool $final = false;
    private ?\SYNTHClaimedRequest $owner = null;
    private int $sequence = 0;

    public function __construct(private $db, private array $event) {}

    // The legacy streaming output setup: stray buffered output is discarded, streaming headers precede claim
    // admission as on every other v2 route, and the turn survives a client abort within the same time bound.
    public function open(): void
    {
        while (!getenv('PHPUNIT_TEST') && ob_get_length() && ob_end_clean());
        synth_protocol_ndjson_headers(true);
        ignore_user_abort(true);
        set_time_limit(1200);
    }

    // Route records are written under this explicit claimed owner, never a request global.
    public function claimed(\SYNTHClaimedRequest $owner): void
    {
        if ($owner->event !== $this->event) throw new \LogicException('Native request owner mismatch');
        $this->owner = $owner;
    }

    public function assertActive(): void
    {
        synth_protocol_assert_owner_active($this->db, $this->event);
    }

    public function allocateLine(): string
    {
        return 'dialogue:'.++$this->sequence;
    }

    public function dialogue(string $suffix, string $lineId, array $payload): void
    {
        $this->start();
        $this->line('dialogue', $payload, $suffix, ['utterance_id' => $lineId]);
    }

    // A typed line other than dialogue: private narrator state, reply and status lines.
    public function emit(string $type, string $suffix, array $payload): void
    {
        $this->start();
        $this->line($type, $payload, $suffix);
    }

    public function complete(): void
    {
        $this->conclude('complete', '', $this->head().$this->encode('response_end', ['status' => 'complete'], 'end'));
    }

    // Truthful terminal failure. Lines already sent stay generated (unheard unless receipted).
    public function fail(string $code, string $detail): void
    {
        if (!$this->started) { $this->terminal($code, $detail); return; }
        $this->conclude('failed', $code, $this->encode('status', ['code' => $code, 'detail' => $detail], 'status')
            .$this->encode('response_end', ['status' => 'error'], 'end'));
    }

    // An explicit conversation request never silently goes legacy.
    public function reject(string $reason): void
    {
        $this->terminal('native_turn_ineligible', $reason);
    }

    // Receipt history commits first, idempotently; a lost or failed acknowledgement may be retried safely.
    public function ack(string $status, array $detail = []): void
    {
        synth_protocol_ndjson_headers(false);
        $this->conclude('complete', '', synth_protocol_encode_ack($status, $detail, $this->event));
    }

    public function terminal(string $code, string $detail = ''): void
    {
        synth_protocol_ndjson_headers(false);
        $this->conclude('failed', $code, synth_protocol_encode_terminal_error($code, $detail, $this->event));
    }

    // Server terminal cancellation closes the stream once; an earlier recorded outcome is never replaced.
    public function cancelled(): void
    {
        if ($this->final) return;
        $this->conclude('cancelled', 'request_cancelled', $this->cancellation());
    }

    // Unexpected failure: a generic error status that never reflects exception text.
    public function failed(): void
    {
        if ($this->final) return;
        $this->conclude('failed', 'backend_request_failed', $this->failure());
    }

    private function start(): void
    {
        if ($this->started) return;
        $this->line('response_start', synth_protocol_start_payload(), 'start');
    }

    private function line(string $type, array $payload, string $suffix, array $routeMetadata = []): void
    {
        $this->started = true;
        $wire = $this->encode($type, $payload, $suffix);
        $this->deliver(synth_protocol_prepare_line($this->db, $this->event, $this->owner !== null, $type, $wire, $routeMetadata,
            $this->owner));
    }

    // The legacy writer order: route prepared, replay body updated, bytes echoed, then flushed.
    private function deliver(string $wire): void
    {
        $this->body .= $wire;
        echo $wire;
        synth_protocol_flush_output();
    }

    private function encode(string $type, array $payload, string $suffix): string
    {
        return synth_protocol_encode_response_line($type, $payload, $suffix, $this->event);
    }

    // The opening line when nothing has been sent yet.
    private function head(): string
    {
        return $this->started ? '' : $this->encode('response_start', synth_protocol_start_payload(), 'start');
    }

    private function cancellation(): string
    {
        return $this->head().$this->encode('response_end', ['status' => 'cancelled'], 'end');
    }

    private function failure(): string
    {
        return $this->started
            ? $this->encode('status', ['code' => 'backend_request_failed'], 'status:'.++$this->sequence)
                .$this->encode('response_end', ['status' => 'error'], 'end')
            : synth_protocol_encode_terminal_error('backend_request_failed', '', $this->event);
    }

    // The terminal outcome is committed under the claim/cancel lock before its bytes are sent; no network write
    // happens inside the transaction. A competing cancellation or supersession that won closes the stream as
    // cancelled after the delivered prefix. A persistence failure never reports the proposed outcome: the
    // generic failure is recorded if possible and sent either way.
    private function conclude(string $state, string $code, string $tail): void
    {
        $this->final = true;
        if ($this->owner === null) { $this->send($tail); return; }
        $proposed = [$state, $tail];
        try { $outcome = $this->commit($state, $code, $tail); }
        catch (\Throwable) {
            \Logger::error('[request] terminal outcome persistence unavailable');
            $tail = $this->failure();
            try { $outcome = $this->commit('failed', 'backend_request_failed', $tail); }
            catch (\Throwable) { $outcome = ['won' => true]; }
        }
        $cancelled = ($outcome['state'] ?? '') === 'cancelled'
            || in_array($outcome['error_code'] ?? '', ['turn_cancelled', 'request_cancelled'], true);
        // An ambiguous first commit that did land is still this request's own outcome.
        if (!$outcome['won']) $tail = $cancelled ? $this->cancellation()
            : (($outcome['state'] ?? '') === $proposed[0] ? $proposed[1] : $this->failure());
        $this->send($tail);
    }

    private function commit(string $state, string $code, string $tail): array
    {
        return synth_protocol_commit_outcome($this->db, $this->event, $state, 'application/x-ndjson', $this->body.$tail, $code);
    }

    private function send(string $wire): void
    {
        $this->started = true;
        $this->deliver($wire);
    }
}
