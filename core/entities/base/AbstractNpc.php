<?php

// The mapped row of public.core_npc. Read-only mapping: reads go through NpcDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_npc', schema: 'public')]
class AbstractNpc
{
    #[ORM\Column(name: 'actor_identity_id', type: 'bigint', nullable: true)]
    protected int|string|null $actorIdentityId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $appearance = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $base = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $core = null;

    #[ORM\Column(name: 'dynamic_profile', type: 'integer', nullable: true)]
    protected ?int $dynamicProfile = null;

    #[ORM\Column(name: 'emote_moods', type: 'text', nullable: true)]
    protected ?string $emoteMoods = null;

    #[ORM\Column(name: 'extended_data', type: 'json', nullable: true, options: ['jsonb' => true])]
    protected mixed $extendedData = null;

    #[ORM\Column(name: 'gamets_last_updated', type: 'decimal', nullable: true, precision: 38, scale: 10)]
    protected ?string $gametsLastUpdated = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $gender = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $goals = null;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    protected int $id;

    #[ORM\Column(name: 'lock_profile', type: 'integer', nullable: true)]
    protected ?int $lockProfile = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $md5 = null;

    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    protected mixed $metadata = null;

    #[ORM\Column(name: 'npc_favorite', type: 'integer', nullable: true)]
    protected ?int $npcFavorite = null;

    #[ORM\Column(name: 'npc_name', type: 'text')]
    protected string $npcName;

    #[ORM\Column(name: 'npc_static_bio', type: 'text', nullable: true)]
    protected ?string $npcStaticBio = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $occupation = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $personality = null;

    #[ORM\Column(name: 'profile_id', type: 'integer', nullable: true)]
    protected ?int $profileId = null;

    #[ORM\Column(name: 'prompt_head', type: 'text', nullable: true)]
    protected ?string $promptHead = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $race = null;

    #[ORM\Column(type: 'string', nullable: true, length: 16)]
    protected ?string $refid = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $relationships = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $skills = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $speechstyle = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $tags = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $voiceid = null;

    #[ORM\Column(name: 'worldknowledge_tags', type: 'text', nullable: true)]
    protected ?string $worldknowledgeTags = null;

    #[ORM\Column(name: 'scope_playthrough_id', type: 'text', nullable: true)]
    protected ?string $scopePlaythroughId = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'actor_identity_id' => $this->actorIdentityId,
            'appearance' => $this->appearance,
            'base' => $this->base,
            'core' => $this->core,
            'dynamic_profile' => $this->dynamicProfile,
            'emote_moods' => $this->emoteMoods,
            'extended_data' => $this->extendedData,
            'gamets_last_updated' => $this->gametsLastUpdated,
            'gender' => $this->gender,
            'goals' => $this->goals,
            'id' => $this->id,
            'lock_profile' => $this->lockProfile,
            'md5' => $this->md5,
            'metadata' => $this->metadata,
            'npc_favorite' => $this->npcFavorite,
            'npc_name' => $this->npcName,
            'npc_static_bio' => $this->npcStaticBio,
            'occupation' => $this->occupation,
            'personality' => $this->personality,
            'profile_id' => $this->profileId,
            'prompt_head' => $this->promptHead,
            'race' => $this->race,
            'refid' => $this->refid,
            'relationships' => $this->relationships,
            'skills' => $this->skills,
            'speechstyle' => $this->speechstyle,
            'tags' => $this->tags,
            'voiceid' => $this->voiceid,
            'worldknowledge_tags' => $this->worldknowledgeTags,
            'scope_playthrough_id' => $this->scopePlaythroughId,
        ];
    }
}
