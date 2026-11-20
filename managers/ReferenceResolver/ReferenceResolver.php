<?php

// ReferenceResolver: turns the typed references a client sends (a placed form id, or a runtime actor id) into the stored actor
// they name, and keeps the load-order manifest of each playthrough. References are keyed by plugin plus plugin-local form id;
// the load-order prefix is decoded through the manifest on the way in and never stored. An unresolved reference is a status,
// not an error: it may resolve later (docs/RUNTIME-ACTORS.md, sections 3 and 4). Read paths only; nothing here writes an actor.

declare(strict_types=1);

namespace Synth\Managers\ReferenceResolver;

use InvalidArgumentException;
use Synth\Core\DbManager\Dao\NpcActorDao;
use Synth\Core\DbManager\Dao\NpcRuntimeActorDao;
use Synth\Core\DbManager\Dao\PluginManifestRevisionDao;

/** Resolves typed references and records manifests. */
final class ReferenceResolver
{
    /** The player reference (Fallout 4: 00000014) is never an NPC actor. */
    public const PLAYER_REFERENCE = '00000014';
    public const KINDS = ['placed', 'runtime_actor'];

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    public function __construct(
        private readonly NpcActorDao $actors,
        private readonly NpcRuntimeActorDao $runtimeActors,
        private readonly PluginManifestRevisionDao $revisions,
    ) {
    }

    /** The resolver over the DAOs of the current database session. */
    public static function current(): self
    {
        return new self(NpcActorDao::current(), NpcRuntimeActorDao::current(), PluginManifestRevisionDao::current());
    }

    /**
     * Resolves one typed reference of a playthrough. The playthrough comes from the session, never from the body.
     *
     * @param array<string, mixed> $ref reference_kind (placed when absent), form_id, and origin_plugin (placed) or runtime_actor_id (runtime_actor)
     */
    public function resolve(string $playthroughId, array $ref, ?PluginManifest $manifest): Resolution
    {
        $kind = $ref['reference_kind'] ?? 'placed';
        if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
            return Resolution::unresolved('unsupported_reference_type');
        }
        $formId = $ref['form_id'] ?? null;
        $hex = is_string($formId) ? strtolower(preg_replace('/^0x/i', '', $formId) ?? '') : '';
        if (preg_match('/^[0-9a-f]{8}$/D', $hex) !== 1) {
            return Resolution::unresolved('invalid_rid');
        }

