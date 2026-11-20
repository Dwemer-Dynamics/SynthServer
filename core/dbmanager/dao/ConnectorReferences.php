<?php

// Which SYNTH connectors use a given API key. It guards deletes (a key a connector uses is never deleted) and is the callback
// behind the public views DwemerDistro's shared-key bridge reads, so ChimServer refuses to delete a shared key SYNTH uses.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Doctrine\DBAL\Connection;
use Synth\Core\Contracts\ApiKeyRef;
use Synth\Core\Doctrine\EntityManagerFactory;

require_once dirname(__DIR__).'/EntityManagerFactory.php';

/** Reads the four connector tables for references to one API key. Read-only. */
final class ConnectorReferences
{
    /** The tables that carry a key reference, with the column of each store. */
    private const TABLES = ['core_llm_connector', 'core_tts_connector', 'core_stt_connector', 'core_itt_connector'];

    private ?Connection $connection = null;

    /** Uses the legacy sql wrapper's session, like every other SYNTH repository. */
    public function __construct(private readonly object $db)
    {
    }

    /**
     * The connectors that use $ref: a list of ['table' => ..., 'id' => ..., 'label' => ...]. A failed read throws, so a caller
     * deciding on a delete treats the doubt as "in use".
     *
     * @return list<array{table:string, id:int, label:string}>
     */
    public function usedBy(ApiKeyRef $ref): array
    {
        $column = $ref->isShared() ? 'api_key_id = ?::uuid' : 'local_api_key_id = ?::integer';
        $used = [];
        foreach (self::TABLES as $table) {
            $rows = $this->connection()->executeQuery("SELECT id, coalesce(nullif(label, ''), driver) AS label FROM public.{$table} WHERE {$column} ORDER BY id LIMIT 129", [$ref->id()])
                ->fetchAllAssociative();
            foreach ($rows as $row) {
                $used[] = ['table' => $table, 'id' => (int) $row['id'], 'label' => (string) $row['label']];
            }
        }
        return $used;
    }

    /**
     * The connectors that use the shared key $uuid, the callback form the CHIM side asks for: the same rows the public views
     * (public.core_llm_connector, core_tts_connector and core_api_badge for STT/ITT) show to DwemerDistro's bridge.
     *
     * @return list<array{table:string, id:int, label:string}>
     */
    public function forSharedKey(string $uuid): array
    {
        $ref = ApiKeyRef::shared($uuid);
        return $ref === null ? [] : $this->usedBy($ref);
    }

    /** True when any connector uses $ref; a failed read counts as "yes". */
    public function isUsed(ApiKeyRef $ref): bool
    {
        try {
            return $this->usedBy($ref) !== [];
        } catch (\Doctrine\DBAL\Exception|\Doctrine\DBAL\Driver\Exception $error) {
            return true;
        }
    }

    /** The DBAL connection over the legacy session. */
    private function connection(): Connection
    {
        return $this->connection ??= EntityManagerFactory::forSharedSession($this->db)->getConnection();
    }
}
