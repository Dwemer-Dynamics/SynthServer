<?php

declare(strict_types=1);

namespace Synth\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

// Global API keys with SYNTH-only custom keys (docs/SCHEMA-MIGRATIONS.md, "Shared API keys"). Provider keys are global by
// default: they live in the shared dwemer database's api_keys table (the CHIM 4 server's store). synth.core_api_badge stays
// as the SYNTH-only key table (id, label, api_key, UNIQUE label), for any provider a user wants a key for just in SYNTH.
// A connector names one key in one store, never both: api_key_id (uuid, a shared key; no foreign key, the key is in another
// database) or local_api_key_id (the old api_badge_id column renamed, still a foreign key to core_api_badge). Both null
// means "no key chosen": the connector then uses the global key of its provider. The legacy chain's schema files already
// build this shape, so every statement here is idempotent.
// The seed rows of core_api_badge carry empty keys and the seeded connectors point at them; those references are cleared and
// the empty rows removed, so the SYNTH-only list starts with only what a user entered. Keys the user entered are kept.
// The public view public.synth_key_references (id, kind, label, shared_key_id) shows which shared keys SYNTH connectors use, for
// DwemerDistro's shared-key bridge (see the docs for the paired Distro change).
// Uses addSql() only, like every migration here.
final class Version20261116100000 extends AbstractMigration
{
    private const CONNECTORS = ['core_llm_connector', 'core_tts_connector', 'core_stt_connector', 'core_itt_connector'];

    public function getDescription(): string
    {
        return 'Add shared key references (api_key_id uuid) beside SYNTH-only keys (local_api_key_id) on the connectors';
    }

    public function isTransactional(): bool
    {
        return true;
    }

    /** The statements of up(), for the unit fixture that builds its schema with the legacy chain. */
    public static function statements(): array
    {
        $sql = [];
        foreach (self::CONNECTORS as $table) {
            $sql[] = "DO \$do\$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'synth' AND table_name = '{$table}'"
                . " AND column_name = 'api_badge_id') THEN ALTER TABLE synth.{$table} RENAME COLUMN api_badge_id TO local_api_key_id; END IF; END \$do\$";
            $sql[] = "ALTER TABLE synth.{$table} ADD COLUMN IF NOT EXISTS api_key_id uuid NULL";
            $sql[] = "DO \$do\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = '{$table}_one_key_store') THEN ALTER TABLE synth.{$table}"
                . " ADD CONSTRAINT {$table}_one_key_store CHECK (api_key_id IS NULL OR local_api_key_id IS NULL); END IF; END \$do\$";
            $sql[] = "UPDATE synth.{$table} SET local_api_key_id = NULL WHERE local_api_key_id IN (SELECT id FROM synth.core_api_badge WHERE btrim(api_key) = '')";
        }
        $sql[] = "DELETE FROM synth.core_api_badge WHERE btrim(api_key) = ''";
        // Which shared keys SYNTH connectors use, for DwemerDistro's shared-key bridge: one row per referencing connector.
        $sql[] = "CREATE OR REPLACE VIEW public.synth_key_references AS"
            . " SELECT id, 'model'::text AS kind, coalesce(nullif(label, ''), model) AS label, api_key_id AS shared_key_id FROM synth.core_llm_connector WHERE api_key_id IS NOT NULL"
            . " UNION ALL SELECT id, 'voice', coalesce(nullif(label, ''), driver), api_key_id FROM synth.core_tts_connector WHERE api_key_id IS NOT NULL"
            . " UNION ALL SELECT id, 'stt', coalesce(nullif(label, ''), driver), api_key_id FROM synth.core_stt_connector WHERE api_key_id IS NOT NULL"
            . " UNION ALL SELECT id, 'itt', coalesce(nullif(label, ''), driver), api_key_id FROM synth.core_itt_connector WHERE api_key_id IS NOT NULL";
        return $sql;
    }

    public function up(Schema $schema): void
    {
        foreach (self::statements() as $statement) {
            $this->addSql($statement);
        }
    }

    // The owner's revertTo() runs this and, as for the baseline, only the ledger record is removed; the schema stays.
    public function down(Schema $schema): void
    {
    }
}