        return $kind === 'runtime_actor'
            ? $this->resolveRuntime($playthroughId, $ref, $hex)
            : $this->resolveFormId($ref, $hex, $manifest);
    }

    /** Resolves a stored plugin-local reference (a deferred one): plugin_missing when the plugin is no longer loaded. */
    public function resolvePlaced(string $plugin, string $localId, ?PluginManifest $manifest): Resolution
    {
        if ($manifest === null) {
            return Resolution::unresolved('manifest_missing', strtolower($plugin), $localId);
        }
        if (!$manifest->has($plugin)) {
            return Resolution::unresolved('plugin_missing', strtolower($plugin), $localId);
        }
        $actor = $this->actors->find($localId, $plugin);

        return $actor === null
            ? Resolution::unresolved('unknown_reference', strtolower($plugin), strtolower($localId))
            : Resolution::resolved($actor->getCharacterId(), $actor->getPlugin(), $actor->getRefId(), null);
    }

    /**
     * Validates, hashes and records a `session.plugin_manifest` message, and compares it with the playthrough's previous revision.
     *
     * @param array<string, mixed> $message manifest_sha256, plugins, runtime_variant, game_version
     * @return array{revision_id: int, manifest: PluginManifest, diff: array{added: list<string>, removed: list<string>, moved: list<string>}}
     * @throws InvalidArgumentException when the list is invalid or the hash the client sent differs from the recomputed one
     */
    public function acceptManifest(string $playthroughId, array $message): array
    {
        $variant = $message['runtime_variant'] ?? 'flat';
        $plugins = $message['plugins'] ?? null;
        if (!in_array($variant, ['flat', 'vr'], true) || !is_array($plugins) || !array_is_list($plugins)) {
            throw new InvalidArgumentException('The manifest needs a runtime variant of flat or vr and a plugin list.');
        }
        $manifest = new PluginManifest($plugins, $variant);
        if (!is_string($message['manifest_sha256'] ?? null) || !hash_equals($manifest->sha256(), strtolower($message['manifest_sha256']))) {
            throw new InvalidArgumentException('The manifest hash does not match the plugin list.');
        }
        $previous = $this->manifestOf($this->revisions->latest($playthroughId));
        $version = $message['game_version'] ?? null;
        $revision = $this->revisions->record($playthroughId, $manifest->sha256(), $manifest->toList(), $variant,
            is_string($version) && preg_match('/^[0-9.]{1,32}$/D', $version) === 1 ? $version : null);

        return ['revision_id' => $revision, 'manifest' => $manifest, 'diff' => $manifest->diff($previous)];
    }

    /** The manifest a request was sent under: the playthrough's latest revision, or null when none was sent yet. */
    public function currentManifest(string $playthroughId): ?PluginManifest
    {
        return $this->manifestOf($this->revisions->latest($playthroughId));
    }

    /** Whether a request's manifest hash differs from the playthrough's stored revision (answered `manifest_stale`). */
    public function isStale(string $playthroughId, string $sentSha256): bool
    {
        $latest = $this->revisions->latest($playthroughId);

        return $latest === null || !hash_equals($latest->getManifestSha256(), strtolower($sentSha256));
    }

    /** @param \Synth\Core\Entities\Base\AbstractPluginManifestRevision|null $revision */
    private function manifestOf(?object $revision): ?PluginManifest
    {
        if ($revision === null) {
            return null;
        }
        try {
            return new PluginManifest($revision->getPlugins(), $revision->getRuntimeVariant() === 'vr' ? 'vr' : 'flat');
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @param array<string, mixed> $ref */
    private function resolveFormId(array $ref, string $hex, ?PluginManifest $manifest): Resolution
    {
        if (str_starts_with($hex, 'ff')) {
            return Resolution::rejected('invalid_identity');
        }
        if ($hex === self::PLAYER_REFERENCE) {
            return Resolution::rejected('player_reference');
        }
        if ($manifest === null) {
            return Resolution::unresolved('manifest_missing');
        }
        $decoded = $manifest->decode($hex);
        if (!isset($decoded['plugin'])) {
            return Resolution::unresolved($decoded['reason']);
        }
        $claimed = $ref['origin_plugin'] ?? null;
        if ($claimed !== null && (!is_string($claimed) || strcasecmp($claimed, $decoded['plugin']) !== 0)) {
            return Resolution::rejected('plugin_mismatch');
        }

        return $this->resolvePlaced($decoded['plugin'], $decoded['local_id'], $manifest);
    }

    /** @param array<string, mixed> $ref */
    private function resolveRuntime(string $playthroughId, array $ref, string $hex): Resolution
    {
        $id = $ref['runtime_actor_id'] ?? null;
        if (!str_starts_with($hex, 'ff') || !is_string($id) || preg_match(self::UUID, strtolower($id)) !== 1 || !empty($ref['origin_plugin'])) {
            return Resolution::rejected('invalid_identity');
        }
        $id = strtolower($id);
        $actor = $this->runtimeActors->find($playthroughId, $id);
        if ($actor === null) {
            return Resolution::unresolved('runtime_actor_unregistered', null, null, $id);
        }
        if (in_array($actor->getStatus(), ['retired', 'replaced'], true)) {
            return Resolution::unresolved('runtime_actor_stale', null, null, $id);
        }

        return Resolution::resolved($actor->getCharacterId(), null, null, $id);
    }
}
