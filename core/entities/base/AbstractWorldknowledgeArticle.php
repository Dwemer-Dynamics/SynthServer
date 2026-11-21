<?php

// The mapped row of public.core_worldknowledge_article. Read-only mapping: reads go through WorldknowledgeArticleDao; writes stay in the
// statements that own them, so Doctrine never flushes it.
// Not mapped (DAO-only or unsupported types): native_vector, vector384.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_worldknowledge_article', schema: 'public')]
class AbstractWorldknowledgeArticle
{
    #[ORM\Column(type: 'text')]
    protected string $aliases;

    #[ORM\Column(name: 'canonical_topic', type: 'text')]
    protected string $canonicalTopic;

    #[ORM\Column(name: 'catalog_id', type: 'text', nullable: true)]
    protected ?string $catalogId = null;

    #[ORM\Column(name: 'catalog_version', type: 'text', nullable: true)]
    protected ?string $catalogVersion = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $category = null;

    #[ORM\Column(name: 'content_hash', type: 'string', nullable: true, length: 64)]
    protected ?string $contentHash = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    protected DateTimeImmutable $createdAt;

    #[ORM\Id]
    #[ORM\Column(name: 'entry_id', type: 'bigint')]
    protected int|string $entryId;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    protected bool $isActive;

    #[ORM\Column(name: 'knowledge_class_basic', type: 'text', nullable: true)]
    protected ?string $knowledgeClassBasic = null;

    #[ORM\Column(name: 'knowledge_class', type: 'text', nullable: true)]
    protected ?string $knowledgeClass = null;

    #[ORM\Column(name: 'source_kind', type: 'text')]
    protected string $sourceKind;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $tags = null;

    #[ORM\Column(name: 'topic_desc_basic', type: 'text', nullable: true)]
    protected ?string $topicDescBasic = null;

    #[ORM\Column(name: 'topic_desc', type: 'string', nullable: true)]
    protected ?string $topicDesc = null;

    #[ORM\Column(type: 'string')]
    protected string $topic;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    protected DateTimeImmutable $updatedAt;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'aliases' => $this->aliases,
            'canonical_topic' => $this->canonicalTopic,
            'catalog_id' => $this->catalogId,
            'catalog_version' => $this->catalogVersion,
            'category' => $this->category,
            'content_hash' => $this->contentHash,
            'created_at' => $this->createdAt,
            'entry_id' => $this->entryId,
            'is_active' => $this->isActive,
            'knowledge_class_basic' => $this->knowledgeClassBasic,
            'knowledge_class' => $this->knowledgeClass,
            'source_kind' => $this->sourceKind,
            'tags' => $this->tags,
            'topic_desc_basic' => $this->topicDescBasic,
            'topic_desc' => $this->topicDesc,
            'topic' => $this->topic,
            'updated_at' => $this->updatedAt,
        ];
    }
}
