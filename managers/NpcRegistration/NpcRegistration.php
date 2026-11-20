<?php

// NpcRegistration: stores the actors a client names, over the actor DAOs. Placed actors are keyed by plugin and plugin-local
// reference id (never a load-order prefix); runtime (0xFF) actors by a client-minted id (docs/RUNTIME-ACTORS.md, sections 2, 5
// and 6). Every input is untrusted: ids, plugins, names, spawn kinds and caps are validated here, and a refusal stores nothing.

declare(strict_types=1);

namespace Synth\Managers\NpcRegistration;

use Synth\Core\DbManager\Dao\NpcActorDao;
use Synth\Core\DbManager\Dao\NpcDao;
use Synth\Core\DbManager\Dao\NpcRuntimeActorDao;
use Synth\Core\Entities\Base\AbstractNpcRuntimeActor;

/** Registers, renames, retires and re-adopts actors. */
final class NpcRegistration
{
    public const MAX_NAME = 255;
    public const MAX_PER_PLAYTHROUGH = 20000;
    public const MAX_BATCH = 32;
    public const MAX_SYNC = 2048;
    public const SPAWN_KINDS = ['leveled_spawn', 'workshop_settler', 'quest_alias', 'script_placed', 'summoned_or_cloned', 'unknown'];
    public const RETIRE_REASONS = ['deleted', 'id_reused'];

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    public function __construct(
        private readonly NpcActorDao $actors,
        private readonly NpcRuntimeActorDao $runtimeActors,
        private readonly NpcDao $characters,
        private readonly int $maxPerPlaythrough = self::MAX_PER_PLAYTHROUGH,
    ) {
    }

    /** The manager over the DAOs of the current database session. */
    public static function current(): self
    {
        return new self(NpcActorDao::current(), NpcRuntimeActorDao::current(), NpcDao::current());
    }

    /**
     * Stores a placed actor for a character that already exists. The plugin and local id come from the manifest decode, not from
     * the client; a form id that carries the runtime prefix is refused even when a plugin is named (invariant 1).
     *
     * @param array<string, mixed> $actor plugin, local_id (required); name, form_id, gender, race, actor_class,
     *     base_plugin, base_local_id, template_plugin, template_local_id (optional)
     */
    public function registerPlaced(array $actor, int $characterId): RegistrationResult
    {
        $plugin = strtolower(trim((string) ($actor['plugin'] ?? '')));
        $local = self::localId($actor['local_id'] ?? null);
        $formId = preg_replace('/^0x/i', '', (string) ($actor['form_id'] ?? '')) ?? '';
        $name = array_key_exists('name', $actor) && $actor['name'] !== null ? $this->cleanName($actor['name']) : null;
        $nameGiven = array_key_exists('name', $actor) && $actor['name'] !== null;
        if ($characterId < 1 || $local === null || self::pluginOrNull($plugin) === null
            || ($formId !== '' && preg_match('/^[0-9a-f]{8}$/Di', $formId) !== 1)
            || strncasecmp($formId, 'FF', 2) === 0
            || ($nameGiven && $name === null)) {
            return RegistrationResult::rejected('invalid_identity');
        }
        $existing = $this->actors->find($local, $plugin);
        $this->actors->save([
            'refid' => $local, 'plugin' => $plugin, 'character_id' => $existing?->getCharacterId() ?? $characterId, 'name' => $name,
            'gender' => self::shortText($actor['gender'] ?? null, 16), 'race' => self::shortText($actor['race'] ?? null, 255),
            'actor_class' => self::shortText($actor['actor_class'] ?? null, 64),
            'base_plugin' => self::pluginOrNull($actor['base_plugin'] ?? null), 'base_formid' => self::localIdOrNull($actor['base_local_id'] ?? null),
            'template_plugin' => self::pluginOrNull($actor['template_plugin'] ?? null),
            'template_formid' => self::localIdOrNull($actor['template_local_id'] ?? null),
        ]);
        $stored = $this->actors->find($local, $plugin);

        return RegistrationResult::accepted($existing === null ? RegistrationResult::REGISTERED : RegistrationResult::EXISTING,
            $stored?->getCharacterId() ?? $characterId);
    }

