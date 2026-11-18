<?php

declare(strict_types=1);

namespace Synth\Drivers\Stt\Drivers;

use Synth\Drivers\Stt\Exception\SttConfigurationException;
use Synth\Drivers\Stt\Exception\SttException;
use Synth\Drivers\Stt\SttDriverInterface;
use Synth\Drivers\Stt\SttRequest;
use Synth\Drivers\Stt\SttResult;

/**
 * The local Whisper service ("localwhisper"): one multipart audio field, a JSON answer carrying "text" or "transcription".
 *
 * Every outcome carries a diagnostic array. The speech pipeline relies on it to tell "heard nothing" (stage
 * recognized_empty) from transport, HTTP and decoding failures, so its keys are part of the contract.
 */
final class LocalWhisperDriver implements SttDriverInterface
{
    public const NAME = 'localwhisper';

    /** @param array{url?:string,form_field?:string,timeout?:int} $config */
    public function __construct(private readonly array $config)
    {
        if (trim((string) ($config['url'] ?? '')) === '') {
            throw new SttConfigurationException('LocalWhisper URL is empty.', [
                'driver' => self::NAME, 'stage' => 'config', 'message' => 'LocalWhisper URL is empty.',
            ]);
        }
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function transcribe(SttRequest $request): SttResult
    {
        $url = trim((string) $this->config['url']);
        $formField = trim((string) ($this->config['form_field'] ?? 'file')) ?: 'file';
        $timeout = (int) ($this->config['timeout'] ?? 60);
        $timeout = $timeout > 0 ? $timeout : 60;
        $path = $request->audioPath;

        if (!is_file($path) || !is_readable($path)) {
            throw new SttException('Audio file is missing or unreadable.', [
                'driver' => self::NAME, 'stage' => 'input', 'message' => 'Audio file is missing or unreadable.', 'file_path' => $path,
            ]);
        }
        $audio = @file_get_contents($path);
        if ($audio === false) {
            throw new SttException('Failed to read audio file.', [
                'driver' => self::NAME, 'stage' => 'input', 'message' => 'Failed to read audio file.', 'file_path' => $path,
                'php_error' => error_get_last()['message'] ?? '',
            ]);
        }

        $base = [
            'driver' => self::NAME, 'url' => $url, 'form_field' => $formField, 'file_path' => $path,
            'file_size' => strlen($audio), 'timeout_seconds' => $timeout,
        ];
        try {
            [$response, $status, $headers] = SttHttp::multipart(
                $url,
                [['name' => $formField, 'filename' => basename($path), 'type' => 'audio/wav', 'value' => $audio]],
                [],
                $timeout,
                true
            );
        } catch (SttException $e) {
            $d = $e->diagnostic();
            $statusLine = '';
            foreach ((array) ($d['headers'] ?? []) as $line) {
                if (stripos((string) $line, 'HTTP/') === 0) {
                    $statusLine = (string) $line;
                }
            }
            throw new SttException($e->getMessage(), $base + [
                'http_status_line' => $statusLine, 'http_status_code' => (int) ($d['http_status_code'] ?? 0),
                'stage' => 'transport', 'error_code' => 'stt_transport_failed',
                'message' => 'Failed to get response from LocalWhisper server.',
                'php_error' => error_get_last()['message'] ?? '',
            ], $e);
        }
        $statusLine = '';
        foreach ($headers as $line) {
            if (stripos((string) $line, 'HTTP/') === 0) {
                $statusLine = (string) $line;
            }
        }
        $base += ['http_status_line' => $statusLine, 'http_status_code' => $status];

        if ($status < 200 || $status >= 300) {
            throw new SttException('LocalWhisper returned an unsuccessful HTTP status.', $base + [
                'stage' => 'http', 'error_code' => 'stt_http_error', 'message' => 'LocalWhisper returned an unsuccessful HTTP status.',
            ]);
        }
        $parsed = json_decode($response, true);
        if (!is_array($parsed)) {
            $message = 'Failed to parse JSON response from LocalWhisper.';
            if (preg_match('/^\s*<!DOCTYPE html/i', $response) || preg_match('/<html/i', $response)) {
                $message = 'Endpoint returned HTML instead of JSON. LocalWhisper URL likely points to a web page or wrong route.';
            }
            throw new SttException($message, $base + [
                'stage' => 'decode', 'error_code' => 'stt_invalid_response', 'message' => $message, 'json_error' => json_last_error_msg(),
            ]);
        }
        foreach (['text', 'transcription'] as $key) {
            if (!array_key_exists($key, $parsed)) {
                continue;
            }
            if (!is_string($parsed[$key])) {
                throw new SttException('LocalWhisper transcription field must be a string.', $base + [
                    'stage' => 'response', 'error_code' => 'stt_invalid_response',
                    'message' => 'LocalWhisper transcription field must be a string.',
                ]);
            }
            $text = trim($parsed[$key]);

            return new SttResult($text, $base + [
                'stage' => $text === '' ? 'recognized_empty' : 'success',
                'message' => $text === '' ? 'LocalWhisper accepted the request without a recognized transcript.' : 'LocalWhisper transcription received successfully.',
                'response_key' => $key,
                'response_value_type' => 'string',
            ]);
        }

        throw new SttException('JSON response did not contain a text or transcription field.', $base + [
            'stage' => 'response', 'error_code' => 'stt_invalid_response',
            'message' => 'JSON response did not contain a text or transcription field.',
        ]);
    }
}
