<?php

// The mapped row of public.core_action_issued. Read-only mapping: reads go through ActionIssuedDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_action_issued', schema: 'public')]
class AbstractActionIssued
{
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $action = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $actorname = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $fullcall = null;

    #[ORM\Column(type: 'decimal', nullable: true, precision: 38, scale: 10)]
    protected ?string $gamets = null;

    #[ORM\Column(type: 'decimal', nullable: true, precision: 38, scale: 10)]
    protected ?string $localts = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $original = null;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    protected int $rowid;

    #[ORM\Column(type: 'decimal', nullable: true, precision: 38, scale: 10)]
    protected ?string $ts = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'actorname' => $this->actorname,
            'fullcall' => $this->fullcall,
            'gamets' => $this->gamets,
            'localts' => $this->localts,
            'original' => $this->original,
            'rowid' => $this->rowid,
            'ts' => $this->ts,
        ];
    }
}
