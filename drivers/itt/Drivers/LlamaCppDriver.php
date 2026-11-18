<?php

declare(strict_types=1);

namespace Synth\Drivers\Itt\Drivers;

use Synth\Drivers\Itt\Exception\IttConfigurationException;
use Synth\Drivers\Itt\Exception\IttException;
use Synth\Drivers\Itt\IttDriverInterface;
use Synth\Drivers\Itt\IttRequest;

/** A llama.cpp server's /completion endpoint with image_data ("llamacpp"). Needs no model or key. */
final class LlamaCppDriver implements IttDriverInterface
{
    public const NAME = 'llamacpp';

    /** @param array{url?:string,prompt?:string,timeout?:int} $config */
    public function __construct(private readonly array $config)
    {
        if (rtrim(trim((string) ($config['url'] ?? '')), '/') === '') {
            throw new IttConfigurationException('llama.cpp ITT connector URL is empty');
        }
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function describe(IttRequest $request): string
    {
        [$bytes] = IttHttp::imageData($request->imagePath);
        $baseUrl = rtrim(trim((string) $this->config['url']), '/');
        $prompt = IttHttp::prompt((string) ($this->config['prompt'] ?? ''), $request->hints);
        $response = IttHttp::requestJson($baseUrl . '/completion', [
            'prompt' => $prompt . "\nASSISTANT:",
            'n_predict' => 1024,
            'image_data' => [['data' => base64_encode($bytes), 'id' => 1]],
            'ignore_eos' => false,
            'temperature' => 0.0,
        ], [], (int) ($this->config['timeout'] ?? 60));
        $description = IttHttp::extractContent($response);
        if ($description === '') {
            throw new IttException('llama.cpp ITT connector returned an empty description');
        }

        return $description;
    }
}
