<?php

declare(strict_types=1);

namespace Synth\Drivers\Itt\Drivers;

use Synth\Drivers\Itt\Exception\IttConfigurationException;
use Synth\Drivers\Itt\Exception\IttException;
use Synth\Drivers\Itt\IttDriverInterface;
use Synth\Drivers\Itt\IttRequest;

/** Chat-completions vision call: the image as a data URL beside the prompt. Base of the OpenAI-style ITT drivers. */
class OpenAiCompatibleIttDriver implements IttDriverInterface
{
    protected const EXTRA_HEADERS = [];

    /** @param array{url?:string,model?:string,api_key?:string,prompt?:string,detail?:string,max_tokens?:int|string,timeout?:int} $config */
    public function __construct(private readonly string $name, private readonly array $config)
    {
        if (trim((string) ($config['url'] ?? '')) === '' || trim((string) ($config['model'] ?? '')) === '') {
            throw new IttConfigurationException('ITT connector requires both an endpoint URL and model');
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function describe(IttRequest $request): string
    {
        [$bytes, $mime] = IttHttp::imageData($request->imagePath);
        $imageUrl = ['url' => 'data:' . $mime . ';base64,' . base64_encode($bytes)];
        $detail = strtolower(trim((string) ($this->config['detail'] ?? 'low')));
        if (in_array($detail, ['low', 'high'], true)) {
            $imageUrl['detail'] = $detail;
        }
        $headers = [];
        $apiKey = trim((string) ($this->config['api_key'] ?? ''));
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }
        array_push($headers, ...static::EXTRA_HEADERS);

        $response = IttHttp::requestJson(trim((string) $this->config['url']), [
            'model' => trim((string) $this->config['model']),
            'temperature' => 0.0,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => IttHttp::prompt((string) ($this->config['prompt'] ?? ''), $request->hints)],
                    ['type' => 'image_url', 'image_url' => $imageUrl],
                ],
            ]],
            'max_tokens' => max(64, min((int) ($this->config['max_tokens'] ?? 1024), 4096)),
        ], $headers, (int) ($this->config['timeout'] ?? 60));

        $description = IttHttp::extractContent($response);
        if ($description === '') {
            throw new IttException('ITT connector returned an empty description');
        }

        return $description;
    }
}
