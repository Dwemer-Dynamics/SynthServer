<?php

// The mapped row of public.core_memory_log. Read-only mapping: reads go through MemoryLogDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_memory_log', schema: 'public')]
class AbstractMemoryLog
{
    #[ORM\Column(type: 'string', nullable: true, length: 64)]
    protected ?string $event = null;

    #[ORM\Column(type: 'bigint')]
    protected int|string $gamets;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $listener = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $localts = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $message = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $momentum = null;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $rowid;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $session = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $speaker = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    protected int|string|null $ts = null;

    #[ORM\Column(type: 'integer')]
    protected int $uid;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event' => $this->event,
            'gamets' => $this->gamets,
            'listener' => $this->listener,
            'localts' => $this->localts,
            'message' => $this->message,
            'momentum' => $this->momentum,
            'rowid' => $this->rowid,
            'session' => $this->session,
            'speaker' => $this->speaker,
            'ts' => $this->ts,
            'uid' => $this->uid,
        ];
    }
}
