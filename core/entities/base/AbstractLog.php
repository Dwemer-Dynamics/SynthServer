<?php

// The mapped row of public.core_log. Read-only mapping: reads go through LogDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_log', schema: 'public')]
class AbstractLog
{
    #[ORM\Column(type: 'bigint')]
    protected int|string $localts;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $prompt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $response = null;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $rowid;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $url = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'localts' => $this->localts,
            'prompt' => $this->prompt,
            'response' => $this->response,
            'rowid' => $this->rowid,
            'url' => $this->url,
        ];
    }
}
