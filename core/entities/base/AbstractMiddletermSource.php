<?php

// The mapped row of public.core_middleterm_source. Read-only mapping: reads go through MiddletermSourceDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_middleterm_source', schema: 'public')]
class AbstractMiddletermSource
{
    #[ORM\Id]
    #[ORM\Column(name: 'input_id', type: 'bigint')]
    protected int|string $inputId;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    protected int $position;

    #[ORM\Column(name: 'source_sha256', type: 'string', length: 64, options: ['fixed' => true])]
    protected string $sourceSha256;

    #[ORM\Column(name: 'summary_id', type: 'bigint')]
    protected int|string $summaryId;

    #[ORM\Column(name: 'summary_revision', type: 'bigint')]
    protected int|string $summaryRevision;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'input_id' => $this->inputId,
            'position' => $this->position,
            'source_sha256' => $this->sourceSha256,
            'summary_id' => $this->summaryId,
            'summary_revision' => $this->summaryRevision,
        ];
    }
}
