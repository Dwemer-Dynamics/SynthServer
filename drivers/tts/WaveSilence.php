<?php

namespace Synth\Drivers\Tts;

// WaveSilence: detects digital silence in synthesized speech.
//
// A TTS provider can return a structurally valid WAV whose samples are all at the zero level. Playing it
// produces nothing, so callers treat it as "no audio" and deliver the line as text. This class only reads
// bytes: it knows no provider, game state, settings or database.
final class WaveSilence
{
    // True when the bytes are a valid RIFF/WAVE whose sample data is only zero-level samples: 0x80 for
    // unsigned 8-bit PCM, otherwise all-zero bytes (float zero is all-zero bytes). Any other sample, a
    // malformed container or a missing data chunk returns false; malformed media keeps its own rejection.
    public static function isSilent(string $bytes): bool
    {
        $length = strlen($bytes);
        if ($length < 44 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WAVE') {
            return false;
        }
        $end = self::word($bytes, 4) + 8;
        if ($end < 44 || $end > $length) {
            return false;
        }
        $tag = 0;
        $bits = 0;
        $hasFormat = false;
        $sawData = false;
        for ($offset = 12; $offset + 8 <= $end;) {
            $name = substr($bytes, $offset, 4);
            $size = self::word($bytes, $offset + 4);
            $start = $offset + 8;
            if ($size > $end - $start) {
                return false;
            }
            if ($name === 'fmt ' && $size >= 16) {
                $format = unpack('vtag/vchannels/Vrate/Vbytes/vblock/vbits', substr($bytes, $start, 16));
                $tag = $format['tag'];
                $bits = $format['bits'];
                // Extensible carries the real encoding in the first word of its sub-format GUID.
                if ($tag === 0xFFFE && $size >= 40) {
                    $tag = self::word($bytes, $start + 24);
                }
                $hasFormat = true;
            } elseif ($name === 'data') {
                if (!$hasFormat || $size === 0) {
                    return false;
                }
                $sawData = true;
                $zero = $tag === 1 && $bits === 8 ? "\x80" : "\0";
                if (strspn(substr($bytes, $start, $size), $zero) !== $size) {
                    return false;
                }
            }
            $offset = $start + $size + ($size & 1);
        }
        return $sawData;
    }

    // Reads one unsigned little-endian 32-bit word at the offset.
    private static function word(string $bytes, int $offset): int
    {
        return unpack('Vvalue', substr($bytes, $offset, 4))['value'];
    }
}
