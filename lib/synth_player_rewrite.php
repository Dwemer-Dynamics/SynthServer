<?php

use Synth\Core\CharacterKey;
use Synth\Core\LlmDriver;
use Synth\Core\PlacedCharacter;
use Synth\Core\TurnRequest;

require_once dirname(__DIR__).'/core/contracts/dialogue.php';
require_once dirname(__DIR__).'/core/entities/dialogue.php';

// Explicitly selected Auto Chat: rewrites the player's typed or transcribed intent into one spoken player line
// for the request's exact target. Pure apart from the one provider stream; it reads no globals or settings,
// performs no ambient history lookup (only the explicitly supplied history is read) and publishes nothing. It returns only the accepted line: the original request stays the canonical,
// unmodified owner/replay record, and the caller places the line into its derived pipeline payload and owns
// claims, replay and duplicate handling. Failures throw; there is no legacy or subprocess fallback.
final class SynthPlayerRewrite
{
    // Intent input bounds.
    public const MAX_INPUT_CHARS = 4096;
    public const MAX_INPUT_BYTES = 16384;
    // Accepted output bound: lib/response.php publishes payload.text as substr($text, 0, 4096) bytes, so a longer
    // line would be silently cut there while the caller kept the full text. Longer output is rejected, never shortened.
    public const MAX_OUTPUT_BYTES = 4096;
    public const MAX_HISTORY = 64;
    public const MAX_PROMPT_BYTES = 65536;

    /** @param Closure():void $assertActive owner check; throws when the request is stale or cancelled */
    public function __construct(private LlmDriver $llm, private Closure $assertActive) {}

    /**
     * @param TurnRequest $request original scoped input_text/input_audio request; read only, never altered
     * @param string $intent caller-validated intent: the typed text or the server transcript of the audio
     * @param array $connector stored player connector row; unsupported rows fail closed
     * @param array $player caller-scoped player profile snapshot: optional bio and speech_style strings
     * @param array $history this player/target pair, oldest first: user = player, assistant = target
     * @return string the accepted spoken line only
     */
    public function rewrite(TurnRequest $request, PlacedCharacter $target, string $intent, array $connector, array $player, array $history): string
    {
        $assertActive = $this->assertActive;
        $assertActive();
        $playerName = $request->context['player']['display_name'] ?? null;
        if (!in_array($request->event['type'] ?? null, ['input_text', 'input_audio'], true)
            || !self::valid($intent, self::MAX_INPUT_BYTES) || mb_strlen($intent) > self::MAX_INPUT_CHARS
            || !self::valid($playerName, 255) || !self::valid($target->identity['display_name'] ?? null, 255)) {
            throw new DomainException('rewrite_input_invalid');
        }
        // The placed target must be exactly the one this request's context addresses, never a name or time lookup:
        // the stored key, the key derived from each side and the current runtime binding (form id and plugin
        // case-normalized, playthrough exact) must all agree, and the context's own label is the one prompted.
        $addressed = is_array($request->context['target'] ?? null) ? $request->context['target'] : [];
        $placed = $target->identity;
        $key = CharacterKey::of($addressed);
        if ($key === null || CharacterKey::of($placed) !== $key || $target->key !== $key
            || CharacterKey::runtimeFormId($addressed['form_id']) !== CharacterKey::runtimeFormId($placed['form_id'])
            || !is_string($addressed['origin_plugin']) || !is_string($placed['origin_plugin'])
            || strtolower($addressed['origin_plugin']) !== strtolower($placed['origin_plugin'])
            || $addressed['playthrough_id'] !== $placed['playthrough_id']
            || ($addressed['display_name'] ?? null) !== $placed['display_name']) {
            throw new DomainException('rewrite_target_mismatch');
        }
        if (!$this->llm->supports($connector)) throw new DomainException('provider_unsupported');
        $playerName = trim($playerName);
        $messages = self::messages($playerName, trim($addressed['display_name']), trim($intent), $player, $history);

        // Final admission: nothing reaches the provider once cancellation or staleness has been observed.
        $assertActive();
        $text = '';
        $this->llm->stream($connector, $messages, static function (string $delta) use (&$text, $assertActive): void {
            $assertActive();
            if (strlen($text) + strlen($delta) > self::MAX_OUTPUT_BYTES) throw new LengthException('rewrite_output_oversized');
            $text .= $delta;
        });
        // A normal return from the driver is provider completion; OpenAiCompatibleDriver throws on truncation.
        $text = self::accept($text, $playerName);
        $assertActive();
        return $text;
    }

