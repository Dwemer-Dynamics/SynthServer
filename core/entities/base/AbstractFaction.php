<?php

// The mapped row of public.core_faction. Read-only mapping: reads go through FactionDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_faction', schema: 'public')]
class AbstractFaction
{
    #[ORM\Column(type: 'decimal', nullable: true, precision: 38, scale: 10)]
    protected ?string $caps = null;

    #[ORM\Id]
    #[ORM\Column(type: 'text')]
    protected string $formid;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $name = null;

    #[ORM\Column(name: 'player_rank', type: 'decimal', nullable: true, precision: 38, scale: 10)]
    protected ?string $playerRank = null;

    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    protected mixed $stock = null;

    #[ORM\Column(name: 'vendor_cont', type: 'text', nullable: true)]
    protected ?string $vendorCont = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'caps' => $this->caps,
            'formid' => $this->formid,
            'name' => $this->name,
            'player_rank' => $this->playerRank,
            'stock' => $this->stock,
            'vendor_cont' => $this->vendorCont,
        ];
    }
}
