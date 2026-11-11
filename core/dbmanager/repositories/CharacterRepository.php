<?php

namespace Synth\Core;

// Character registry reads. Registered = an existing actor_identity-bound NPC row with a profile and its
// primary LLM connector; never resolved by display name.
class CharacterRepository
{
    public function __construct(private $db) {}

    public function registered(string $fullFormId, string $originPlugin, string $playthroughId): ?array
    {
        return $this->one("SELECT a.id AS identity_id, n.id AS npc_id, p.id AS profile_id, c.*
            FROM synth.actor_identities a JOIN synth.core_npc_master n ON n.actor_identity_id=a.id
            JOIN synth.core_profiles p ON p.id=n.profile_id JOIN core_llm_connector c ON c.id=p.llm_primary_id
            WHERE a.game='fo4' AND upper(a.full_form_id)=upper($1) AND lower(a.origin_plugin)=lower($2)
                AND a.playthrough_id=$3 LIMIT 1", [$fullFormId, $originPlugin, $playthroughId]);
    }

    /** Runtime full form IDs of the other identities registered for the same plugin and playthrough. */
    public function otherFormIds(string $originPlugin, string $playthroughId, int $identityId): array
    {
        $result = $this->db->execQuery("SELECT full_form_id FROM synth.actor_identities WHERE game='fo4'
            AND lower(origin_plugin)=lower($1) AND playthrough_id=$2 AND id<>$3", [$originPlugin, $playthroughId, $identityId]);
        if (!$result) throw new \RuntimeException('Unable to read actor identities');
        return array_map('strval', array_column(pg_fetch_all($result) ?: [], 'full_form_id'));
    }

    public function npc(int $id): ?array
    {
        return $this->one('SELECT * FROM synth.core_npc_master WHERE id=$1', [$id]);
    }

    public function profile(int $id): ?array
    {
        return $this->one('SELECT * FROM synth.core_profiles WHERE id=$1', [$id]);
    }

    private function one(string $sql, array $params): ?array
    {
        $result = $this->db->execQuery($sql, $params);
        $row = $result ? pg_fetch_assoc($result) : false;
        return is_array($row) ? $row : null;
    }
}
