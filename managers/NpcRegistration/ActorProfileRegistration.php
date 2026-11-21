<?php

// ActorProfileRegistration: the `actor_profile` game-data record. It makes sure the named NPC has a character row, stores the
// profile, SPECIAL, skills, stats, equipment, factions and reputation in the character metadata, and applies the enabled
// profile rules. Moved out of gamedata.php with its behaviour unchanged; gamedata.php and /api/v1/npc/add stay thin adapters.
// The bridge helpers it uses (cleanBridgeString, findNpcForGameData, the metadata builders) are shared with the other
// gamedata.php record types and stay there.

declare(strict_types=1);

namespace Synth\Managers\NpcRegistration;

use Logger;

/** Handles one actor_profile record. */
final class ActorProfileRegistration
{
    private static function hasNonEmptyEquipment(array $equipment): bool
    {
        foreach ($equipment as $item) {
            if (!is_array($item)) {
                if (trim((string)$item) !== '') {
                    return true;
                }
                continue;
            }

            $name = trim((string)($item['name'] ?? ''));
            $baseid = trim((string)($item['baseid'] ?? ''));
            if ($name !== '' || $baseid !== '') {
                return true;
            }
        }

        return false;
    }

    private static function flattenFields(array $data): array
    {
        $identity = is_array($data['identity'] ?? null) ? $data['identity'] : [];
        $special = is_array($data['special'] ?? null) ? $data['special'] : [];
        $skills = is_array($data['skills'] ?? null) ? $data['skills'] : [];
        $stats = is_array($data['stats'] ?? null) ? $data['stats'] : [];

        $fields = [
            'refid' => \firstNonEmptyString([$data['refid'] ?? '', $identity['refid'] ?? '']),
            'baseid' => \firstNonEmptyString([$data['baseid'] ?? '', $identity['baseid'] ?? '']),
            'gender' => \firstNonEmptyString([$data['gender'] ?? '', $identity['gender'] ?? '']),
            'race' => \firstNonEmptyString([$data['race'] ?? '', $identity['race'] ?? '']),
            'voice' => \firstNonEmptyString([$data['voiceid'] ?? '', $identity['voiceid'] ?? '', $identity['voice'] ?? '']),
            'voice_formid' => \firstNonEmptyString([$data['voice_formid'] ?? '', $identity['voice_formid'] ?? '']),
            'voice_name' => \firstNonEmptyString([$data['voice_name'] ?? '', $identity['voice_name'] ?? '']),
        ];

        foreach (['strength', 'perception', 'endurance', 'charisma', 'intelligence', 'agility', 'luck'] as $key) {
            if (array_key_exists($key, $special)) {
                $fields[$key] = $special[$key];
            }
        }

        foreach ([
            'barter', 'energy_weapons', 'explosives', 'guns', 'lockpick', 'medicine',
            'melee_weapons', 'repair', 'science', 'sneak', 'speech', 'survival', 'unarmed'
        ] as $key) {
            if (array_key_exists($key, $skills)) {
                $fields[$key] = $skills[$key];
            }
        }

        foreach (['level', 'health', 'health_max', 'action_points', 'action_points_max', 'scale', 'xp', 'karma'] as $key) {
            if (array_key_exists($key, $stats)) {
                $fields[$key] = $stats[$key];
            }
        }

        if (!empty($data['factions'])) {
            $fields['factions'] = $data['factions'];
        }
        if (!empty($data['reputation'])) {
            $fields['reputation'] = $data['reputation'];
        }

        return array_filter($fields, static function ($value) {
            return !(is_string($value) && trim($value) === '');
        });
    }

