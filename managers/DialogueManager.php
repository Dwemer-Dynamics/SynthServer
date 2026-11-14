<?php

namespace Synth\Managers;

use Synth\Core\AdmittedTurn;
use Synth\Core\CharacterKey;
use Synth\Core\LlmDriver;
use Synth\Core\TurnOutput;
use Synth\Core\TurnRejected;
use Synth\Core\UtteranceRepository;

require_once dirname(__DIR__).'/core/contracts/dialogue.php';
require_once dirname(__DIR__).'/core/entities/dialogue.php';
require_once dirname(__DIR__).'/core/dbmanager/repositories/UtteranceRepository.php';
require_once __DIR__.'/SessionManager.php';
require_once __DIR__.'/CharacterManager.php';
require_once __DIR__.'/ContextManager.php';
require_once __DIR__.'/PromptManager.php';
require_once __DIR__.'/SpeechManager.php';
require_once __DIR__.'/WorldKnowledgeManager.php';

// One explicit conversation turn: sequential admission, context, prompt, provider stream, utterance
// records, speech and completion. Once admitted there is no legacy fallback; failures are terminal.
// Delivery receipts for the emitted lines are recorded here too.
final class DialogueManager
{
    public const MAX_LINES = 12;
    public const MAX_OUTPUT_BYTES = 16384;
    public const MAX_LINE_CHARS = 1000;
    // Specific terminal details; anything else is reported as provider_failed.
    private const FAILURES = ['provider_unsupported', 'provider_stream_incomplete', 'provider_stream_malformed',
        'provider_output_oversized', 'provider_output_too_many_lines', 'provider_line_oversized', 'speech_unavailable'];
    private const RECEIPT_STATES = ['audio_completed' => 'spoken', 'text_presented' => 'presented',
        'interrupted' => 'interrupted', 'failed' => 'failed'];

    public function __construct(private SessionManager $sessions, private CharacterManager $characters,
        private ContextManager $context, private PromptManager $prompts, private LlmDriver $llm,
        private SpeechManager $speech, private UtteranceRepository $utterances, private TurnOutput $output,
        private ?WorldKnowledgeManager $knowledge = null) {}

    public function converse(array $event): void
    {
        try { $turn = $this->admit($event); }
        catch (TurnRejected $rejected) { $this->output->reject($rejected->getMessage()); return; }
        $this->run($turn);
    }

    // Delivery receipt for one emitted line; returns the stored state. Text and speaker come from the server
    // record, never the request. Refusals throw a DomainException naming receipt_invalid, receipt_owner_mismatch
    // or receipt_conflict.
    public function receipt(array $event): string
    {
        $payload = $event['payload'];
        $state = self::RECEIPT_STATES[$payload['outcome'] ?? ''] ?? null;
        $character = is_array($payload['character'] ?? null) ? $payload['character'] : [];
        $characterId = CharacterKey::of($character);
        if ($state === null || $characterId === null) throw new \DomainException('receipt_invalid');
        $receipt = $this->sessions->receiptOwner($event);
        $speaker = strval($receipt->context['target']['display_name'] ?? $character['display_name'] ?? '');
        $player = $receipt->playerName();
        $heard = $this->sessions->heard($state)
            ? static fn(string $text): string => $speaker.': '.$text.' (talking to '.$player.')' : null;
        return $this->utterances->recordReceipt($receipt, $character, $characterId, $state, $heard);
    }

    // The first unmet requirement is the reported reason.
    public function admit(array $event): AdmittedTurn
    {
        $request = $this->sessions->admit($event);
        $character = $this->characters->resolve($request);
        if (!$this->llm->supports($character->connector)) throw new TurnRejected('llm_connector_unsupported');
        $speech = $this->speech->settings($character);
        if ($speech === null) throw new TurnRejected('tts_profile_unsupported');
        return new AdmittedTurn($request, $character, $speech);
    }

    public function run(AdmittedTurn $turn): void
    {
        $request = $turn->request;
        $character = $turn->character;
        // Read prior history before this turn's own input row is inserted.
        try {
            $situation = $this->context->situation($request);
            $history = $this->context->history($request, $character);
            // Deterministic lore selection; it degrades to no articles and never calls a provider.
            $knowledge = $this->knowledge?->context($request, $character, $history) ?? '';
            $messages = $this->prompts->messages($character, $situation, $history, $request->text(),
                $this->context->skills($request, $character), $knowledge);
        } catch (\SYNTHProtocolCancelled $error) { throw $error; }
        catch (\Throwable) { $this->output->fail('provider_failed', 'history_unavailable'); return; }
        $this->utterances->recordInput($request, $request->playerName().': '.$request->text()
            .' (Talking to '.(\synth_control_everyone_input($request->event) ? 'everyone' : $character->displayName()).')');
        $buffer = ''; $count = 0; $total = 0;
        // Over-limit output stops provider consumption and ends the turn as an error; nothing is truncated.
        $emit = function (string $text) use ($turn, &$count): void {
            $this->output->assertActive();
            if ($count >= self::MAX_LINES) throw new \LengthException('provider_output_too_many_lines');
            if (mb_strlen($text) > self::MAX_LINE_CHARS) throw new \LengthException('provider_line_oversized');
            $suffix = $this->output->allocateLine();
            $lineId = $turn->request->lineId($suffix);
            $this->utterances->recordLine($turn->request, $turn->character, $lineId, $text);
            $speech = $this->speech->speak($turn->character, $turn->speech, $lineId, $text);
            $this->output->assertActive();
            $this->output->dialogue($suffix, $lineId, ['speaker' => $turn->character->identity, 'native_turn' => true,
                'text' => $text, 'speech' => $speech]);
            $count++;
        };
        try {
            $this->llm->stream($character->connector, $messages, function (string $delta) use (&$buffer, &$total, $emit): void {
                $this->output->assertActive();
                $total += strlen($delta);
                if ($total > self::MAX_OUTPUT_BYTES) throw new \LengthException('provider_output_oversized');
                $buffer .= $delta;
                foreach (self::takeSentences($buffer, false) as $line) $emit($line);
                if (mb_strlen($buffer) > self::MAX_LINE_CHARS) throw new \LengthException('provider_line_oversized');
            });
            $this->output->assertActive();
            foreach (self::takeSentences($buffer, true) as $line) $emit($line);
        } catch (\SYNTHProtocolCancelled $error) { throw $error; }
        catch (\Throwable $error) {
            $this->output->fail('provider_failed', in_array($error->getMessage(), self::FAILURES, true)
                ? $error->getMessage() : 'provider_failed');
            return;
        }
        if ($count === 0) { $this->output->fail('provider_empty', 'provider_empty'); return; }
        // Own completion: no legacy relationship/inline side effects run for this route.
        $this->output->assertActive();
        $this->output->complete();
    }

    // Complete sentences become lines; a trailing fragment is flushed at stream end.
    public static function takeSentences(string &$buffer, bool $final): array
    {
        $lines = [];
        while (preg_match('/^(.+?[.!?…]+["\')\]]?)(\s+|$)/su', $buffer, $match) === 1 && ($final || $match[2] !== '')) {
            $lines[] = trim($match[1]); $buffer = substr($buffer, strlen($match[0]));
        }
        if ($final && trim($buffer) !== '') { $lines[] = trim($buffer); $buffer = ''; }
        return array_values(array_filter($lines, static fn($line) => $line !== ''));
    }
}
