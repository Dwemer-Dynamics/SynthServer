<?php

namespace Synth\Managers;

use Synth\Core\AdmittedTurn;
use Synth\Core\LlmDriver;
use Synth\Core\TurnOutput;
use Synth\Core\TurnRejected;
use Synth\Core\UtteranceRepository;
use Synth\Managers\ExtensionManager\ExtensionManager;
use Synth\Managers\ExtensionManager\Hooks;

require_once dirname(__DIR__).'/core/contracts/dialogue.php';
require_once dirname(__DIR__).'/core/entities/dialogue.php';
require_once dirname(__DIR__).'/core/dbmanager/repositories/UtteranceRepository.php';
require_once dirname(__DIR__).'/lib/logger.php';
require_once __DIR__.'/ExtensionManager/ExtensionManager.php';

// One explicit conversation turn: sequential admission, context, prompt, provider stream, utterance
// records, speech and completion. Once admitted there is no legacy fallback; failures are terminal.
// Delivery receipts for the emitted lines are recorded by ReceiptManager.
final class DialogueManager
{
    public const MAX_LINES = 12;
    public const MAX_OUTPUT_BYTES = 16384;
    public const MAX_LINE_CHARS = 1000;
    // Specific terminal details; anything else is reported as provider_failed.
    private const FAILURES = ['provider_unsupported', 'provider_stream_incomplete', 'provider_stream_malformed',
        'provider_output_oversized', 'provider_output_too_many_lines', 'provider_line_oversized'];

    public function __construct(private SessionManager $sessions, private CharacterManager $characters,
        private ContextManager $context, private PromptManager $prompts, private LlmDriver $llm,
        private SpeechManager $speech, private UtteranceRepository $utterances, private TurnOutput $output,
        private ?WorldKnowledgeManager $knowledge = null) {}

    public function converse(array $event): void
    {
        try { $turn = $this->admit($event); }
        catch (TurnRejected $rejected) {
            $reason = $rejected->getMessage();
            // One line per rejected request: admission runs once and a replay returns the stored response.
            \Logger::warn('[native-turn] Conversation turn rejected: ' . $reason);
            $this->output->reject($reason);
            return;
        }
        $this->run($turn);
    }

    // The first unmet requirement is the reported reason.
    public function admit(array $event): AdmittedTurn
    {
        $request = $this->sessions->admit($event);
        $character = $this->characters->resolve($request);
        if (!$this->llm->supports($character->connector)) throw new TurnRejected('llm_connector_unsupported');
        // Voice is optional: a profile without a portable TTS connector is admitted and answers as text.
        // Extension filter (API 1.1 dialogue.request.received): may rewrite the player's text; never throws.
        $received = ExtensionManager::filterHook(Hooks::DIALOGUE_REQUEST_RECEIVED, ['request_id' => strval($request->event['request_id'] ?? ''),
            'character_key' => $character->key, 'npc_name' => $character->displayName(), 'player_name' => $request->playerName(),
            'text' => $request->text()]);
        if ($received['text'] !== $request->text()) {
            $changed = $request->event;
            $changed['payload']['text'] = $received['text'];
            $request = new \Synth\Core\TurnRequest($changed, $request->context);
        }
        return new AdmittedTurn($request, $character, $this->speech->settings($character));
    }

    public function run(AdmittedTurn $turn): void
    {
        $request = $turn->request;
        $character = $turn->character;
        // Read prior history before this turn's own input row is inserted.
        try {
            $situation = $this->context->situation($request, $character);
            $history = $this->context->history($request, $character);
            // One locked quest read serves both the prompt block and World Knowledge participant access.
            $quest = $this->context->questKnowledge($request, $character);
            // Deterministic lore selection; it degrades to no articles and never calls a provider.
            $knowledge = $this->knowledge?->context($request, $character, $history, $quest) ?? '';
            // Saved read only; an unavailable or disabled diary is simply absent.
            $latestDiary = $this->context->latestDiary($request, $character);
            $messages = $this->prompts->messages($character, $situation, $history, $request->text(),
                $this->context->skills($request, $character), $knowledge,
                $quest['reports'] || $quest['participant_background'] ? \synthQuestKnowledgePrompt($quest) : '', $latestDiary);
            // Extension hook (no-op without enabled extensions; never throws).
            $messages = ExtensionManager::dialoguePrompt($messages, ['character_key' => $character->key,
                'npc_name' => $character->displayName(), 'player_name' => $request->playerName(), 'world' => $situation['world'] ?? []]);
        } catch (\SYNTHProtocolCancelled $error) { throw $error; }
        catch (\Throwable) { $this->output->fail('provider_failed', 'history_unavailable'); return; }
        $this->utterances->recordInput($request, $request->playerName().': '.$request->text()
            .' (Talking to '.(\synth_control_everyone_input($request->event) ? 'everyone' : $character->displayName()).')');
        $buffer = ''; $count = 0; $total = 0; $voiceNoted = false; $spoken = [];
        if ($turn->speech === null) {
            \Logger::info('[native-turn] The speaking profile has no TTS connector; the reply is delivered as text only.');
            $voiceNoted = true;
        }
        // Over-limit output stops provider consumption and ends the turn as an error; nothing is truncated.
        $emit = function (string $text) use ($turn, &$count, &$voiceNoted, &$spoken): void {
            $this->output->assertActive();
            // Extension filter (API 1.1 dialogue.reply.parsed): one parsed line; an empty text drops it. Never throws.
            $parsed = ExtensionManager::filterHook(Hooks::DIALOGUE_REPLY_PARSED, ['request_id' => strval($turn->request->event['request_id'] ?? ''),
                'character_key' => $turn->character->key, 'npc_name' => $turn->character->displayName(), 'line_index' => $count, 'text' => $text]);
            $text = trim($parsed['text']);
            if ($text === '') return;
            if ($count >= self::MAX_LINES) throw new \LengthException('provider_output_too_many_lines');
            if (mb_strlen($text) > self::MAX_LINE_CHARS) throw new \LengthException('provider_line_oversized');
            $suffix = $this->output->allocateLine();
            $lineId = $turn->request->lineId($suffix);
            $this->utterances->recordLine($turn->request, $turn->character, $lineId, $text);
            // A line whose speech cannot be queued is still delivered: no `speech` block means text only.
            $speech = $this->speech->speak($turn->character, $turn->speech, $lineId, $text);
            if ($speech === null && !$voiceNoted) {
                \Logger::warn('[native-turn] Speech could not be queued (' . ($this->speech->lastFailure() ?? 'unknown') . '); the reply is delivered as text only.');
                $voiceNoted = true;
            }
            $this->output->assertActive();
            $payload = ['speaker' => $turn->character->identity, 'native_turn' => true, 'text' => $text];
            if ($speech !== null) $payload['speech'] = $speech;
            $this->output->dialogue($suffix, $lineId, $payload);
            $count++;
            $spoken[] = $text;
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
        // Extension event after the reply reached the client (inline now; deferred once a sink is installed).
        ExtensionManager::publish(Hooks::DIALOGUE_REPLY_COMPLETED, ['request_id' => strval($request->event['request_id'] ?? ''),
            'character_key' => $character->key, 'npc_name' => $character->displayName(), 'player_name' => $request->playerName(),
            'lines' => $spoken, 'world' => $situation['world'] ?? []]);
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
