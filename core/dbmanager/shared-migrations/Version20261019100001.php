<?php

// Doctrine migration for the shared dwemer database: creates the api_keys table (see the class comment below).

declare(strict_types=1);

namespace ChimServer\SharedMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S19: shared API-key storage. This migration belongs to the separate shared dwemer database and its own version table;
 * it never touches the CHIM runtime database. Manually authored PostgreSQL, no ORM mapping.
 *
 * One table, api_keys: a stable UUID, an explicit provider, a free display label (duplicates allowed), the one exact
 * origin the key may be sent to, the encrypted secret with its nonce, a status, a revision and timestamps. Encryption
 * happens in Core/ApiKeyManager; the database only ever sees the nonce and the authenticated ciphertext. A deleted key
 * is a tombstone: its nonce and ciphertext are wiped and only the metadata stays, so a later lookup reports it revoked.
 * The provider and origin cannot change after creation (the runtime role is granted UPDATE on the editable columns
 * only). Every statement is guarded, so a rerun on an upgraded schema is a no-op.
 */
final class Version20261019100001 extends AbstractMigration {
    /** Display label: 1-64 code points, no leading/trailing whitespace, no control characters (same rule as S17). */
    private const LABEL_CHECK = "char_length(label) BETWEEN 1 AND 64 AND label = btrim(label) AND label !~ '[[:cntrl:]]'";

    /** Summary shown by the Doctrine Migrations status and list commands. */
    public function getDescription(): string {
        return 'Create the shared api_keys table';
    }

    /** Runs inside a transaction; PostgreSQL DDL is transactional, so a failure leaves the schema unchanged. */
    public function isTransactional(): bool {
        return true;
    }

    /**
     * Creates api_keys and its active-list index. The CHECK constraints repeat the Core rules that matter for safety:
     * known providers are pinned to their fixed origin, plain http is allowed only to a loopback custom origin, and an
     * active row always holds a non-NULL 24-byte nonce and ciphertext while a deleted row holds neither. A trigger keeps
     * a deleted row from ever being updated again, so a tombstone cannot become an active key.
     */
    public function up(Schema $schema): void {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL only');

        $label = self::LABEL_CHECK;
        $this->addSql(<<<SQL
            CREATE TABLE IF NOT EXISTS api_keys (
                key_id     uuid         NOT NULL PRIMARY KEY,
                provider   varchar(32)  NOT NULL CHECK (provider IN ('openrouter', 'openai', 'inworld', 'custom_openai')),
                label      varchar(64)  NOT NULL CHECK ({$label}),
                origin     varchar(300) NOT NULL CHECK (origin ~ '^https?://([a-z0-9-]+(\\.[a-z0-9-]+)*|\\[[0-9a-f:.]+\\]):[0-9]{1,5}$'),
                status     varchar(8)   NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'deleted')),
                nonce      bytea        NULL,
                ciphertext bytea        NULL,
                revision   bigint       NOT NULL DEFAULT 1 CHECK (revision >= 1),
                created_at timestamptz  NOT NULL DEFAULT clock_timestamp(),
                updated_at timestamptz  NOT NULL DEFAULT clock_timestamp(),
                deleted_at timestamptz  NULL,
                CONSTRAINT api_keys_provider_origin CHECK (
                    (provider = 'openrouter' AND origin = 'https://openrouter.ai:443')
                 OR (provider = 'openai' AND origin = 'https://api.openai.com:443')
                 OR (provider = 'inworld' AND origin = 'https://api.inworld.ai:443')
                 OR (provider = 'custom_openai' AND (origin LIKE 'https://%' OR origin ~ '^http://(127\\.0\\.0\\.1|localhost|\\[::1\\]):'))
                ),
                CONSTRAINT api_keys_status_shape CHECK (
                    (status = 'active' AND deleted_at IS NULL AND nonce IS NOT NULL AND ciphertext IS NOT NULL
                        AND octet_length(nonce) = 24 AND octet_length(ciphertext) BETWEEN 17 AND 4112)
                 OR (status = 'deleted' AND deleted_at IS NOT NULL AND nonce IS NULL AND ciphertext IS NULL)
                )
            )
            SQL);
        $this->addSql("CREATE INDEX IF NOT EXISTS api_keys_active_idx ON api_keys (created_at, key_id) WHERE status = 'active'");
        // A tombstone is final: the runtime role may UPDATE status, nonce and ciphertext, so without this trigger it could
        // turn a deleted row back into an active key with any well-formed ciphertext. The trigger refuses every UPDATE of
        // a deleted row whatever the role; only the table owner could drop it.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION api_keys_tombstone_final() RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog AS $fn$
            BEGIN
                IF OLD.status = 'deleted' THEN
                    RAISE EXCEPTION 'api_keys tombstone is final' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END
            $fn$
            SQL);
        $this->addSql('REVOKE ALL ON FUNCTION api_keys_tombstone_final() FROM PUBLIC');
        $this->addSql(<<<'SQL'
            DO $do$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'api_keys_tombstone_final' AND tgrelid = 'api_keys'::regclass) THEN
                    CREATE TRIGGER api_keys_tombstone_final BEFORE UPDATE ON api_keys
                        FOR EACH ROW EXECUTE FUNCTION api_keys_tombstone_final();
                END IF;
            END
            $do$
            SQL);
    }

    /** Always refuses: reverting would delete operator-entered API keys. */
    public function down(Schema $schema): void {
        // Dropping the table would destroy every stored key for every product; restore from backup instead.
        $this->throwIrreversibleMigrationException('api_keys holds operator-entered credentials; restore from backup instead');
    }
}
