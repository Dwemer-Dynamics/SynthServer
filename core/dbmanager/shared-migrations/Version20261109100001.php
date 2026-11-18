<?php

// Doctrine migration for the shared dwemer database: adds plain-text secret storage to api_keys.

declare(strict_types=1);

namespace ChimServer\SharedMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Global API keys are stored as plain text by explicit operator decision. This migration adds the nullable secret
 * column and replaces the storage-shape check so an active row holds exactly one form: a plain-text secret with no
 * nonce or ciphertext, or a legacy sealed value (24-byte nonce and ciphertext) with no secret. A deleted tombstone
 * holds none of them. Schema only: no row is converted. Existing sealed rows stay sealed and readable with the
 * installation key; a row becomes plain text only when an edit replaces its secret.
 * The runtime role needs UPDATE (secret) granted separately (docs/s19-api-keys.md).
 */
final class Version20261109100001 extends AbstractMigration {
    /** Summary shown by the Doctrine Migrations status and list commands. */
    public function getDescription(): string {
        return 'Store global API key secrets as plain text, keeping legacy sealed rows readable';
    }

    /** Column addition and constraint replacement commit together or not at all. */
    public function isTransactional(): bool {
        return true;
    }

    /** Adds the secret column and the two-form storage check; existing rows satisfy it unchanged. */
    public function up(Schema $schema): void {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL only');
        $this->addSql('ALTER TABLE api_keys ADD COLUMN IF NOT EXISTS secret text NULL');
        $this->addSql('ALTER TABLE api_keys DROP CONSTRAINT IF EXISTS api_keys_status_shape');
        // Same secret rule as ApiKeyManager::SECRET_PATTERN: 1-4096 printable ASCII characters without spaces.
        $this->addSql(<<<'SQL'
            ALTER TABLE api_keys ADD CONSTRAINT api_keys_status_shape CHECK (
                (status = 'active' AND deleted_at IS NULL AND (
                    (secret IS NOT NULL AND nonce IS NULL AND ciphertext IS NULL
                        AND char_length(secret) BETWEEN 1 AND 4096 AND secret ~ '^[\x21-\x7e]+$')
                 OR (secret IS NULL AND nonce IS NOT NULL AND ciphertext IS NOT NULL
                        AND octet_length(nonce) = 24 AND octet_length(ciphertext) BETWEEN 17 AND 4112)))
             OR (status = 'deleted' AND deleted_at IS NOT NULL AND secret IS NULL AND nonce IS NULL AND ciphertext IS NULL)
            )
            SQL);
    }

    /** Always refuses: dropping the column would delete plain-text keys entered after the upgrade. */
    public function down(Schema $schema): void {
        $this->throwIrreversibleMigrationException('api_keys.secret holds operator-entered credentials; restore from backup instead');
    }
}
