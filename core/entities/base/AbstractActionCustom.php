<?php

// The mapped row of public.core_action_custom. Read-only mapping: reads go through ActionCustomDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_action_custom', schema: 'public')]
class AbstractActionCustom
{
    #[ORM\Column(name: 'action_name', type: 'string', length: 255)]
    protected string $actionName;

    #[ORM\Column(name: 'available_to_followers', type: 'boolean')]
    protected bool $availableToFollowers;

    #[ORM\Column(name: 'available_to_narrator', type: 'boolean')]
    protected bool $availableToNarrator;

    #[ORM\Column(name: 'available_to_npc', type: 'boolean')]
    protected bool $availableToNpc;

    #[ORM\Column(name: 'code_name', type: 'string', length: 128)]
    protected string $codeName;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', nullable: true)]
    protected ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'text')]
    protected string $description;

    #[ORM\Column(name: 'game_function', type: 'boolean')]
    protected bool $gameFunction;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    protected int $id;

    #[ORM\Column(name: 'import_version', type: 'bigint')]
    protected int|string $importVersion;

    #[ORM\Column(name: 'is_activated', type: 'boolean')]
    protected bool $isActivated;

    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    protected mixed $metadata = null;

    #[ORM\Column(name: 'parameters_json', type: 'json', options: ['jsonb' => true])]
    protected mixed $parametersJson = null;

    #[ORM\Column(name: 'return_message', type: 'text')]
    protected string $returnMessage;

    #[ORM\Column(name: 'script_proxy_program', type: 'json', nullable: true, options: ['jsonb' => true])]
    protected mixed $scriptProxyProgram = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    protected ?DateTimeImmutable $updatedAt = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action_name' => $this->actionName,
            'available_to_followers' => $this->availableToFollowers,
            'available_to_narrator' => $this->availableToNarrator,
            'available_to_npc' => $this->availableToNpc,
            'code_name' => $this->codeName,
            'created_at' => $this->createdAt,
            'description' => $this->description,
            'game_function' => $this->gameFunction,
            'id' => $this->id,
            'import_version' => $this->importVersion,
            'is_activated' => $this->isActivated,
            'metadata' => $this->metadata,
            'parameters_json' => $this->parametersJson,
            'return_message' => $this->returnMessage,
            'script_proxy_program' => $this->scriptProxyProgram,
            'updated_at' => $this->updatedAt,
        ];
    }
}
