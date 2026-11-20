<?php

declare(strict_types=1);

namespace Synth\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

// The extension host's two shared tables (chevo's core_extension_store and core_extension_migrations):
// core_extension_store is the key/value store every extension gets through its context, and core_extension_migration
// records the SQL files of an extension's own ext_<id>_* tables that were applied, with their SHA-256, so a changed
// file is detected instead of re-run. The extension tables themselves are created by those files, not here.
// Uses addSql() only, like every migration here, and every statement is idempotent.
final class Version20261120110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add core_extension_store and core_extension_migration for extension data and their per-extension migrations';
    }

    public function isTransactional(): bool
    {
        return true;
    }

    /** The statements of up(). */
    public static function statements(): array
    {
        return [
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS public.core_extension_store (
                extension_id text NOT NULL,
                store_key text NOT NULL,
                value jsonb NOT NULL,
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT core_extension_store_pkey PRIMARY KEY (extension_id, store_key),
                CONSTRAINT core_extension_store_id_check CHECK (extension_id ~ '^[a-z][a-z0-9]*(-[a-z0-9]+)*$' AND length(extension_id) <= 64),
                CONSTRAINT core_extension_store_key_check CHECK (length(store_key) BETWEEN 1 AND 200)
            )
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS public.core_extension_migration (
                extension_id text NOT NULL,
                name text NOT NULL,
                sha256 char(64) NOT NULL,
                applied_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT core_extension_migration_pkey PRIMARY KEY (extension_id, name),
                CONSTRAINT core_extension_migration_id_check CHECK (extension_id ~ '^[a-z][a-z0-9]*(-[a-z0-9]+)*$' AND length(extension_id) <= 64),
                CONSTRAINT core_extension_migration_sha_check CHECK (sha256 ~ '^[0-9a-f]{64}$')
            )
            SQL,
        ];
    }

    public function up(Schema $schema): void
    {
        foreach (self::statements() as $statement) {
            $this->addSql($statement);
        }
    }

    // As for the other migrations here, revertTo() removes only the ledger record; the tables stay.
    public function down(Schema $schema): void
    {
    }
}
