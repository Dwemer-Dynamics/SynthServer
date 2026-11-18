<?php

declare(strict_types=1);

namespace Synth\Drivers\Stt\Drivers;

use Synth\Drivers\Stt\Exception\SttConfigurationException;
use Synth\Drivers\Stt\Exception\SttException;
use Synth\Drivers\Stt\SttDriverInterface;
use Synth\Drivers\Stt\SttRequest;
use Synth\Drivers\Stt\SttResult;

/** Inworld speech-to-text ("inworld"). Needs an API key. */
final class InworldDriver implements SttDriverInterface
{
    public const NAME = 'inworld';
    public const URL = 'https://api.inworld.ai/stt/v1/transcribe';
    public const DEFAULT_MODEL = 'groq/whisper-large-v3';

    /** @param array{api_key?:string,model?:string,language?:string} $config */
    public function __construct(private readonly array $config)
    {
        if (trim((string) ($config['api_key'] ?? '')) === '') {
            throw new SttConfigurationException("No API key found. Set STT INWORLD API_KEY or API badge 'Inworld'.");
        }
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function transcribe(SttRequest $request): SttResult
    {
        $audio = SttHttp::readAudio($request->audioPath);
        $model = trim((string) ($this->config['model'] ?? '')) ?: self::DEFAULT_MODEL;
        $language = trim((string) ($this->config['language'] ?? 'en-US'));
        $transcribe = ['modelId' => $model, 'audioEncoding' => 'AUTO_DETECT', 'sampleRateHertz' => 16000, 'numberOfChannels' => 1];
        if ($language !== '') {
            $transcribe['language'] = $language;
        }
        $body = json_encode(['transcribeConfig' => $transcribe, 'audioData' => ['content' => base64_encode($audio)]]);
        [$response, $status] = SttHttp::curlPost(self::URL, (string) $body, [
            'Authorization: Basic ' . trim((string) $this->config['api_key']),
            'Content-Type: application/json',
            'Accept: application/json',
        ], 60, 10);
        $parsed = json_decode($response, true);
        if ($status !== 200) {
            $message = is_array($parsed) ? trim((string) ($parsed['message'] ?? '')) : '';
            throw new SttException("HTTP {$status}" . ($message !== '' ? " - {$message}" : ''));
        }
        if (!is_array($parsed)) {
            throw new SttException('Invalid JSON response');
        }

        return new SttResult(trim((string) ($parsed['transcription']['transcript'] ?? '')));
    }
}
