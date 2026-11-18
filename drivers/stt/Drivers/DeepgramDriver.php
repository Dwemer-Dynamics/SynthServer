<?php

declare(strict_types=1);

namespace Synth\Drivers\Stt\Drivers;

use Synth\Drivers\Stt\Exception\SttException;
use Synth\Drivers\Stt\SttDriverInterface;
use Synth\Drivers\Stt\SttRequest;
use Synth\Drivers\Stt\SttResult;

/** Deepgram pre-recorded audio ("deepgram"). */
final class DeepgramDriver implements SttDriverInterface
{
    public const NAME = 'deepgram';
    private const NOVA3_LANGUAGES = '|multi|en|en-US|en-GB|en-IN|en-NZ|bg|ca|cs|da|da-DK|de|de-CH|es|es-419|el|et|fi|fr|fr-CA|hi|hu|'
        . 'id|it|ja|ko|ko-KR|lv|lt|ms|nl|nl-BE|no|pl|pt|pt-BR|pt-PT|ro|ru|sk|sv|sv-SE|tr|uk|vi';

    /** @param array{model?:string,language?:string,api_key?:string} $config */
    public function __construct(private readonly array $config)
    {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function transcribe(SttRequest $request): SttResult
    {
        $audio = SttHttp::readAudio($request->audioPath);
        $model = (string) ($this->config['model'] ?? 'none');
        $lang = (string) ($this->config['language'] ?? 'en');
        if (str_contains($model, 'nova-3')) {
            if (stripos(self::NOVA3_LANGUAGES, $lang) === false) {
                $lang = 'en';
            }
        } elseif (str_contains($model, 'flux-general')) {
            $lang = 'en';
        }
        // The legacy connector built keyword/keyterm parameters but then replaced the URL; only this URL was ever sent.
        $url = "https://api.deepgram.com/v1/listen?punctuate=true&filler_words=true&utterances=true&language={$lang}&model={$model}";
        [$response] = SttHttp::curlPost($url, $audio, [
            'Authorization: Token ' . (string) ($this->config['api_key'] ?? ''),
            'Content-Type: audio/wav',
        ], 60);
        $parsed = json_decode($response, true);
        $text = is_array($parsed) ? ($parsed['results']['channels'][0]['alternatives'][0]['transcript'] ?? null) : null;
        if (!is_string($text)) {
            throw new SttException('The speech provider answered without a transcript.');
        }

        return new SttResult($text);
    }
}
