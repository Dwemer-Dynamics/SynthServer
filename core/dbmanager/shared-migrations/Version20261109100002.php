<?php

// Doctrine migration for the shared dwemer database: allows AtlasCloud keys in the global api_keys table.

declare(strict_types=1);

namespace ChimServer\SharedMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the provider `atlascloud`, pinned to its one fixed origin https://api.atlascloud.ai:443, to the two provider
 * checks of api_keys. Metadata only: every other provider and origin rule is repeated unchanged, so existing rows
 * (including custom_openai keys) satisfy the new checks and no key ID, secret, revision or timestamp is touched.
 */
final class Version20261109100002 extends AbstractMigration {
    /** Summary shown by the Doctrine Migrations status and list commands. */
    public function getDescription(): string {
        return 'Allow AtlasCloud keys in the global API key table';
    }

    /** Both constraints are replaced together or not at all. */
    public function isTransactional(): bool {
        return true;
    }

    /** Replaces the provider list and the provider-origin pairing with the same rules plus atlascloud. */
    public function up(Schema $schema): void {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL only');
        $this->addSql('ALTER TABLE api_keys DROP CONSTRAINT IF EXISTS api_keys_provider_check');
        $this->addSql(<<<'SQL'
            ALTER TABLE api_keys ADD CONSTRAINT api_keys_provider_check CHECK (provider IN ('openrouter','openai','inworld','deepgram','google','azure','elevenlabs','replicate','cartesia','nano_gpt','deepl','groq','ploofy','atlascloud','custom_openai'))
            SQL);
        $this->addSql('ALTER TABLE api_keys DROP CONSTRAINT IF EXISTS api_keys_provider_origin');
        $this->addSql(<<<'SQL'
            ALTER TABLE api_keys ADD CONSTRAINT api_keys_provider_origin CHECK ((provider='openrouter' AND origin='https://openrouter.ai:443') OR (provider='openai' AND origin='https://api.openai.com:443') OR (provider='inworld' AND origin='https://api.inworld.ai:443') OR (provider='deepgram' AND origin='https://api.deepgram.com:443') OR (provider='google' AND origin='https://generativelanguage.googleapis.com:443') OR (provider='elevenlabs' AND origin='https://api.elevenlabs.io:443') OR (provider='cartesia' AND origin='https://api.cartesia.ai:443') OR (provider='nano_gpt' AND origin='https://nano-gpt.com:443') OR (provider='groq' AND origin='https://api.groq.com:443') OR (provider='atlascloud' AND origin='https://api.atlascloud.ai:443') OR (provider IN ('azure','replicate','deepl','ploofy','custom_openai') AND (origin LIKE 'https://%' OR origin ~ '^http://(127\.0\.0\.1|localhost|\[::1\]):')))
            SQL);
    }

    /** Always refuses: AtlasCloud keys entered after the upgrade would violate the older checks. */
    public function down(Schema $schema): void {
        $this->throwIrreversibleMigrationException('AtlasCloud keys may be in use; preserve their credentials');
    }
}
