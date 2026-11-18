<?php

declare(strict_types=1);

// Read-only inspection of the stored voice samples (data/voices) and the silent-sample check used by imports.
// Nothing here writes, deletes or contacts a provider.

namespace Synth\Managers\VoiceSample;

use InvalidArgumentException;

final class VoiceSampleStore
{
    public const MAX_SAMPLE_BYTES = 8388608;
    public const MAX_LISTED = 2000;
    public const REFERENCE_TEXT_BYTES = 4096;

    /**
     * Tells whether a value can be a voice id: a file stem under data/voices.
     *
     * Letters, digits, "_", "-" and "." (not leading), up to 128 characters, so it can never climb out of the folder.
     */
    public static function isValidId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $value) === 1 && !str_contains($value, '..');
    }

    /**
     * Reads only the RIFF/WAVE header of a sample.
     *
     * Returns null when the file is missing, linked or not a readable WAV, so callers never trust a stub. Listing
     * passes $capped = true and skips files over MAX_SAMPLE_BYTES; imports pass false, because a long decode is legitimate.
     *
     * @return array{format:int,channels:int,rate:int,bits:int,data_offset:int,data_bytes:int,duration_seconds:float}|null
     */
    public static function wavInfo(string $path, bool $capped = true): ?array
    {
        $size = @filesize($path);
        if (!is_file($path) || is_link($path) || $size === false || $size <= 44 || ($capped && $size > self::MAX_SAMPLE_BYTES)) {
            return null;
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            return null;
        }
        try {
            return self::scanChunks($handle, $size);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Tells whether every sample of the WAV's data chunk is digital silence.
     *
     * All zero bytes (0x80 for 8-bit PCM) count as silent; any nonzero sample, however quiet, is audible. Returns
     * null when the file cannot be read as a WAV, which is neither silent nor audible. The data is streamed in 64 KiB
     * reads with no size cap, so a long import is checked in full.
     */
    public static function isSilentWav(string $path): ?bool
    {
        $info = self::wavInfo($path, false);
        if ($info === null || $info['data_bytes'] < 1 || !in_array($info['format'], [1, 3, 0xFFFE], true)
            || $info['channels'] < 1 || $info['bits'] < 8) {
            return null;
        }
        $silentByte = ($info['format'] === 1 && $info['bits'] === 8) ? "\x80" : "\0";
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle) || fseek($handle, $info['data_offset']) !== 0) {
            return null;
        }
        try {
            $remaining = $info['data_bytes'];
            while ($remaining > 0) {
                $chunk = fread($handle, min(65536, $remaining));
                if (!is_string($chunk) || $chunk === '') {
                    return null;
                }
                if (strspn($chunk, $silentByte) !== strlen($chunk)) {
                    return false;
                }
                $remaining -= strlen($chunk);
            }
            return true;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Import gate for a decoded sample: empty, unreadable or all-zero audio never reaches data/voices.
     *
     * @throws InvalidArgumentException with a message the importer can show as is.
     */
    public static function assertAudible(string $path): void
    {
        $silent = self::isSilentWav($path);
        if ($silent === null) {
            throw new InvalidArgumentException('Voice conversion produced no readable audio');
        }
        if ($silent) {
            throw new InvalidArgumentException('Voice sample is silent: the decoded audio contains no sound');
        }
    }

    /**
     * Returns a transcript fit to store and show, or '' when it is too long, has control characters or is not UTF-8.
     */
    public static function cleanReferenceText(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > self::REFERENCE_TEXT_BYTES
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value) || preg_match('//u', $value) !== 1) {
            return '';
        }
        return trim($value);
    }

    /**
     * Lists the stored samples, sorted by voice id: metadata only, no file paths, provider settings or secrets.
     *
     * $onlyVoice limits the list to one voice id. The SHA-256 hashes every file, so it is added only when $withHash is
     * true; callers ask for it for one voice, never for the whole folder on a page load.
     *
     * @return list<array<string,mixed>>
     */
    public static function listSamples(string $voiceDir, ?string $onlyVoice = null, bool $withHash = false): array
    {
        $names = is_dir($voiceDir) ? @scandir($voiceDir) : false;
        if (!is_array($names)) {
            return [];
        }
        $samples = [];
        foreach ($names as $name) {
            if (count($samples) >= self::MAX_LISTED) {
                break;
            }
            $voice = self::voiceOfFile($name);
            if ($voice === null || ($onlyVoice !== null && $voice !== $onlyVoice)) {
                continue;
            }
            $wav = $voiceDir . DIRECTORY_SEPARATOR . $name;
            if (is_link($wav) || !is_file($wav)) {
                continue;
            }
            $samples[] = self::describe($voice, $wav, $voiceDir . DIRECTORY_SEPARATOR . $voice . '.json', $withHash);
        }
        return $samples;
    }

    /**
     * Maps a directory entry to its voice id, or null for hidden files, other extensions and unsafe names.
     */
    private static function voiceOfFile(string $name): ?string
    {
        if (!str_ends_with($name, '.wav') || $name[0] === '.') {
            return null;
        }
        $voice = substr($name, 0, -4);
        return self::isValidId($voice) ? $voice : null;
    }

    /**
     * Builds the metadata entry for one sample from its WAV header and optional sidecar JSON.
     *
     * @return array<string,mixed>
     */
    private static function describe(string $voice, string $wav, string $sidecar, bool $withHash): array
    {
        $bytes = @filesize($wav);
        $info = self::wavInfo($wav);
        $details = [];
        if (is_file($sidecar) && !is_link($sidecar) && filesize($sidecar) <= 65536) {
            $decoded = json_decode((string) file_get_contents($sidecar), true, 8);
            $details = is_array($decoded) ? $decoded : [];
        }
        $source = is_string($details['source'] ?? null) && strlen($details['source']) <= 512
            && !preg_match('/[\x00-\x1f]/', $details['source']) ? $details['source'] : null;
        $selection = in_array($details['selection'] ?? null, ['curated_catalog', 'automatic_candidate'], true)
            ? $details['selection'] : null;
        $referenceText = self::cleanReferenceText($details['reference_text'] ?? '');

        $entry = [
            'voice_id' => $voice,
            'bytes' => $bytes === false ? 0 : $bytes,
            'duration_seconds' => $info['duration_seconds'] ?? null,
            'sample_rate' => $info['rate'] ?? null,
            'playable' => $info !== null && $info['data_bytes'] > 0,
            'stored_at' => gmdate('c', (int) @filemtime($wav)),
            'source' => $source,
            'selection' => $selection,
            'has_reference_text' => $referenceText !== '',
            'reference_text' => $referenceText,
        ];
        if ($withHash) {
            $sha = $bytes !== false && $bytes <= self::MAX_SAMPLE_BYTES ? @hash_file('sha256', $wav) : false;
            $entry['sha256'] = is_string($sha) ? $sha : null;
        }
        return $entry;
    }

    /**
     * Walks the RIFF chunks of an open file for the format and data chunks.
     *
     * @param resource $handle
     * @return array{format:int,channels:int,rate:int,bits:int,data_offset:int,data_bytes:int,duration_seconds:float}|null
     */
    private static function scanChunks($handle, int $size): ?array
    {
        $head = fread($handle, 12);
        if (!is_string($head) || strlen($head) < 12 || !str_starts_with($head, 'RIFF') || substr($head, 8, 4) !== 'WAVE') {
            return null;
        }
        $format = null;
        $offset = 12;
        // At most 64 chunks: a header scan must not be a way to make the server read a whole file.
        for ($chunks = 0; $chunks < 64 && $offset + 8 <= $size; $chunks++) {
            if (fseek($handle, $offset) !== 0) {
                return null;
            }
            $header = fread($handle, 8);
            if (!is_string($header) || strlen($header) < 8) {
                return null;
            }
            $id = substr($header, 0, 4);
            $length = unpack('V', substr($header, 4, 4))[1];
            if ($id === 'fmt ' && $length >= 16) {
                $body = fread($handle, 16);
                if (!is_string($body) || strlen($body) < 16) {
                    return null;
                }
                $format = unpack('vtag/vchannels/Vrate/Vbyterate/valign/vbits', $body);
            } elseif ($id === 'data') {
                return $format === null ? null : self::dataInfo($format, $offset + 8, $length, $size);
            }
            $offset += 8 + $length + ($length & 1);
        }
        return null;
    }

    /**
     * Describes the data chunk. A streamed WAV may leave its length at 0 or 0xFFFFFFFF: the data then runs to the end.
     *
     * @param array<string,int> $format
     * @return array{format:int,channels:int,rate:int,bits:int,data_offset:int,data_bytes:int,duration_seconds:float}
     */
    private static function dataInfo(array $format, int $dataOffset, int $length, int $size): array
    {
        $available = $size - $dataOffset;
        $dataBytes = ($length === 0 || $length > $available) ? $available : $length;
        $bytesPerSecond = max(1, $format['rate'] * $format['channels'] * intdiv(max(8, $format['bits']), 8));
        return [
            'format' => $format['tag'], 'channels' => $format['channels'], 'rate' => $format['rate'],
            'bits' => $format['bits'], 'data_offset' => $dataOffset, 'data_bytes' => $dataBytes,
            'duration_seconds' => round($dataBytes / $bytesPerSecond, 2),
        ];
    }
}
