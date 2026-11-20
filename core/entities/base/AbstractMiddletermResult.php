<?php

// The mapped row of public.core_middleterm_result. Read-only mapping: reads go through MiddletermResultDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_middleterm_result', schema: 'public')]
class AbstractMiddletermResult
{
    #[ORM\Id]
    #[ORM\Column(name: 'history_event_id', type: 'bigint')]
    protected int|string $historyEventId;

    #[ORM\Column(name: 'input_id', type: 'bigint')]
    protected int|string $inputId;

    #[ORM\Column(type: 'text')]
    protected string $narrative;

    #[ORM\Column(name: 'result_sha256', type: 'string', length: 64, options: ['fixed' => true])]
    protected string $resultSha256;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'history_event_id' => $this->historyEventId,
            'input_id' => $this->inputId,
            'narrative' => $this->narrative,
            'result_sha256' => $this->resultSha256,
        ];
    }
}
