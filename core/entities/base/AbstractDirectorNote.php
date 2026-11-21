<?php

// The mapped row of public.core_director_note. Read-only mapping: reads go through DirectorNoteDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_director_note', schema: 'public')]
class AbstractDirectorNote
{
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $data = null;

    #[ORM\Column(type: 'bigint')]
    protected int|string $localts;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $rowid;

    #[ORM\Column(type: 'bigint')]
    protected int|string $ttl;

    #[ORM\Column(type: 'string', nullable: true, length: 128)]
    protected ?string $type = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'localts' => $this->localts,
            'rowid' => $this->rowid,
            'ttl' => $this->ttl,
            'type' => $this->type,
        ];
    }
}
