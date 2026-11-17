<?php

declare(strict_types=1);

namespace Synth\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

// Shared API keys (docs/SCHEMA-MIGRATIONS.md, "Shared API keys"): SYNTH keeps no key table. Provider keys live in the shared
// dwemer database's api_keys table (the CHIM 4 server's store) and a connector references one by its key_id, the way
// ChimServer's connections do. This migration removes synth.core_api_badge and replaces each connector's api_badge_id
// (integer, foreign key) with api_key_id (uuid, no foreign key: the key is in another database). There is no upgrade path
// for stored keys: it refuses to run while the old table still holds a key, so no credential is dropped silently. The seed
// rows carry empty keys, so a fresh install always passes. Uses addSql() only, like every migration here.
final class Version20261116100000 extends AbstractMigration
{
    private const CONNECTORS = ['core_llm_connector', 'core_tts_connector', 'core_stt_connector', 'core_itt_connector'];

    public function getDescription(): string
    {
        return 'Replace core_api_badge and connector api_badge_id with shared key references (api_key_id uuid)';
    }

    public function isTransactional(): bool
    {
        return true;
    }

    public function preUp(Schema $schema): void
    {
        // A database the legacy chain built already has the new schema and no old table.
        if ($this->connection->fetchOne("SELECT to_regclass('synth.core_api_badge')") === null) {
            return;
        }
        $held = $this->connection->fetchOne("SELECT count(*) FROM synth.core_api_badge WHERE btrim(api_key) <> ''");
        $this->abortIf((int) $held > 0, 'synth.core_api_badge still holds API keys. This version keeps keys only in the shared dwemer store: '
            . 'write them down or re-enter them there, then clear them here.');
    }

    public function up(Schema $schema): void
    {
        foreach (self::CONNECTORS as $table) {
            $this->addSql("ALTER TABLE synth.{$table} DROP COLUMN IF EXISTS api_badge_id");
            $this->addSql("ALTER TABLE synth.{$table} ADD COLUMN IF NOT EXISTS api_key_id uuid NULL");
        }
        $this->addSql('DROP TABLE IF EXISTS synth.core_api_badge');
        $this->addSql("DELETE FROM synth.database_versioning WHERE tablename = 'core_api_badge'");
    }

    // The owner's revertTo() runs this and, as for the baseline, only the ledger record is removed; the schema stays. The removed
    // key table is not recreated: keys were never moved out of the shared store, and a connector's reference cannot be rebuilt.
    public function down(Schema $schema): void
    {
    }
}
