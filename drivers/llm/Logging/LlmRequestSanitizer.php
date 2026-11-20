<?php

declare(strict_types=1);

namespace Synth\Drivers\Llm\Logging;

/**
 * Makes a request or reply safe and small enough to store.
 *
 * Redaction removes secrets three ways: values under a secret-looking key (api_key, authorization, token, password and
 * the like, but not counters such as max_tokens), the exact key strings of the connector in use, and key-shaped text
 * (bearer tokens, provider key prefixes, key= query parameters). Bounding cuts every string at MAX_STRING bytes and drops
 * a payload that is still larger than MAX_PAYLOAD bytes in total, and reports that it did so, because a cut request can
 * no longer be replayed faithfully.
 */
final class LlmRequestSanitizer
{
    public const MAX_STRING = 65536;
    public const MAX_PAYLOAD = 1048576;
    public const MAX_RESPONSE = 262144;
    public const MAX_ERROR = 2000;
    private const MAX_DEPTH = 16;
    private const REDACTED = '[redacted]';

    private const SECRET_KEY = '/^(?:.*[_-])?(?:api[_-]?key|apikey|authorization|secret|password|passwd|token|access[_-]?token|refresh[_-]?token|auth[_-]?token|bearer|game[_-]?key|private[_-]?key)(?:[_-].*)?$/i';

    /** @var list<string> exact secret strings to remove wherever they appear */
    private array $secrets;

    /** @param list<string> $secrets exact keys of the connector in use; short or empty values are ignored */
    public function __construct(array $secrets = [])
    {
        $this->secrets = array_values(array_filter(array_unique(array_map('strval', $secrets)), static fn(string $secret): bool => strlen($secret) >= 8));
    }

    /**
     * Redacts and bounds a structured value.
     *
     * @return array{0: mixed, 1: bool} the cleaned value and whether anything was cut
     */
    public function clean(mixed $value): array
    {
        $cut = false;
        $clean = $this->walk($value, 0, $cut);
        $encoded = json_encode($clean, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded !== false && strlen($encoded) > self::MAX_PAYLOAD) {
            return [['_oversized' => true, 'bytes' => strlen($encoded)], true];
        }

        return [$clean, $cut];
    }

    /** @return array{0: string, 1: bool} the cleaned text (cut at $limit bytes) and whether it was cut */
    public function cleanText(string $text, int $limit = self::MAX_RESPONSE): array
    {
        $text = $this->redactString($this->utf8($text));
        if (strlen($text) <= $limit) {
            return [$text, false];
        }

        return [mb_strcut($text, 0, $limit, 'UTF-8') . '…[cut ' . (strlen($text) - $limit) . ' bytes]', true];
    }

    /** Redacts text only (for error messages, which are cut short). */
    public function message(string $text): string
    {
        return $this->cleanText($text, self::MAX_ERROR)[0];
    }

    private function walk(mixed $value, int $depth, bool &$cut): mixed
    {
        if (is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                $cut = true;

                return '[too deep]';
            }
            $out = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match(self::SECRET_KEY, $key) === 1 && !is_array($item)) {
                    $out[$key] = self::REDACTED;
                    continue;
                }
                $out[$key] = $this->walk($item, $depth + 1, $cut);
            }

            return $out;
        }
        if (is_string($value)) {
            $text = $this->redactString($this->utf8($value));
            if (strlen($text) > self::MAX_STRING) {
                $cut = true;

                return mb_strcut($text, 0, self::MAX_STRING, 'UTF-8') . '…[cut ' . (strlen($text) - self::MAX_STRING) . ' bytes]';
            }

            return $text;
        }
        if (is_object($value)) {
            return '[' . get_debug_type($value) . ']';
        }
        if (is_resource($value)) {
            return '[resource]';
        }

        return $value;
    }

    private function redactString(string $text): string
    {
        foreach ($this->secrets as $secret) {
            $text = str_replace($secret, self::REDACTED, $text);
        }
        $text = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]{8,}/i', 'Bearer ' . self::REDACTED, $text) ?? $text;
        $text = preg_replace('/\b(?:sk-[A-Za-z0-9_-]{16,}|AIza[0-9A-Za-z_-]{30,}|gsk_[A-Za-z0-9]{20,}|xai-[A-Za-z0-9]{20,})/', self::REDACTED, $text) ?? $text;
        $text = preg_replace('/([?&](?:key|api_key|apikey|access_token|token)=)[^&\s"\']+/i', '$1' . self::REDACTED, $text) ?? $text;

        return $text;
    }

    private function utf8(string $text): string
    {
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
}
