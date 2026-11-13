<?php

namespace Synth\Managers;

use Synth\Core\LlmDriver;
use Synth\Core\NarratorOutput;
use Synth\Core\NarratorRejected;
use Synth\Core\PrivateNarratorRepository;
use Synth\Drivers\Tts\NarratorSpeechDriver;

require_once dirname(__DIR__).'/core/contracts/narrator.php';
require_once dirname(__DIR__).'/core/dbmanager/repositories/PrivateNarratorRepository.php';
require_once dirname(__DIR__).'/drivers/tts/NarratorSpeechDriver.php';

// One private narrator request: a state/select/cancel command, a typed, push-to-talk or scene turn, or the receipt
// of a reply. Narrator content is kept in its own tables and never becomes NPC dialogue, history, diary or memory.
// Provider and speech calls run outside every database transaction; the player's input is committed first.
final class PrivateNarratorManager
{
    public const STATE_TRANSCRIPT = 24;
    public const PROMPT_HISTORY = 12;
    public const MAX_REPLY_CHARS = 1000;
    public const MAX_OUTPUT_BYTES = 8192;
    private const RECEIPT_STATES = ['audio_completed' => 'spoken', 'interrupted' => 'interrupted', 'failed' => 'failed'];
    private const SCENE_FACTS = ['location', 'cell', 'worldspace', 'weather', 'interior'];

    /**
     * @param \Closure(array):string $transcribe uploaded push-to-talk media to text through the configured STT
     * @param \Closure():array $context the request's claimed context snapshot (synth_protocol_original_context shape)
     */
    public function __construct(private PrivateNarratorRepository $narrators, private LlmDriver $llm,
        private NarratorSpeechDriver $speech, private NarratorOutput $output, private \Closure $transcribe,
        private \Closure $context) {}

    public function handle(array $event): void
    {
        try {
            match ($event['type']) {
                'narrator_command' => $this->command($event['payload']),
                'narrator_turn' => $this->turn($event),
                'narrator_receipt' => $this->receipt($event['payload']),
            };
        } catch (NarratorRejected $rejected) {
            $this->output->terminal($rejected->getMessage());
        }
    }

    private function command(array $payload): void
    {
        $stateId = match ($payload['action']) {
            'select' => $this->narrators->select($payload['definition_id']),
            'cancel' => $this->narrators->cancelLane(),
            default => $this->lane()['state_id'],
        };
        $this->emitState($stateId, $payload['cursor'] ?? null);
        $this->output->complete();
    }

    private function turn(array $event): void
    {
        $payload = $event['payload'];
        $lane = $this->lane();
        $state = $this->narrators->state($lane['state_id']);
        if (intval($lane['state_id'] ?? 0) !== $payload['state_id']) throw new NarratorRejected('narrator_state_changed');
        if ($state['selected'] === null) throw new NarratorRejected('narrator_not_selected');
        if ($state['selected'] !== $payload['definition_id']) throw new NarratorRejected('narrator_selection_changed');
        $revision = $this->narrators->revision($state['applied'][$state['selected']]);
        if ($revision === null) throw new NarratorRejected('narrator_definition_unknown');
        $source = $this->narrators->profile($revision['profile_id'] === null ? null : intval($revision['profile_id']));
        if ($source === null || !$this->llm->supports($source['connector'])) throw new NarratorRejected('narrator_provider_unsupported');
        $name = trim($revision['name']);
        $speech = $this->speech->settings($source['profile'], $name, trim($revision['voice']));
        if ($speech === null) throw new NarratorRejected('narrator_speech_unavailable');
        $context = ($this->context)();
        $player = trim(strval($context['player']['display_name'] ?? '')) ?: 'Player';
        // Push-to-talk carries no scene facts. Typed turns add the copied facts that exist; scene commentary needs them.
        $scene = $payload['kind'] === 'voice' ? [] : self::sceneFacts($context);
        if ($payload['kind'] === 'scene' && $scene === []) throw new NarratorRejected('narrator_scene_unavailable');
        $text = match ($payload['kind']) {
            'typed' => trim($payload['text']),
            'voice' => $this->transcribe($payload['media']),
            'scene' => 'Comment on the current scene.',
        };
        $this->output->assertActive();
        $history = $this->narrators->transcript($state['persona_heads'][$state['selected']] ?? null, self::PROMPT_HISTORY, $state['selected']);
        $stateId = $this->narrators->recordInput($payload['state_id'], $state['selected'], intval($revision['revision_id']),
            $payload['kind'], mb_substr($player, 0, 64), mb_substr($text, 0, 4096), intval($revision['edit_generation']));
        $this->emitState($stateId);
        try {
            $reply = $this->generate($source['connector'], $this->messages($revision, $player, $scene, $history, $text, $payload['kind'],
                strval($speech['LLM_LANG'] ?? '')));
            $this->output->assertActive();
            $lineId = $event['request_id'].':narrator';
            $voiced = $this->speech->synthesize($name, $lineId, $reply, $speech, ['request_id' => $event['request_id'],
                'turn_id' => $event['turn_id'], 'utterance_id' => $lineId, 'generation' => $event['generation']]);
            $this->output->assertActive();
            $this->narrators->recordGenerated($reply);
        } catch (\SYNTHProtocolCancelled $error) { throw $error; }
        catch (\Throwable $error) {
            $this->narrators->failPending();
            $code = in_array($error->getMessage(), ['provider_output_oversized', 'provider_empty', 'speech_unavailable',
                'provider_stream_incomplete', 'provider_stream_malformed'], true) ? $error->getMessage() : 'provider_failed';
            $this->output->fail('narrator_failed', $code);
            return;
        }
        $this->output->emit('narrator_reply', 'narrator', ['narrator' => ['definition_id' => $state['selected'],
            'revision_id' => intval($revision['revision_id']), 'display_name' => $name],
            'parent_state_id' => $stateId, 'text' => $reply, 'speech' => $voiced]);
        $this->output->complete();
    }

