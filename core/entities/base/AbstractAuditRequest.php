<?php

// The mapped row of public.core_audit_request. Read-only mapping: reads go through AuditRequestDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_audit_request', schema: 'public')]
class AbstractAuditRequest
{
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $connector = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', nullable: true)]
    protected ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $request = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $result = null;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $rowid;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $url = null;

    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    protected mixed $usage = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'connector' => $this->connector,
            'created_at' => $this->createdAt,
            'request' => $this->request,
            'result' => $this->result,
            'rowid' => $this->rowid,
            'url' => $this->url,
            'usage' => $this->usage,
        ];
    }
}
