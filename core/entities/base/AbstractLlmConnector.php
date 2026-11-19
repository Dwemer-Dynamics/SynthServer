<?php

// The mapped row of public.core_llm_connector: one LLM connector (a model endpoint). Read-only mapping: reads go through LlmConnectorDao;
// the edit API owns writes, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_llm_connector', schema: 'public')]
class AbstractLlmConnector
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    protected int $id;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $label = null;

    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    /** @var array<string, mixed>|null */
    protected ?array $metadata = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $url = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $model = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $provider = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $driver = null;

    #[ORM\Column(name: 'reasoning_model', type: 'integer', nullable: true)]
    protected ?int $reasoningModel = null;

    #[ORM\Column(name: 'max_tokens', type: 'integer', nullable: true)]
    protected ?int $maxTokens = null;

    #[ORM\Column(name: 'enforce_json', type: 'integer', nullable: true)]
    protected ?int $enforceJson = null;

    #[ORM\Column(name: 'prefill_json', type: 'integer', nullable: true)]
    protected ?int $prefillJson = null;

    #[ORM\Column(name: 'local_api_key_id', type: 'integer', nullable: true)]
    protected ?int $localApiKeyId = null;

    #[ORM\Column(name: 'api_key_id', type: 'guid', nullable: true)]
    protected ?string $apiKeyId = null;

    #[ORM\Column(name: 'json_schema', type: 'integer', nullable: true)]
    protected ?int $jsonSchema = null;

    #[ORM\Column(type: 'decimal', nullable: true, precision: 38, scale: 10)]
    protected ?string $temperature = null;

    #[ORM\Column(name: 'presence_penalty', type: 'decimal', nullable: true, precision: 38, scale: 10)]
    protected ?string $presencePenalty = null;

    #[ORM\Column(name: 'frequency_penalty', type: 'decimal', nullable: true, precision: 38, scale: 10)]
    protected ?string $frequencyPenalty = null;

    #[ORM\Column(name: 'repetition_penalty', type: 'decimal', nullable: true, precision: 38, scale: 10)]
    protected ?string $repetitionPenalty = null;

    #[ORM\Column(name: 'top_p', type: 'decimal', nullable: true, precision: 38, scale: 10)]
    protected ?string $topP = null;

    #[ORM\Column(name: 'top_k', type: 'integer', nullable: true)]
    protected ?int $topK = null;

    #[ORM\Column(name: 'min_p', type: 'decimal', nullable: true, precision: 38, scale: 10)]
    protected ?string $minP = null;

    #[ORM\Column(name: 'top_a', type: 'decimal', nullable: true, precision: 38, scale: 10)]
    protected ?string $topA = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $service = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'metadata' => $this->metadata,
            'url' => $this->url,
            'model' => $this->model,
            'provider' => $this->provider,
            'driver' => $this->driver,
            'reasoning_model' => $this->reasoningModel,
            'max_tokens' => $this->maxTokens,
            'enforce_json' => $this->enforceJson,
            'prefill_json' => $this->prefillJson,
            'local_api_key_id' => $this->localApiKeyId,
            'api_key_id' => $this->apiKeyId,
            'json_schema' => $this->jsonSchema,
            'temperature' => $this->temperature,
            'presence_penalty' => $this->presencePenalty,
            'frequency_penalty' => $this->frequencyPenalty,
            'repetition_penalty' => $this->repetitionPenalty,
            'top_p' => $this->topP,
            'top_k' => $this->topK,
            'min_p' => $this->minP,
            'top_a' => $this->topA,
            'service' => $this->service,
        ];
    }
}
