<?php

// The mapped row of public.core_llm_request_log: one LLM request sent through the driver layer, with its reply and timing.
// Read-only mapping: reads go through LlmRequestLogDao; the rows are written by that DAO's parameterized statements, so
// Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_llm_request_log', schema: 'public')]
class AbstractLlmRequestLog
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /** A streamed request (open, process, close) or a single non-streamed one (fast_request). */
    public const KIND_STREAM = 'stream';
    public const KIND_FAST = 'fast';

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $id;

    #[ORM\Column(name: 'created_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'finished_at', type: PreciseDateTimeType::NAME, nullable: true)]
    protected ?DateTimeImmutable $finishedAt = null;

    #[ORM\Column(name: 'duration_ms', type: 'integer', nullable: true)]
    protected ?int $durationMs = null;

    #[ORM\Column(type: 'text')]
    protected string $status = self::STATUS_RUNNING;

    #[ORM\Column(name: 'call_kind', type: 'text')]
    protected string $callKind;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $purpose = null;

    #[ORM\Column(name: 'correlation_id', type: 'text', nullable: true)]
    protected ?string $correlationId = null;

    #[ORM\Column(name: 'replay_of_id', type: 'bigint', nullable: true)]
    protected int|string|null $replayOfId = null;

    #[ORM\Column(name: 'connector_id', type: 'integer', nullable: true)]
    protected ?int $connectorId = null;

    #[ORM\Column(type: 'text')]
    protected string $driver;

    #[ORM\Column(type: 'text')]
    protected string $model = '';

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $url = null;

    /** @var array<string, mixed> the driver input: call, messages, parameters */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    protected array $request = [];

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'request_body', type: 'json', nullable: true, options: ['jsonb' => true])]
    protected ?array $requestBody = null;

    #[ORM\Column(name: 'request_truncated', type: 'boolean')]
    protected bool $requestTruncated = false;

    #[ORM\Column(name: 'http_status', type: 'integer', nullable: true)]
    protected ?int $httpStatus = null;

    #[ORM\Column(name: 'response_content', type: 'text', nullable: true)]
    protected ?string $responseContent = null;

    #[ORM\Column(name: 'response_truncated', type: 'boolean')]
    protected bool $responseTruncated = false;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    protected ?array $usage = null;

    #[ORM\Column(name: 'prompt_tokens', type: 'integer', nullable: true)]
    protected ?int $promptTokens = null;

    #[ORM\Column(name: 'completion_tokens', type: 'integer', nullable: true)]
    protected ?int $completionTokens = null;

    #[ORM\Column(name: 'total_tokens', type: 'integer', nullable: true)]
    protected ?int $totalTokens = null;

    #[ORM\Column(name: 'error_class', type: 'text', nullable: true)]
    protected ?string $errorClass = null;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    protected ?string $errorMessage = null;

    public function getId(): int
    {
        return (int) $this->id;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getFinishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCallKind(): string
    {
        return $this->callKind;
    }

    public function getPurpose(): ?string
    {
        return $this->purpose;
    }

    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    public function getReplayOfId(): ?int
    {
        return $this->replayOfId === null ? null : (int) $this->replayOfId;
    }

    public function getConnectorId(): ?int
    {
        return $this->connectorId;
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    /** @return array<string, mixed> */
    public function getRequest(): array
    {
        return $this->request;
    }

    /** @return array<string, mixed>|null */
    public function getRequestBody(): ?array
    {
        return $this->requestBody;
    }

    public function isRequestTruncated(): bool
    {
        return $this->requestTruncated;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function getResponseContent(): ?string
    {
        return $this->responseContent;
    }

    public function isResponseTruncated(): bool
    {
        return $this->responseTruncated;
    }

    /** @return array<string, mixed>|null */
    public function getUsage(): ?array
    {
        return $this->usage;
    }

    public function getPromptTokens(): ?int
    {
        return $this->promptTokens;
    }

    public function getCompletionTokens(): ?int
    {
        return $this->completionTokens;
    }

    public function getTotalTokens(): ?int
    {
        return $this->totalTokens;
    }

    public function getErrorClass(): ?string
    {
        return $this->errorClass;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
