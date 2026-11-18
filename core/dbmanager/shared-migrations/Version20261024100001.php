<?php

// Extend the existing encrypted global table to the provider names used by DwemerDistro.
declare(strict_types=1);
namespace ChimServer\SharedMigrations;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024100001 extends AbstractMigration {
    /** Explain the metadata-only schema upgrade. */
    public function getDescription(): string {
        return 'Allow DwemerDistro providers in the existing global API key table';
    }
    /** Keep constraint replacement atomic. */
    public function isTransactional(): bool {
        return true;
    }
    /** Extend provider checks without modifying IDs, sealed values or assignments. */
    public function up(Schema $schema): void {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL only');
        $this->addSql(<<<'SQL'
            ALTER TABLE api_keys DROP CONSTRAINT IF EXISTS api_keys_provider_check
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE api_keys ADD CONSTRAINT api_keys_provider_check CHECK (provider IN ('openrouter','openai','inworld','deepgram','google','azure','elevenlabs','replicate','cartesia','nano_gpt','deepl','groq','ploofy','custom_openai'))
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE api_keys DROP CONSTRAINT IF EXISTS api_keys_provider_origin
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE api_keys ADD CONSTRAINT api_keys_provider_origin CHECK ((provider='openrouter' AND origin='https://openrouter.ai:443') OR (provider='openai' AND origin='https://api.openai.com:443') OR (provider='inworld' AND origin='https://api.inworld.ai:443') OR (provider='deepgram' AND origin='https://api.deepgram.com:443') OR (provider='google' AND origin='https://generativelanguage.googleapis.com:443') OR (provider='elevenlabs' AND origin='https://api.elevenlabs.io:443') OR (provider='cartesia' AND origin='https://api.cartesia.ai:443') OR (provider='nano_gpt' AND origin='https://nano-gpt.com:443') OR (provider='groq' AND origin='https://api.groq.com:443') OR (provider IN ('azure','replicate','deepl','ploofy','custom_openai') AND (origin LIKE 'https://%' OR origin ~ '^http://(127\.0\.0\.1|localhost|\[::1\]):')))
            SQL);
    }
    /** A downgrade could invalidate newly entered providers, so retain the schema and data. */
    public function down(Schema $schema): void {
        $this->throwIrreversibleMigrationException('Global API key providers may be in use; preserve their credentials');
    }
}