    // Spoken-only variant of the player_rewrite.php wording; no prompt-table lookup or PROMPT_HEAD global.
    // Malformed or oversized input throws; only well-formed bio/style are clipped to their prompt share.
    public static function messages(string $playerName, string $targetName, string $intent, array $player, array $history): array
    {
        $bio = $player['bio'] ?? '';
        $style = $player['speech_style'] ?? '';
        if (!self::valid($bio, self::MAX_PROMPT_BYTES, true) || !self::valid($style, self::MAX_PROMPT_BYTES, true)
            || !array_is_list($history) || count($history) > self::MAX_HISTORY) {
            throw new DomainException('rewrite_input_invalid');
        }
        $system = "You are roleplaying as {$playerName} in Fallout 4. You are currently speaking to {$targetName}.";
        $bio = trim($bio);
        $style = trim($style);
        if ($bio !== '' || $style !== '') {
            $system .= "\n\n# Character Context";
            if ($bio !== '') $system .= "\nPlayer Backstory: ".mb_substr(strtr($bio, ['#PLAYER_NAME#' => $playerName]), 0, 4000);
            if ($style !== '') $system .= "\nPlayer Speech Style: ".mb_substr($style, 0, 1000);
        }
        $transcript = '';
        foreach ($history as $message) {
            if (!is_array($message) || !in_array($message['role'] ?? null, ['user', 'assistant'], true)
                || !self::valid($message['content'] ?? null, self::MAX_PROMPT_BYTES)) {
                throw new DomainException('rewrite_input_invalid');
            }
            $transcript .= ($message['role'] === 'user' ? $playerName : $targetName).': '.trim($message['content'])."\n";
        }
        $messages = [['role' => 'system', 'content' => $system]];
        if ($transcript !== '') $messages[] = ['role' => 'user', 'content' => "# HISTORIC DIALOGUE IN CHRONOLOGICAL ORDER\n".$transcript];
        $messages[] = ['role' => 'user', 'content' => "Rewrite dialogue for {$playerName}, using this text as source \"{$playerName}:{$intent}\". "
            ."Use comments between brackets only as guidance for tone, target, length, and verbosity. Do not repeat bracketed "
            ."comments, stage directions, narration, asterisked narration, or speaker names in the output."];
        $messages[] = ['role' => 'user', 'content' => 'Output only the final spoken dialogue line. No narration. No stage directions. '
            .'No asterisked narration. No speaker names. No bracketed comments.'];
        if (array_sum(array_map(static fn(array $m): int => strlen($m['content']), $messages)) > self::MAX_PROMPT_BYTES) {
            throw new LengthException('rewrite_input_oversized');
        }
        return $messages;
    }

    // One bounded spoken line, or a throw. Only an exact leading player-name prefix is removed; markup that could
    // re-enter the ** rewrite path, read as narration/instructions, or span lines is rejected, not repaired.
    public static function accept(string $text, string $playerName): string
    {
        if (!mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) throw new UnexpectedValueException('rewrite_output_malformed');
        // Bounding the raw completion bounds the accepted line too: trimming only removes bytes.
        if (strlen($text) > self::MAX_OUTPUT_BYTES) throw new LengthException('rewrite_output_oversized');
        $text = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $text);
        $prefix = $playerName.':';
        if (str_starts_with($text, $prefix)) $text = preg_replace('/^[\s\p{Z}]+/u', '', substr($text, strlen($prefix)));
        if ($text === '') throw new UnexpectedValueException('rewrite_output_empty');
        if (str_contains($text, '**') || preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $text) === 1
            || preg_match('/^[*#\[({<]/u', $text) === 1 || str_starts_with($text, $prefix)) {
            throw new UnexpectedValueException('rewrite_output_unsafe');
        }
        return $text;
    }

    // A bounded, NUL-free UTF-8 string; non-blank (Unicode whitespace counts as blank) unless $blank allows it.
    private static function valid(mixed $value, int $maxBytes, bool $blank = false): bool
    {
        return is_string($value) && strlen($value) <= $maxBytes && !str_contains($value, "\0")
            && mb_check_encoding($value, 'UTF-8') && ($blank || preg_match('/[^\s\p{Z}]/u', $value) === 1);
    }
}
