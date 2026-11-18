<?php

declare(strict_types=1);

namespace Synth\Drivers\Stt\Drivers;

use Synth\Drivers\Stt\Exception\SttConfigurationException;
use Synth\Drivers\Stt\Exception\SttException;
use Synth\Drivers\Stt\SttDriverInterface;
use Synth\Drivers\Stt\SttRequest;
use Synth\Drivers\Stt\SttResult;

/**
 * POST /v1/audio/transcriptions as the OpenAI Whisper API and the local Parakeet service take it.
 * Used by two drivers: "whisper" (OpenAI) and "parakeet" (same wire format, own endpoint).
 */
class OpenAiCompatibleSttDriver implements SttDriverInterface
{
    /** @param array{url?:string,api_key?:string,language?:string,model?:string,timeout?:int} $config */
    public function __construct(private readonly string $name, private readonly array $config)
    {
        if (trim((string) ($config['url'] ?? '')) === '') {
            throw new SttConfigurationException("STT driver {$name} needs an endpoint URL.");
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function transcribe(SttRequest $request): SttResult
    {
        $audio = SttHttp::readAudio($request->audioPath);
        $parts = [
            ['name' => 'file', 'filename' => basename($request->audioPath), 'type' => 'audio/wav', 'binary' => true, 'value' => $audio],
            ['name' => 'model', 'value' => (string) ($this->config['model'] ?? 'whisper-1')],
            ['name' => 'prompt', 'value' => $request->hint],
            ['name' => 'language', 'value' => (string) ($this->config['language'] ?? 'en')],
        ];
        $key = trim((string) ($this->config['api_key'] ?? ''));
        [$response] = SttHttp::multipart(
            (string) $this->config['url'],
            $parts,
            ['Authorization: Bearer ' . $key],
            (int) ($this->config['timeout'] ?? 60)
        );
        if (trim($response) === '') {
            throw new SttException('The speech provider answered nothing.');
        }
        $parsed = json_decode($response);
        if (!is_object($parsed) || isset($parsed->error)) {
            throw new SttException('The speech provider answered with an error or unusable body.');
        }

        return new SttResult(is_string($parsed->text ?? null) ? $parsed->text : '');
    }
}