    private function receipt(array $payload): void
    {
        [$stored, $stateId] = $this->narrators->recordReceipt($payload, self::RECEIPT_STATES[$payload['outcome']]);
        if ($stored === 'cancelled') throw new NarratorRejected('narrator_reply_stale');
        $this->output->emit('status', 'status', ['code' => 'narrator_receipt_accepted', 'detail' => $stored]);
        $this->emitState($stateId);
        $this->output->complete();
    }

    private function lane(): array
    {
        $lane = $this->narrators->lane();
        if ($lane === null) throw new NarratorRejected('narrator_state_unavailable');
        return $lane;
    }

    private function transcribe(array $media): string
    {
        try { $text = trim(($this->transcribe)($media)); }
        catch (\SYNTHProtocolCancelled $error) { throw $error; }
        catch (\SYNTHSpeechNotRecognized) { throw new NarratorRejected('speech_not_recognized'); }
        catch (\Throwable) { throw new NarratorRejected('stt_failed'); }
        if ($text === '') throw new NarratorRejected('speech_not_recognized');
        return $text;
    }

    // One bounded reply line; over-limit output stops consumption and fails the reply, nothing is truncated.
    private function generate(array $connector, array $messages): string
    {
        $buffer = '';
        $this->llm->stream($connector, $messages, function (string $delta) use (&$buffer): void {
            $this->output->assertActive();
            $buffer .= $delta;
            if (strlen($buffer) > self::MAX_OUTPUT_BYTES) throw new \LengthException('provider_output_oversized');
        });
        $reply = trim(preg_replace('/\s+/u', ' ', $buffer) ?? '');
        if ($reply === '') throw new \RuntimeException('provider_empty');
        if (mb_strlen($reply) > self::MAX_REPLY_CHARS) throw new \LengthException('provider_output_oversized');
        return $reply;
    }

    private function messages(array $revision, string $player, array $scene, array $history, string $text, string $kind, string $language): array
    {
        $name = trim($revision['name']);
        $system = "You are {$name}, a private narrator in Fallout 4 who speaks only to {$player}. Nobody in the world can hear"
            ." you, and nothing you say reaches any character. Reply in your own voice with spoken words only, in at most"
            ." four sentences, without markup, stage directions or invented events.";
        if ($language !== '') $system .= "\nReply in the configured language: ".mb_substr($language, 0, 64).'.';
        foreach (['personality' => 'Personality', 'background' => 'Background', 'style' => 'Speaking style'] as $field => $label) {
            $value = trim(strval($revision[$field] ?? ''));
            if ($value !== '') $system .= "\n{$label}: ".$value;
        }
        $background = $this->narrators->playerBackground();
        if ($background !== '') $system .= "\nAbout {$player}: ".mb_substr($background, 0, 4000);
        if ($scene !== []) $system .= "\nCurrent scene: ".json_encode($scene, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($history as $entry) $messages[] = ['role' => $entry['role'] === 'narrator' ? 'assistant' : 'user',
            'content' => mb_substr(strval($entry['text']), 0, 1000)];
        $messages[] = ['role' => 'user', 'content' => $kind === 'scene'
            ? 'Comment privately on the current scene described above.' : $text];
        return $messages;
    }

    // Only facts the client copied into the claimed snapshot; never a guessed actor or target.
    private static function sceneFacts(?array $context): array
    {
        $world = is_array($context['payload']['world'] ?? null) ? $context['payload']['world'] : [];
        $facts = [];
        foreach (self::SCENE_FACTS as $key) {
            $value = $world[$key] ?? null;
            if (is_bool($value)) $facts[$key] = $value;
            elseif (is_string($value) && trim($value) !== '') $facts[$key] = mb_substr(trim($value), 0, 255);
        }
        return isset($facts['location']) || isset($facts['cell']) ? $facts : [];
    }

    private function emitState(?int $stateId, ?string $cursor = null): void
    {
        $state = $this->narrators->state($stateId);
        $reference = static fn($row) => ['definition_id' => $row['definition_id'],
            'revision_id' => intval($row['revision_id']), 'display_name' => mb_substr(trim($row['name']), 0, 64)];
        $definitions = [];
        foreach ($state['applied'] as $revisionId) $definitions[] = $reference($this->narrators->revision($revisionId));
        [$entries, $nextCursor] = $this->narrators->transcriptPage($state, $cursor);
        $payload = ['state_id' => $state['state_id'], 'definitions' => $definitions,
            'available' => array_map($reference, $this->narrators->library()), 'transcript' => array_map(
            static fn($entry) => ['role' => $entry['role'], 'display_name' => mb_substr(trim($entry['speaker_name']), 0, 64),
                'text' => mb_substr(strval($entry['text']), 0, 4096)],
            $entries)];
        if ($nextCursor !== null) $payload['next_cursor'] = $nextCursor;
        if ($state['selected'] !== null) {
            $revision = $this->narrators->revision($state['applied'][$state['selected']]);
            $payload['selected'] = ['definition_id' => $state['selected'], 'revision_id' => intval($revision['revision_id']),
                'display_name' => mb_substr(trim($revision['name']), 0, 64)];
        }
        $this->output->emit('narrator_state', 'state', $payload);
    }
}
