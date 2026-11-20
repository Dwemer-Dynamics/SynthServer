<?php

// Reads and appends core_plugin_manifest_revision: the load-order manifests a playthrough was played under. Revisions are
// never changed; recording the same manifest twice returns the existing revision.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use InvalidArgumentException;
use Synth\Core\Entities\Base\AbstractPluginManifestRevision;


/** Records and reads manifest revisions. */
final class PluginManifestRevisionDao
{
    use UsesEntityManager;

    /**
     * Records a manifest for a playthrough and returns its revision id (the existing one when already recorded).
     *
     * @param list<array<string, mixed>> $plugins the ordered plugin list as sent
     */
    public function record(string $playthroughId, string $manifestSha256, array $plugins, ?string $runtimeVariant = null, ?string $gameVersion = null): int
    {
        $sha = strtolower($manifestSha256);
        if ($playthroughId === '' || preg_match('/^[0-9a-f]{64}$/D', $sha) !== 1) {
            throw new InvalidArgumentException('A manifest revision needs a playthrough and a 64 hex SHA-256.');
        }
        $connection = $this->connection();
        $connection->executeStatement(
            'INSERT INTO public.core_plugin_manifest_revision (playthrough_id, manifest_sha256, plugins, runtime_variant, game_version)
             VALUES (:playthrough_id, :sha, CAST(:plugins AS jsonb), :variant, :version)
             ON CONFLICT (playthrough_id, manifest_sha256) DO NOTHING',
            ['playthrough_id' => $playthroughId, 'sha' => $sha, 'plugins' => json_encode(array_values($plugins), JSON_THROW_ON_ERROR),
                'variant' => $runtimeVariant, 'version' => $gameVersion]
        );

        return (int) $connection->fetchOne(
            'SELECT id FROM public.core_plugin_manifest_revision WHERE playthrough_id = :playthrough_id AND manifest_sha256 = :sha',
            ['playthrough_id' => $playthroughId, 'sha' => $sha]
        );
    }

    /** One revision by id, or null. */
    public function find(int $id): ?AbstractPluginManifestRevision
    {
        return $this->fresh()->find(AbstractPluginManifestRevision::class, $id);
    }

    /** The newest revision recorded for a playthrough, or null. */
    public function latest(string $playthroughId): ?AbstractPluginManifestRevision
    {
        return $this->fresh()->getRepository(AbstractPluginManifestRevision::class)
            ->findOneBy(['playthroughId' => $playthroughId], ['id' => 'DESC']);
    }
}