    private static function sanitizePayload(array $data): array
    {
        $allowed = [
            'schema', 'type', 'actor_name', 'actor_type', 'identity', 'special',
            'skills', 'stats', 'equipment', 'factions', 'reputation', 'source',
            'refid', 'baseid', 'timestamp', 'gamets',
        ];

        $profile = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $data)) {
                $profile[$key] = \cleanBridgePayloadValue($data[$key]);
            }
        }
        $profile['schema'] = trim((string)($profile['schema'] ?? '')) !== ''
            ? $profile['schema']
            : 'synth.actor_profile.v1';
        $profile['updated_at'] = time();
        return $profile;
    }

    private static function applyRules(array $currentData, array $data, \NpcMaster $npcMaster): void
    {
        $db = \Synth\Bootstrap::db();
        $factionNames = [];
        $factionFormIds = [];
        foreach (($data['factions'] ?? []) as $faction) {
            if (!is_array($faction) || intval($faction['rank'] ?? -1) < 0) {
                continue;
            }
            $name = \cleanBridgeString($faction['name'] ?? '');
            if ($name !== '' && strcasecmp($name, 'Unknown Faction') !== 0) {
                $factionNames[] = $name;
            }
            $formId = \synthNormalizeHexIdentifier($faction['formid'] ?? '', 8);
            if ($formId !== '') {
                $factionFormIds[] = $formId;
            }
        }

        $factionFormIds = array_values(array_unique($factionFormIds));
        if (!empty($factionFormIds)) {
            foreach (\Synth\Core\DbManager\Dao\FactionDao::current($db)->namesByFormIds($factionFormIds) as $factionRow) {
                $name = trim((string)($factionRow['name'] ?? ''));
                if ($name !== '') {
                    $factionNames[] = $name;
                }
            }
        }
        $factionNames = array_values(array_unique($factionNames));

        $mods = is_array($data['mods'] ?? null)
            ? array_values(array_filter(
                array_map(static fn($mod) => trim((string)$mod), $data['mods']),
                static fn($mod) => $mod !== ''
            ))
            : [];
        $rules = \Synth\Core\DbManager\Dao\NpcProfileRuleDao::current($db)->matching((string)($currentData['npc_name'] ?? ''), (string)($currentData['race'] ?? ''),
            (string)($currentData['gender'] ?? ''), (string)($currentData['base'] ?? ''), $factionNames, $mods);

        if (empty($rules)) {
            return;
        }

        $metadata = json_decode((string)($currentData['metadata'] ?? ''), true);
        if (!is_array($metadata)) {
            $metadata = [];
        }
        foreach ($rules as $rule) {
            if (!empty($rule['profile'])) {
                $currentData['profile_id'] = intval($rule['profile']);
            }
            $actions = json_decode((string)($rule['action'] ?? ''), true);
            if (!is_array($actions)) {
                continue;
            }
            foreach ($actions as $key => $value) {
                if ($key === 'metadata' && is_array($value)) {
                    $metadata = array_merge($metadata, $value);
                } else {
                    $currentData[$key] = $value;
                }
            }
        }

        $currentData['metadata'] = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $npcMaster->updateByArray($currentData);
        Logger::info("[PROFILE_RULES] Applied " . count($rules) . " rule(s) to {$currentData['npc_name']}");
    }

    public static function handle(array $data, \NpcMaster $npcMaster): void
    {
        $actorName = \cleanBridgeString($data['actor_name'] ?? '');
        $actorType = \cleanBridgeString($data['actor_type'] ?? '');
        if ($actorName === '' || $actorType === 'player') {
            return;
        }

        $profileFields = self::flattenFields($data);
        $refid = trim((string)($profileFields['refid'] ?? ''));
        \synth_ensure_npc(\Synth\Bootstrap::db(), $actorName, $refid, $profileFields);

        $currentData = \findNpcForGameData($npcMaster, $actorName, $data);
        if (!$currentData) {
            $currentData = $npcMaster->getByName($actorName);
        }
        if (!$currentData) {
            Logger::warn("[gamedata.php] actor_profile could not create/find NPC: {$actorName}");
            return;
        }

        $metadataUpdates = [
            'actor_profile' => self::sanitizePayload($data),
            'actor_profile_updated' => time(),
        ];
        if (!empty($data['special']) && is_array($data['special'])) {
            $metadataUpdates['special'] = $data['special'];
        }
        if (!empty($data['skills']) && is_array($data['skills'])) {
            $metadataUpdates['skills'] = \buildSkillsMetadataValue($data['skills']);
        }
        if (!empty($data['stats']) && is_array($data['stats'])) {
            $metadataUpdates['stats'] = \buildStatsMetadataValue($data['stats']);
        }
        if (!empty($data['equipment']) && is_array($data['equipment']) && self::hasNonEmptyEquipment($data['equipment'])) {
            $metadataUpdates['equipment'] = \buildEquipmentMetadataValue($data['equipment']);
            $metadataUpdates['equipment_structured'] = $data['equipment'];
        }
        if (!empty($data['factions']) && is_array($data['factions'])) {
            $metadataUpdates['factions'] = array_values($data['factions']);
        }
        if (!empty($data['reputation']) && is_array($data['reputation'])) {
            $metadataUpdates['reputation'] = array_values($data['reputation']);
        }

        $npcMaster->updateMetadataKeysByName($currentData['npc_name'], $metadataUpdates);

        $baseid = \firstNonEmptyString([$data['baseid'] ?? '', $data['identity']['baseid'] ?? '']);
        if ($baseid !== '') {
            \Synth\Bootstrap::db()->execQuery("
                UPDATE public.core_npc
                SET base = '" . \Synth\Bootstrap::db()->escape($baseid) . "'
                WHERE id = " . intval($currentData['id']) . "
            ");
        }

        $currentData = $npcMaster->getByName($currentData['npc_name']) ?: $currentData;
        self::applyRules($currentData, $data, $npcMaster);

        Logger::debug("[gamedata.php] Updated actor_profile for {$currentData['npc_name']}");
    }
}
