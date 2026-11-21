<?php

// The mapped row of public.core_memory. Read-only mapping: reads go through MemoryDao; writes stay in the
// statements that own them, so Doctrine never flushes it.
// Not mapped (DAO-only or unsupported types): embedding768, embedding, native_vec.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_memory', schema: 'public')]
class AbstractMemory
{
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $classifier = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $companions = null;

    #[ORM\Column(name: 'gamets_truncated', type: 'bigint')]
    protected int|string $gametsTruncated;

    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $n = null;

    #[ORM\Column(name: 'packed_message', type: 'text', nullable: true)]
    protected ?string $packedMessage = null;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    protected int $rowid;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $scope = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $summary = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $tags = null;

    #[ORM\Column(name: 'source_type', type: 'text')]
    protected string $sourceType;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $topic = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $location = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    protected int|string|null $localts = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    protected int|string|null $gamets = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $ts = null;

    #[ORM\Column(type: 'string', nullable: true, length: 1024)]
    protected ?string $sess = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $uid = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'classifier' => $this->classifier,
            'companions' => $this->companions,
            'gamets_truncated' => $this->gametsTruncated,
            'n' => $this->n,
            'packed_message' => $this->packedMessage,
            'rowid' => $this->rowid,
            'scope' => $this->scope,
            'summary' => $this->summary,
            'tags' => $this->tags,
            'source_type' => $this->sourceType,
            'topic' => $this->topic,
            'location' => $this->location,
            'localts' => $this->localts,
            'gamets' => $this->gamets,
            'ts' => $this->ts,
            'sess' => $this->sess,
            'uid' => $this->uid,
        ];
    }
}
