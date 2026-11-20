<?php

// Reads and writes core_npc_runtime_actor. The runtime actor id is the only lookup key; the form id is a hint (the active-actor
// index), never an identity. Registration, status changes and the form-id hint are single-row statements with no network or
// model call, so a caller can run them inside its own database transaction.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use InvalidArgumentException;
use Synth\Core\Entities\Base\AbstractNpcRuntimeActor;


/** Finds and stores runtime actors. */
final class NpcRuntimeActorDao
{
    use UsesEntityManager;

    public const STATUSES = ['active', 'not_in_save', 'retired', 'replaced'];

    /** The actor of one client-minted id in one playthrough, or null. */
    public function find(string $playthroughId, string $runtimeActorId): ?AbstractNpcRuntimeActor
    {
        return $this->fresh()->find(AbstractNpcRuntimeActor::class,
            ['playthroughId' => $playthroughId, 'runtimeActorId' => strtolower($runtimeActorId)]);
    }

    /** The active actor last seen under a form id (FFxxxxxx), or null: a hint lookup, never an identity match. */
    public function findActiveByFormId(string $playthroughId, string $formId): ?AbstractNpcRuntimeActor
    {
        return $this->fresh()->getRepository(AbstractNpcRuntimeActor::class)
            ->findOneBy(['playthroughId' => $playthroughId, 'lastFormId' => strtoupper($formId), 'status' => 'active']);
    }

    /**
     * Registers an actor, or refreshes an existing one (idempotent by playthrough and runtime actor id): the name, form id
     * hint and descriptive fields follow the client, the status returns to 'active' and the character never changes.
     *
     * @param array<string, mixed> $row playthrough_id, runtime_actor_id, character_id, last_form_id, name (required); the rest optional
     */
    public function register(array $row): void
    {
        $formId = strtoupper((string) ($row['last_form_id'] ?? ''));
        $name = (string) ($row['name'] ?? '');
        $spawnKind = (string) ($row['spawn_kind'] ?? 'unknown');
        if ((string) ($row['playthrough_id'] ?? '') === '' || (int) ($row['character_id'] ?? 0) < 1 || $name === ''
            || preg_match('/^FF[0-9A-F]{6}$/D', $formId) !== 1
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', strtolower((string) ($row['runtime_actor_id'] ?? ''))) !== 1) {
            throw new InvalidArgumentException('A runtime actor needs a playthrough, a uuid, a character, a FF form id and a name.');
        }
        $this->connection()->executeStatement(
            'INSERT INTO public.core_npc_runtime_actor (playthrough_id, runtime_actor_id, character_id, last_form_id, name, race_plugin, race_local_id,
               gender, actor_class, base_plugin, base_local_id, template_plugin, template_local_id, fingerprint, spawn_kind, spawn_context,
               first_seen_gamets, last_seen_gamets)
             VALUES (:playthrough_id, CAST(:runtime_actor_id AS uuid), :character_id, :last_form_id, :name, :race_plugin, :race_local_id,
               :gender, :actor_class, :base_plugin, :base_local_id, :template_plugin, :template_local_id, :fingerprint, :spawn_kind,
               CAST(:spawn_context AS jsonb), :gamets, :gamets)
             ON CONFLICT (playthrough_id, runtime_actor_id) DO UPDATE SET last_form_id = EXCLUDED.last_form_id, name = EXCLUDED.name,
               race_plugin = COALESCE(EXCLUDED.race_plugin, core_npc_runtime_actor.race_plugin),
               race_local_id = COALESCE(EXCLUDED.race_local_id, core_npc_runtime_actor.race_local_id),
               gender = COALESCE(EXCLUDED.gender, core_npc_runtime_actor.gender),
               actor_class = COALESCE(EXCLUDED.actor_class, core_npc_runtime_actor.actor_class),
               base_plugin = COALESCE(EXCLUDED.base_plugin, core_npc_runtime_actor.base_plugin),
               base_local_id = COALESCE(EXCLUDED.base_local_id, core_npc_runtime_actor.base_local_id),
               template_plugin = COALESCE(EXCLUDED.template_plugin, core_npc_runtime_actor.template_plugin),
               template_local_id = COALESCE(EXCLUDED.template_local_id, core_npc_runtime_actor.template_local_id),
               fingerprint = COALESCE(EXCLUDED.fingerprint, core_npc_runtime_actor.fingerprint),
               last_seen_gamets = EXCLUDED.last_seen_gamets, status = \'active\', updated_at = now()',
            [
                'playthrough_id' => (string) $row['playthrough_id'], 'runtime_actor_id' => strtolower((string) $row['runtime_actor_id']),
                'character_id' => (int) $row['character_id'], 'last_form_id' => $formId, 'name' => $name,
                'race_plugin' => $row['race_plugin'] ?? null, 'race_local_id' => $row['race_local_id'] ?? null,
                'gender' => $row['gender'] ?? null, 'actor_class' => $row['actor_class'] ?? null,
                'base_plugin' => $row['base_plugin'] ?? null, 'base_local_id' => $row['base_local_id'] ?? null,
                'template_plugin' => $row['template_plugin'] ?? null, 'template_local_id' => $row['template_local_id'] ?? null,
                'fingerprint' => $row['fingerprint'] ?? null, 'spawn_kind' => $spawnKind,
                'spawn_context' => json_encode($row['spawn_context'] ?? new \stdClass(), JSON_THROW_ON_ERROR),
                'gamets' => isset($row['gamets']) ? (int) $row['gamets'] : null,
            ]
        );
    }

    /** Sets one actor's status; returns whether a row changed. 'retired' and 'replaced' never return to 'active' except by register(). */
    public function setStatus(string $playthroughId, string $runtimeActorId, string $status, ?string $replacedBy = null): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown runtime actor status.');
        }

        return $this->connection()->executeStatement(
            'UPDATE public.core_npc_runtime_actor SET status = :status, replaced_by_runtime_actor_id = CAST(:replaced_by AS uuid), updated_at = now()
              WHERE playthrough_id = :playthrough_id AND runtime_actor_id = CAST(:runtime_actor_id AS uuid)',
            ['status' => $status, 'replaced_by' => $replacedBy === null ? null : strtolower($replacedBy),
                'playthrough_id' => $playthroughId, 'runtime_actor_id' => strtolower($runtimeActorId)]
        ) === 1;
    }
}
