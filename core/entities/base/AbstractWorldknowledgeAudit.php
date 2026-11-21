<?php

// The mapped row of public.core_worldknowledge_audit. Read-only mapping: reads go through WorldknowledgeAuditDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_worldknowledge_audit', schema: 'public')]
class AbstractWorldknowledgeAudit
{
    #[ORM\Column(name: 'access_decisions', type: 'json', options: ['jsonb' => true])]
    protected mixed $accessDecisions = null;

    #[ORM\Column(name: 'algorithm_version', type: 'text')]
    protected string $algorithmVersion;

    #[ORM\Id]
    #[ORM\Column(name: 'audit_id', type: 'bigint')]
    protected int|string $auditId;

    #[ORM\Column(name: 'catalog_checksum', type: 'string', nullable: true, length: 64)]
    protected ?string $catalogChecksum = null;

    #[ORM\Column(name: 'catalog_id', type: 'text', nullable: true)]
    protected ?string $catalogId = null;

    #[ORM\Column(name: 'catalog_version', type: 'text', nullable: true)]
    protected ?string $catalogVersion = null;

    #[ORM\Column(name: 'context_tags', type: 'json', options: ['jsonb' => true])]
    protected mixed $contextTags = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'elapsed_ms', type: 'decimal', precision: 38, scale: 10)]
    protected string $elapsedMs;

    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    protected mixed $fallback = null;

    #[ORM\Column(name: 'forced_signals', type: 'json', options: ['jsonb' => true])]
    protected mixed $forcedSignals = null;

    #[ORM\Column(name: 'grounded_matches', type: 'json', options: ['jsonb' => true])]
    protected mixed $groundedMatches = null;

    #[ORM\Column(name: 'input_text', type: 'text')]
    protected string $inputText;

    #[ORM\Column(name: 'normalized_input', type: 'text')]
    protected string $normalizedInput;

    #[ORM\Column(name: 'npc_name', type: 'text', nullable: true)]
    protected ?string $npcName = null;

    #[ORM\Column(name: 'prompt_hash', type: 'string', nullable: true, length: 64)]
    protected ?string $promptHash = null;

    #[ORM\Column(name: 'rejected_candidates', type: 'json', options: ['jsonb' => true])]
    protected mixed $rejectedCandidates = null;

    #[ORM\Column(name: 'request_type', type: 'text')]
    protected string $requestType;

    #[ORM\Column(name: 'retrieval_elapsed_ms', type: 'decimal', precision: 38, scale: 10)]
    protected string $retrievalElapsedMs;

    #[ORM\Column(name: 'selected_articles', type: 'json', options: ['jsonb' => true])]
    protected mixed $selectedArticles = null;

    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    protected mixed $settings = null;

    #[ORM\Column(type: 'text')]
    protected string $status;

    #[ORM\Column(name: 'tag_decisions', type: 'json', options: ['jsonb' => true])]
    protected mixed $tagDecisions = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'access_decisions' => $this->accessDecisions,
            'algorithm_version' => $this->algorithmVersion,
            'audit_id' => $this->auditId,
            'catalog_checksum' => $this->catalogChecksum,
            'catalog_id' => $this->catalogId,
            'catalog_version' => $this->catalogVersion,
            'context_tags' => $this->contextTags,
            'created_at' => $this->createdAt,
            'elapsed_ms' => $this->elapsedMs,
            'fallback' => $this->fallback,
            'forced_signals' => $this->forcedSignals,
            'grounded_matches' => $this->groundedMatches,
            'input_text' => $this->inputText,
            'normalized_input' => $this->normalizedInput,
            'npc_name' => $this->npcName,
            'prompt_hash' => $this->promptHash,
            'rejected_candidates' => $this->rejectedCandidates,
            'request_type' => $this->requestType,
            'retrieval_elapsed_ms' => $this->retrievalElapsedMs,
            'selected_articles' => $this->selectedArticles,
            'settings' => $this->settings,
            'status' => $this->status,
            'tag_decisions' => $this->tagDecisions,
        ];
    }
}
