<?php

declare(strict_types=1);

namespace Synth\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

// Shared API keys, SYNTH side (docs/SCHEMA-MIGRATIONS.md, "Shared API keys"): the local binding column. core_api_badge
// keeps its ids, labels and api_key values, so no connector row (api_badge_id) is rewritten; shared_key_id optionally
// points a badge at a key of the shared `dwemer` database's api_keys table. The column is a plain nullable uuid: there is
// no foreign key (another database) and no default, so every existing row stays unbound until the explicit import
// (tools/shared-api-keys.php) or a save binds it. Schema only: no row is read or changed, no connection to another
// database is made. Uses addSql() only, like every migration here.
final class Version20261116100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the nullable shared API key binding column to synth.core_api_badge';
    }

    public function isTransactional(): bool
    {
        return true;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE synth.core_api_badge ADD COLUMN IF NOT EXISTS shared_key_id uuid NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS core_api_badge_shared_key_idx ON synth.core_api_badge (shared_key_id) WHERE shared_key_id IS NOT NULL');
    }

    // Removes only the binding. api_key keeps a mirror of every bound value, so a rolled-back install still has its keys;
    // shared keys are never touched. Re-importing adopts them again by provider and value.
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS synth.core_api_badge_shared_key_idx');
        $this->addSql('ALTER TABLE synth.core_api_badge DROP COLUMN IF EXISTS shared_key_id');
    }
}
