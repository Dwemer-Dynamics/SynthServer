<?php

declare(strict_types=1);

namespace Synth\Drivers\Stt\Drivers;

use Synth\Drivers\Stt\Exception\SttConfigurationException;
use Synth\Drivers\Stt\Exception\SttException;
use Synth\Drivers\Stt\SttDriverInterface;
use Synth\Drivers\Stt\SttRequest;
use Synth\Drivers\Stt\SttResult;

/**
 * Gemini generateContent with audio ("gemini"): one call returns the transcript and the speaker's vocal tone.
 *
 * The tone is returned in the result diagnostic ('tone'); the caller decides whether to cache it. The driver
 * writes no files.
 */
final class GeminiDriver implements SttDriverInterface
{
    public const NAME = 'gemini';
    public const DEFAULT_MODEL = 'gemini-2.5-flash';

    /** @param array{api_key?:string,model?:string,language?:string} $config */
    public function __construct(private readonly array $config)
    {
        if (trim((string) ($config['api_key'] ?? '')) === '') {
            throw new SttConfigurationException("No API key found. Set in STT config or add API badge with label 'gemini' or 'google'.");
        }
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function transcribe(SttRequest $request): SttResult
    {
        $audio = SttHttp::readAudio($request->audioPath);
        $apiKey = trim((string) $this->config['api_key']);
        $model = !empty($this->config['model']) ? (string) $this->config['model'] : self::DEFAULT_MODEL;
        $lang = !empty($this->config['language']) ? (string) $this->config['language'] : 'en';
        $hints = $request->keywords === []
            ? ''
            : "\n\nThe following proper nouns may appear in the audio (use these for spelling): " . implode(', ', $request->keywords);

        $prompt = "You are a speech-to-text system for Fallout 4 gameplay. Transcribe this audio accurately and detect the speaker's emotional tone from their voice.\n\nLanguage: {$lang}{$hints}\n\nReturn ONLY valid JSON with exactly these two fields:\n{\"transcript\": \"the transcribed text\", \"tone\": \"one or two words describing the emotional tone of the speaker's voice\"}\n\nFor tone, use descriptive words like: calm, angry, excited, nervous, sad, happy, sarcastic, scared, commanding, playful, seductive, desperate, bored, suspicious, threatening, gentle, annoyed, confused, etc.\n\nIf the audio is silent or unintelligible, return: {\"transcript\": \"\", \"tone\": \"neutral\"}";
        $payload = [
            'contents' => [['parts' => [
                ['text' => $prompt],
                ['inline_data' => ['mime_type' => 'audio/wav', 'data' => base64_encode($audio)]],
            ]]],
            'generationConfig' => ['temperature' => 0.1, 'maxOutputTokens' => 1024, 'responseMimeType' => 'application/json'],
        ];
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        [$response, $status] = SttHttp::curlPost($url, (string) json_encode($payload), ['Content-Type: application/json'], 30);
        if ($status !== 200) {
            throw new SttException("API returned HTTP {$status} for model={$model}: " . substr($response, 0, 500));
        }
        $parsed = json_decode($response, true);
        $generated = is_array($parsed) ? ($parsed['candidates'][0]['content']['parts'][0]['text'] ?? null) : null;
        if (!is_string($generated) || $generated === '') {
            throw new SttException('No generated text in response');
        }
        $result = json_decode($generated, true);
        if (!is_array($result) && preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $generated, $m)) {
            $result = json_decode($m[1], true);
        }
        if (!is_array($result)) {
            // The model ignored the JSON instruction; the whole answer is treated as the transcript.
            return new SttResult(trim($generated));
        }

        return new SttResult(trim((string) ($result['transcript'] ?? '')), ['tone' => trim((string) ($result['tone'] ?? 'neutral'))]);
    }
}
