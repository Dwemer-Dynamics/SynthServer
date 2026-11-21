<?php

// Test doubles that let a test keep answering a DAO read from its own scripted fake `$db`. The double builds the legacy
// SQL text and asks the fake, so the fake's rows are what the DAO returns. Call reset() when the test is done.

use Synth\Core\DbManager\Dao\ContextEventDao;
use Synth\Core\DbManager\Dao\RequestExecutionDao;
use Synth\Core\DbManager\Dao\WorldknowledgeCatalogDao;
use Synth\Managers\ConfigManager\ConfigManager;

final class DaoTestDoubles
{
    /** RequestExecutionDao::ownerState() answered by $fake->fetchOne() with the legacy owner query. */
    public static function ownerFrom(object $fake): void
    {
        RequestExecutionDao::inject(new class($fake) extends RequestExecutionDao {
            public function __construct(private object $fake) {}

            public function ownerState(string $sessionId, string $requestId, int $generation): ?array
            {
                $session = $this->fake->escapeLiteral($sessionId);
                $request = $this->fake->escapeLiteral($requestId);
                $row = $this->fake->fetchOne("SELECT e.state, e.error_code, r.state AS runtime_state, r.active_generation,
            r.active_turn_id, r.active_turn_sequence
        FROM public.core_request_execution e JOIN public.core_runtime_session r USING (session_id)
        WHERE e.session_id={$session} AND e.request_id={$request} AND e.generation={$generation}");

                return is_array($row) && $row !== [] ? $row : null;
            }
        });
    }

    /**
     * The world-knowledge processor's three reads answered by the test's own fake: the newest chat text ($fake->fetchOne with
     * the legacy chat query), a catalog checksum and the 'conf' setting current_worldknowledge_topic.
     */
    public static function worldknowledgeFrom(object $fake): void
    {
        ContextEventDao::inject(new class($fake) extends ContextEventDao {
            public function __construct(private object $fake) {}

            public function latestChatData(): ?string
            {
                $row = $this->fake->fetchOne("SELECT data FROM core_context_event WHERE type='chat' ORDER BY gamets DESC LIMIT 1");

                return is_array($row) && isset($row['data']) ? (string) $row['data'] : null;
            }
        });
        WorldknowledgeCatalogDao::inject(new class($fake) extends WorldknowledgeCatalogDao {
            public function __construct(private object $fake) {}

            public function checksum(string $catalogId, string $catalogVersion): ?string
            {
                $row = $this->fake->fetchOne('SELECT checksum_sha256 FROM public.core_worldknowledge_catalog WHERE catalog_id='
                    .$this->fake->escapeLiteral($catalogId).' AND catalog_version='.$this->fake->escapeLiteral($catalogVersion).' LIMIT 1');

                return is_array($row) && isset($row['checksum_sha256']) ? (string) $row['checksum_sha256'] : null;
            }
        });
        ConfigManager::inject(new class($fake) extends ConfigManager {
            public function __construct(private object $fake) {}

            public function get(string $scope, string $key, ?string $default = null): ?string
            {
                $row = $this->fake->fetchOne("SELECT config_value AS value FROM public.core_config_setting WHERE scope = '{$scope}' AND config_key='{$key}' LIMIT 1");

                return is_array($row) && isset($row['value']) ? (string) $row['value'] : $default;
            }
        });
    }

    public static function resetWorldknowledge(): void
    {
        ContextEventDao::inject(null);
        WorldknowledgeCatalogDao::inject(null);
        ConfigManager::inject(null);
    }

    /**
     * The whole outcome path of RequestExecutionDao (owner check, record, lock, locked row, finish) answered by $fake, which sees
     * the legacy SQL text: fetchOne() for reads and the locked UPDATE ... RETURNING, execQuery() for the unlocked UPDATE.
     */
    public static function outcomeFrom(object $fake): void
    {
        RequestExecutionDao::inject(new class($fake) extends RequestExecutionDao {
            public function __construct(private object $fake) {}

            public function ownerState(string $sessionId, string $requestId, int $generation): ?array
            {
                $session = $this->fake->escapeLiteral($sessionId);
                $request = $this->fake->escapeLiteral($requestId);
                $row = $this->fake->fetchOne("SELECT e.state, e.error_code, r.state AS runtime_state, r.active_generation,
            r.active_turn_id, r.active_turn_sequence
        FROM public.core_request_execution e JOIN public.core_runtime_session r USING (session_id)
        WHERE e.session_id={$session} AND e.request_id={$request} AND e.generation={$generation}");

                return is_array($row) && $row !== [] ? $row : null;
            }

            public function recordOutcome(string $sessionId, string $requestId, string $state, string $contentType, string $body, string $errorCode): bool
            {
                $f = $this->fake;
                $f->execQuery("UPDATE public.core_request_execution
            SET state={$f->escapeLiteral($state)}, response_content_type={$f->escapeLiteral($contentType)},
                response_body={$f->escapeLiteral('\\x' . bin2hex($body))}::bytea, error_code=" . ($errorCode === '' ? 'NULL' : $f->escapeLiteral($errorCode)) . ", completed_at=NOW()
          WHERE session_id={$f->escapeLiteral($sessionId)} AND request_id={$f->escapeLiteral($requestId)}
            AND state IN ('claimed','streaming')");

                return true;
            }

            public function lockSessionForOutcome(string $sessionId): bool
            {
                return (bool) $this->fake->fetchOne("SELECT pg_advisory_xact_lock(hashtext({$this->fake->escapeLiteral($sessionId)}))");
            }

            public function lockOutcomeRow(string $sessionId, string $requestId, int $generation): ?array
            {
                $f = $this->fake;
                $row = $f->fetchOne("SELECT state, error_code FROM public.core_request_execution
            WHERE session_id={$f->escapeLiteral($sessionId)} AND request_id={$f->escapeLiteral($requestId)} AND generation={$generation} FOR UPDATE");

                return is_array($row) ? $row : null;
            }

            public function finishOutcome(string $sessionId, string $requestId, int $generation, string $state, string $contentType, ?string $bodyHex, string $errorCode): ?string
            {
                $f = $this->fake;
                $bodySql = $bodyHex === null ? 'NULL' : $f->escapeLiteral('\\x' . $bodyHex) . '::bytea';
                $updated = $f->fetchOne("UPDATE public.core_request_execution
            SET state={$f->escapeLiteral($state)}, response_content_type={$f->escapeLiteral($contentType)},
                response_body={$bodySql}, error_code=" . ($errorCode === '' ? 'NULL' : $f->escapeLiteral($errorCode)) . ", completed_at=NOW()
          WHERE session_id={$f->escapeLiteral($sessionId)} AND request_id={$f->escapeLiteral($requestId)} AND generation={$generation}
            AND state IN ('claimed','streaming') RETURNING state");
                return is_array($updated) ? ($updated['state'] ?? null) : null;
            }
        });
    }

    public static function reset(): void
    {
        RequestExecutionDao::inject(null);
    }
}