    /**
     * Registers a runtime actor by its client-minted id (idempotent). A second call renames it and updates the form id hint; the
     * character never changes. A new id whose fingerprint matches exactly one actor that left the save, with a claim hint equal to
     * that actor's last form id, takes over its character and marks the old row replaced (status re_adopted).
     *
     * @param array<string, mixed> $actor playthrough_id (from the session), runtime_actor_id, form_id, name, and the optional fields of the runtime_actor.register message
     */
    public function registerRuntime(array $actor): RegistrationResult
    {
        $playthrough = trim((string) ($actor['playthrough_id'] ?? ''));
        $id = strtolower((string) ($actor['runtime_actor_id'] ?? ''));
        $formId = strtoupper(preg_replace('/^0x/i', '', (string) ($actor['form_id'] ?? '')) ?? '');
        $name = $this->cleanName($actor['name'] ?? null);
        if ($playthrough === '' || preg_match(self::UUID, $id) !== 1 || preg_match('/^FF[0-9A-F]{6}$/D', $formId) !== 1 || $name === null
            || !empty($actor['origin_plugin'])) {
            return RegistrationResult::rejected('invalid_identity');
        }
        $spawn = is_array($actor['spawn'] ?? null) ? $actor['spawn'] : [];
        $kind = (string) ($spawn['kind'] ?? 'unknown');
        $fingerprint = isset($actor['fingerprint']) ? strtolower((string) $actor['fingerprint']) : null;
        if (!in_array($kind, self::SPAWN_KINDS, true) || ($fingerprint !== null && preg_match('/^[0-9a-f]{64}$/D', $fingerprint) !== 1)) {
            return RegistrationResult::rejected('invalid_identity');
        }

        $existing = $this->runtimeActors->find($playthrough, $id);
        $status = RegistrationResult::EXISTING;
        $replaces = null;
        if ($existing !== null) {
            $characterId = $existing->getCharacterId();
        } else {
            if ($this->runtimeActors->countForPlaythrough($playthrough) >= $this->maxPerPlaythrough) {
                return RegistrationResult::rejected('registry_full');
            }
            $adopted = $this->adoptable($playthrough, $fingerprint, $actor['claim_hint'] ?? null);
            if ($adopted !== null) {
                $characterId = $adopted->getCharacterId();
                $replaces = $adopted->getRuntimeActorId();
                $status = RegistrationResult::RE_ADOPTED;
            } else {
                $characterId = $this->characters->createScoped($name, $playthrough, $formId);
                $status = RegistrationResult::REGISTERED;
            }
        }
        $base = is_array($actor['base'] ?? null) ? $actor['base'] : [];
        $template = is_array($actor['template'] ?? null) ? $actor['template'] : [];
        $race = is_array($actor['race'] ?? null) ? $actor['race'] : [];
        $this->runtimeActors->register([
            'playthrough_id' => $playthrough, 'runtime_actor_id' => $id, 'character_id' => $characterId, 'last_form_id' => $formId, 'name' => $name,
            'race_plugin' => self::pluginOrNull($race['plugin'] ?? null), 'race_local_id' => self::localIdOrNull($race['local_id'] ?? null),
            'gender' => self::shortText($actor['sex'] ?? null, 16), 'actor_class' => self::shortText($actor['actor_class'] ?? null, 64),
            'base_plugin' => self::pluginOrNull($base['plugin'] ?? null), 'base_local_id' => self::localIdOrNull($base['local_id'] ?? null),
            'template_plugin' => self::pluginOrNull($template['plugin'] ?? null), 'template_local_id' => self::localIdOrNull($template['local_id'] ?? null),
            'fingerprint' => $fingerprint, 'spawn_kind' => $kind, 'spawn_context' => self::spawnContext($spawn),
            'gamets' => isset($actor['gamets']) ? max(0, (int) $actor['gamets']) : null,
        ]);
        if ($replaces !== null) {
            $this->runtimeActors->setStatus($playthrough, $replaces, 'replaced', $id);
        }

        return RegistrationResult::accepted($status, $characterId);
    }

    /**
     * Registers up to MAX_BATCH actors of one playthrough (the post-load re-announcement); each gets its own result.
     *
     * @param list<mixed> $actors
     * @return list<RegistrationResult>
     */
    public function registerRuntimeBatch(string $playthroughId, array $actors): array
    {
        if (count($actors) > self::MAX_BATCH) {
            return [RegistrationResult::rejected('batch_too_large')];
        }
        $results = [];
        foreach ($actors as $actor) {
            $results[] = is_array($actor)
                ? $this->registerRuntime(['playthrough_id' => $playthroughId] + $actor)
                : RegistrationResult::rejected('invalid_identity');
        }

        return $results;
    }

    /** Retires a runtime actor (idempotent); an unknown id or reason is refused. A dead actor is not retired. */
    public function retireRuntime(string $playthroughId, string $runtimeActorId, string $reason): bool
    {
        $id = strtolower($runtimeActorId);
        if (!in_array($reason, self::RETIRE_REASONS, true) || preg_match(self::UUID, $id) !== 1
            || $this->runtimeActors->find($playthroughId, $id) === null) {
            return false;
        }
        $this->runtimeActors->setStatus($playthroughId, $id, 'retired');

        return true;
    }

