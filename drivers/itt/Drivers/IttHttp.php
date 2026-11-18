<?php

declare(strict_types=1);

namespace Synth\Drivers\Itt\Drivers;

use Synth\Drivers\Itt\Exception\IttException;

/** Image reading, JSON transport and answer extraction shared by the ITT drivers. */
final class IttHttp
{
    /** @return array{0:string,1:string} image bytes and MIME type */
    public static function imageData(string $imagePath): array
    {
        $bytes = @file_get_contents($imagePath);
        if (!is_string($bytes) || $bytes === '') {
            throw new IttException('PipVision could not read the normalized image');
        }
        $info = @getimagesize($imagePath);
        $mime = strtolower(trim((string) ($info['mime'] ?? '')));
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            throw new IttException('PipVision does not support this image format');
        }

        return [$bytes, $mime];
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<string> $headers
     * @return array<string,mixed>
     */
    public static function requestJson(string $url, array $payload, array $headers, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            throw new IttException('PHP cURL is required for PipVision');
        }
        if ($url === '') {
            throw new IttException('ITT connector endpoint URL is empty');
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $raw = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
            $detail = $curlError !== '' ? $curlError : ('HTTP ' . $status);
            if (is_string($raw) && trim($raw) !== '') {
                $detail .= ': ' . substr(trim(strip_tags($raw)), 0, 300);
            }
            throw new IttException('ITT connector request failed: ' . $detail);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new IttException('ITT connector returned invalid JSON');
        }

        return $decoded;
    }

    /** @param array<string,mixed> $response */
    public static function extractContent(array $response): string
    {
        $content = $response['choices'][0]['message']['content'] ?? ($response['content'] ?? '');
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if (is_array($part) && isset($part['text'])) {
                    $parts[] = (string) $part['text'];
                } elseif (is_string($part)) {
                    $parts[] = $part;
                }
            }
            $content = implode("\n", $parts);
        }

        return trim((string) $content);
    }

    /** The prompt, then the game hints under a "Game metadata" heading. */
    public static function prompt(string $base, string $hints): string
    {
        $prompt = trim($base);
        if ($hints !== '') {
            $prompt .= ($prompt !== '' ? "\n\nGame metadata:\n" : '') . $hints;
        }

        return $prompt;
    }
}
