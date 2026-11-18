<?php

declare(strict_types=1);

namespace Synth\Drivers\Stt\Drivers;

use Synth\Drivers\Stt\Exception\SttException;
use Synth\Drivers\Stt\SttDriverInterface;
use Synth\Drivers\Stt\SttRequest;
use Synth\Drivers\Stt\SttResult;

/** Azure short-audio REST recognition ("azure"). */
final class AzureDriver implements SttDriverInterface
{
    public const NAME = 'azure';

    /** @param array{region?:string,language?:string,api_key?:string} $config */
    public function __construct(private readonly array $config)
    {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function transcribe(SttRequest $request): SttResult
    {
        $region = trim((string) ($this->config['region'] ?? '')) ?: 'eastus';
        $lang = trim((string) ($this->config['language'] ?? '')) ?: 'en-US';
        $url = "https://$region.stt.speech.microsoft.com/speech/recognition/conversation/cognitiveservices/v1?language=$lang";
        $audio = SttHttp::readAudio($request->audioPath);
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", ['Content-Type: audio/wav', 'Ocp-Apim-Subscription-Key: ' . (string) ($this->config['api_key'] ?? '')]),
            'content' => $audio,
        ]]);
        $headers = [];
        $response = @synth_speech_fetch($url, $context, $headers);
        if ($response === false) {
            throw new SttException('The speech provider could not be reached.');
        }
        $parsed = json_decode($response, true);

        return new SttResult(is_array($parsed) && is_string($parsed['DisplayText'] ?? null) ? $parsed['DisplayText'] : '');
    }
}