    /**
     * Post-load sync: the ids the client re-bound with their current form ids. Listed ids are refreshed as active at the new
     * hint; the caller's other active ids become not_in_save (kept, inactive). Nothing else changes.
     *
     * @param list<array<string, mixed>> $bound entries of runtime_actor_id and form_id
     * @param list<string> $knownActiveIds the playthrough's active ids, from the caller's read
     * @return array{rebound: int, not_in_save: int, ignored: int}
     */
    public function syncRuntime(string $playthroughId, array $bound, array $knownActiveIds): array
    {
        $counts = ['rebound' => 0, 'not_in_save' => 0, 'ignored' => 0];
        $present = [];
        foreach (array_slice($bound, 0, self::MAX_SYNC) as $entry) {
            $entry = is_array($entry) ? $entry : [];
            $id = strtolower((string) ($entry['runtime_actor_id'] ?? ''));
            $row = preg_match(self::UUID, $id) === 1 ? $this->runtimeActors->find($playthroughId, $id) : null;
            $formId = strtoupper(preg_replace('/^0x/i', '', (string) ($entry['form_id'] ?? '')) ?? '');
            if ($row === null || in_array($row->getStatus(), ['retired', 'replaced'], true) || preg_match('/^FF[0-9A-F]{6}$/D', $formId) !== 1) {
                $counts['ignored']++;
                continue;
            }
            $present[$id] = true;
            $this->runtimeActors->register([
                'playthrough_id' => $playthroughId, 'runtime_actor_id' => $id, 'character_id' => $row->getCharacterId(),
                'last_form_id' => $formId, 'name' => $row->getName(),
            ]);
            $counts['rebound']++;
        }
        foreach ($knownActiveIds as $id) {
            $id = strtolower($id);
            if (!isset($present[$id]) && $this->runtimeActors->setStatus($playthroughId, $id, 'not_in_save')) {
                $counts['not_in_save']++;
            }
        }

        return $counts;
    }

    /** The one actor a fingerprint and claim hint may take over, or null (two look-alikes are never merged). */
    private function adoptable(string $playthrough, ?string $fingerprint, mixed $claimHint): ?AbstractNpcRuntimeActor
    {
        $hint = is_array($claimHint) ? strtoupper(preg_replace('/^0x/i', '', (string) ($claimHint['last_form_id'] ?? '')) ?? '') : '';
        if ($fingerprint === null || $hint === '') {
            return null;
        }
        $candidates = $this->runtimeActors->findUnlinkedByFingerprint($playthrough, $fingerprint);

        return count($candidates) === 1 && $candidates[0]->getLastFormId() === $hint ? $candidates[0] : null;
    }

    /** A display name of 1 to MAX_NAME characters without control characters, or null. */
    private function cleanName(mixed $name): ?string
    {
        if (!is_string($name)) {
            return null;
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            return null;
        }

        return $name;
    }

    /** A plugin-local id as six lower-case hex digits (a light plugin's three are padded), or null. */
    private static function localId(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return preg_match('/^[0-9a-f]{3,6}$/D', $value) === 1 ? str_pad($value, 6, '0', STR_PAD_LEFT) : null;
    }

    private static function localIdOrNull(mixed $value): ?string
    {
        return $value === null ? null : self::localId($value);
    }

    /** A lower-cased plugin file name, or null when absent or malformed. */
    private static function pluginOrNull(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return preg_match('/^[^\/\\\\]{1,248}\.(esm|esp|esl)$/D', $value) === 1 ? $value : null;
    }

    private static function shortText(mixed $value, int $max): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' && mb_strlen($value) <= $max && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1 ? $value : null;
    }

    /**
     * The descriptive spawn context kept with an actor: only cell, worldspace and location pairs of plugin and local id.
     *
     * @param array<string, mixed> $spawn
     * @return array<string, array{plugin: string, local_id: string}>|\stdClass an empty object when nothing usable was sent (the column holds an object)
     */
    private static function spawnContext(array $spawn): array|\stdClass
    {
        $context = [];
        foreach (['cell', 'worldspace', 'location'] as $key) {
            $ref = is_array($spawn[$key] ?? null) ? $spawn[$key] : [];
            $plugin = self::pluginOrNull($ref['plugin'] ?? null);
            $local = self::localId($ref['local_id'] ?? null);
            if ($plugin !== null && $local !== null) {
                $context[$key] = ['plugin' => $plugin, 'local_id' => $local];
            }
        }

        return $context === [] ? new \stdClass() : $context;
    }
}
