<?php

// The mapped row of public.core_response_log. Read-only mapping: reads go through ResponseLogDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_response_log', schema: 'public')]
class AbstractResponseLog
{
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $action = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $actor = null;

    #[ORM\Column(type: 'bigint')]
    protected int|string $localts;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $rowid;

    #[ORM\Column(type: 'bigint')]
    protected int|string $sent;

    #[ORM\Column(type: 'string', nullable: true, length: 256)]
    protected ?string $tag = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $text = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'actor' => $this->actor,
            'localts' => $this->localts,
            'rowid' => $this->rowid,
            'sent' => $this->sent,
            'tag' => $this->tag,
            'text' => $this->text,
        ];
    }
}
