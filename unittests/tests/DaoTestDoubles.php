<?php

// Test doubles that let a test keep answering a DAO read from its own scripted fake `$db`. The double builds the legacy
// SQL text and asks the fake, so the fake's rows are what the DAO returns. Call reset() when the test is done.

use Synth\Core\DbManager\Dao\ActionIntentDao;
use Synth\Core\DbManager\Dao\ItemDescriptionDao;
use Synth\Core\DbManager\Dao\PluginsDao;
use Synth\Core\DbManager\Dao\ActorActivationDao;
use Synth\Core\DbManager\Dao\ActorIdentityDao;
use Synth\Core\DbManager\Dao\RuntimeContextDao;
use Synth\Core\DbManager\Dao\ContextEventDao;
use Synth\Core\DbManager\Dao\ProtocolEventDao;
use Synth\Core\DbManager\Dao\RequestExecutionDao;
use Synth\Core\DbManager\Dao\RuntimeSessionDao;
use Synth\Core\DbManager\Dao\WorkerJobDao;
use Synth\Core\DbManager\Dao\WorldknowledgeCatalogDao;
use Synth\Managers\ConfigManager\ConfigManager;
use Synth\Core\DbManager\Dao\HistoryEventDao;
use Synth\Core\DbManager\Dao\NpcDao;
use Synth\Core\DbManager\Dao\NpcBiographyTemplateDao;
use Synth\Core\DbManager\Dao\WorldknowledgeArticleDao;
use Synth\Core\DbManager\Dao\WorldknowledgeAuditDao;

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

    /** DiaryResultDao::latestVisible() answered by $fake->fetchOne() with the legacy visible-diary query text. */
    public static function diaryLatestFrom(object $fake): void
    {
        \Synth\Core\DbManager\Dao\DiaryResultDao::inject(new class($fake) extends \Synth\Core\DbManager\Dao\DiaryResultDao {
            public function __construct(private object $fake) {}

            public function latestVisible(string $diaryCte, bool $automaticOnly): ?array
            {
                $row = $this->fake->fetchOne($diaryCte.' SELECT history_event_id,result_payload,published_at,'.($automaticOnly ? '0' : 'content_revision').' AS content_revision FROM '
                    .($automaticOnly ? 'diary_chain' : 'native_diaries').($automaticOnly ? " WHERE reason IN ('sleep','wait')" : '').' ORDER BY history_event_id DESC LIMIT 1');

                return is_array($row) && $row !== [] ? $row : null;
            }
        });
    }

    /**
     * Records the claim-transaction DAO calls of synth_protocol_claim_request instead of running them: every write appends
     * [method, arguments] to $calls. $script scripts the reads: 'lockForClaim' (the session row, or null), 'findAnchor' and
     * 'existing' (the request row, or null).
     *
     * @param array<int, array{0: string, 1: array<int, mixed>}> $calls
     * @param array<string, mixed> $script
     */
    public static function claimRecorder(array &$calls, array $script = []): void
    {
        $log = new class($calls) {
            public function __construct(public array &$calls) {}
            public function __invoke(string $name, array $args): bool { $this->calls[] = [$name, $args]; return true; }
        };
        RuntimeSessionDao::inject(new class($log, $script) extends RuntimeSessionDao {
            public function __construct(private object $log, private array $script) {}
            public function lockForClaim(string $sessionId): ?array { ($this->log)(__FUNCTION__, func_get_args()); return $this->script['lockForClaim'] ?? null; }
            public function supportsAudienceReset(): bool { return false; }
            public function upsertInit(string $sessionId, string $playthroughId, string $runtimeVariant, string $clientVersion, string $runtimeVersion, int $generation, string $capabilitiesJson, bool $resetAudience): ?array { ($this->log)(__FUNCTION__, func_get_args()); return []; }
            public function adoptTurn(string $sessionId, int $generation, string $turnId, int $sequence): bool { return ($this->log)(__FUNCTION__, func_get_args()); }
            public function clearTurn(string $sessionId, int $generation, string $turnId, int $sequence): bool { return ($this->log)(__FUNCTION__, func_get_args()); }
            public function halt(string $sessionId): bool { return ($this->log)(__FUNCTION__, func_get_args()); }
        });
        RequestExecutionDao::inject(new class($log, $script) extends RequestExecutionDao {
            public function __construct(private object $log, private array $script) {}
            public function lockSession(string $sessionId): bool { return ($this->log)(__FUNCTION__, func_get_args()); }
            public function lockForClaim(string $sessionId, string $requestId): ?array { ($this->log)(__FUNCTION__, func_get_args()); return $this->script['existing'] ?? null; }
            public function insertClaim(string $sessionId, string $requestId, string $turnId, int $generation, string $requestSha256, string $eventType): bool { return ($this->log)(__FUNCTION__, func_get_args()); }
            public function cancelOlderGenerations(string $sessionId, int $generation): bool { return ($this->log)(__FUNCTION__, func_get_args()); }
            public function cancelSupersededTurn(string $sessionId, int $generation, string $turnId, int $sequence): bool { return ($this->log)(__FUNCTION__, func_get_args()); }
            public function cancelForHalt(string $sessionId, int $generation, string $haltRequestId): bool { return ($this->log)(__FUNCTION__, func_get_args()); }
            public function hasCancelledContext(string $sessionId, int $generation, int $sequence): bool { ($this->log)(__FUNCTION__, func_get_args()); return false; }
            public function cancelTurn(string $sessionId, int $generation, string $cancelRequestId, string $cancelTurnId, int $cancelSequence): bool { return ($this->log)(__FUNCTION__, func_get_args()); }
            public function cancelRequest(string $sessionId, int $generation, string $cancelRequestId): bool { return ($this->log)(__FUNCTION__, func_get_args()); }
        });
        WorkerJobDao::inject(new class($log) extends WorkerJobDao {
            public function __construct(private object $log) {}
            public function cancelOlderGenerations(string $sessionId, int $generation): bool { return ($this->log)(__FUNCTION__, func_get_args()); }
            public function cancelSupersededTurn(string $sessionId, int $generation, string $turnId, int $sequence): bool { return ($this->log)(__FUNCTION__, func_get_args()); }
            public function cancelForHalt(string $sessionId, int $generation): bool { return ($this->log)(__FUNCTION__, func_get_args()); }
            public function cancelTurn(string $sessionId, int $generation, string $turnId, int $sequence): bool { return ($this->log)(__FUNCTION__, func_get_args()); }
            public function cancelRequest(string $sessionId, int $generation, string $requestId): bool { return ($this->log)(__FUNCTION__, func_get_args()); }
        });
        ProtocolEventDao::inject(new class($log, $script) extends ProtocolEventDao {
            public function __construct(private object $log, private array $script) {}
            public function insertClaim(string $sessionId, string $requestId, string $turnId, int $generation, string $eventType, string $canonicalJson, string $eventSha256): bool { return ($this->log)(__FUNCTION__, func_get_args()); }
            public function findAnchor(string $sessionId, int $generation, string $requestId): ?array { ($this->log)(__FUNCTION__, func_get_args()); return $this->script['findAnchor'] ?? null; }
        });
        ActorActivationDao::inject(new class($log) extends ActorActivationDao {
            public function __construct(private object $log) {}
            public function staleUpToGeneration(string $sessionId, int $generation): bool { return ($this->log)(__FUNCTION__, func_get_args()); }
        });
    }

    public static function resetClaim(): void
    {
        RuntimeSessionDao::inject(null);
        WorkerJobDao::inject(null);
        ProtocolEventDao::inject(null);
        ActorActivationDao::inject(null);
        RequestExecutionDao::inject(null);
    }

    /**
     * The action intent insert, follow-up origin read and last-issued read of synth_protocol_state.php answered by $fake, which sees
     * the legacy SQL text (execQuery() for the insert, fetchOne() and fetchAll() for the reads).
     */
    public static function actionIntentFrom(object $fake): void
    {
        ActionIntentDao::inject(new class($fake) extends ActionIntentDao {
            public function __construct(private object $fake) {}

            public function insertIntent(string $actionId, string $idempotencyKey, string $sessionId, string $requestId, string $turnId, int $generation, string $actionName, string $capability, int $actorIdentityId, ?int $targetIdentityId, string $argumentsJson, int $deadlineMs): bool
            {
                $f = $this->fake;
                $target = $targetIdentityId === null ? 'NULL' : strval($targetIdentityId);
                return $f->execQuery("INSERT INTO public.core_action_intent
                (action_id, idempotency_key, session_id, request_id, turn_id, generation,
                 action_name, capability, actor_identity_id, target_identity_id, arguments, deadline_at)
             VALUES ({$f->escapeLiteral($actionId)}, {$f->escapeLiteral($idempotencyKey)}, {$f->escapeLiteral($sessionId)}, {$f->escapeLiteral($requestId)}, {$f->escapeLiteral($turnId)}, {$generation},
                     {$f->escapeLiteral($actionName)}, {$f->escapeLiteral($capability)}, {$actorIdentityId}, {$target}, {$f->escapeLiteral($argumentsJson)}::jsonb,
                     NOW() + ({$deadlineMs} * INTERVAL '1 millisecond'))") !== false;
            }

            public function followupOrigin(string $actionId, string $idempotencyKey, string $sessionId, int $generation, string $turnId, string $runtimeVariant): ?array
            {
                $f = $this->fake;
                $row = $f->fetchOne("SELECT i.action_name, e.canonical_event
               FROM public.core_action_intent i
               JOIN public.core_protocol_event e ON e.session_id=i.session_id
                AND e.request_id=i.request_id AND e.generation=i.generation AND e.turn_id=i.turn_id
               JOIN public.core_runtime_session r ON r.session_id=i.session_id
              WHERE i.action_id={$f->escapeLiteral($actionId)} AND i.idempotency_key={$f->escapeLiteral($idempotencyKey)}
                AND i.session_id={$f->escapeLiteral($sessionId)} AND i.generation={$generation} AND i.turn_id={$f->escapeLiteral($turnId)}
                AND r.state='active' AND r.active_generation={$generation} AND r.runtime_variant={$f->escapeLiteral($runtimeVariant)}");

                return $row ?: null;
            }

            public function lastIssuedByAction(string $historyCte, string $sessionId, int $generation, int $sequence, string $turnId): array
            {
                $f = $this->fake;
                return $f->fetchAll($historyCte . "
        SELECT DISTINCT ON (i.action_name) i.action_name AS action,
            (h.event_payload->>'gamets')::bigint AS gamets,
            (h.event_payload->>'ts')::bigint AS ts
        FROM native_history_scopes s
        JOIN public.core_history_event h ON h.session_id=s.session_id AND h.generation=s.generation
            AND h.playthrough_id=s.playthrough_id AND h.event_payload->>'type'='funccall'
        JOIN public.core_action_intent i ON i.action_id=h.event_payload->>'data'
            AND i.session_id=h.session_id AND i.generation=h.generation AND i.request_id=h.request_id
            AND i.actor_identity_id=s.reader_identity_id
        WHERE h.id<=s.cutoff OR (h.session_id={$f->escapeLiteral($sessionId)} AND h.generation={$generation}
            AND h.context_sequence={$sequence} AND i.turn_id={$f->escapeLiteral($turnId)})
        ORDER BY i.action_name, (h.event_payload->>'gamets')::bigint DESC, h.id DESC");
            }
        });
    }

    /**
     * The actor identity, activation and loaded-context reads and writes of synth_protocol_state.php answered by $fake, which sees
     * the legacy SQL text (fetchOne() for reads and RETURNING, execQuery() for the other writes).
     */
    public static function identityFrom(object $fake): void
    {
        ActorIdentityDao::inject(new class($fake) extends ActorIdentityDao {
            public function __construct(private object $fake) {}

            public function lockFo4Identity(string $fullFormId, string $originPlugin, string $playthroughId): ?int
            {
                $f = $this->fake;
                $row = $f->fetchOne("SELECT id FROM public.core_actor_identity
          WHERE game={$f->escapeLiteral('fo4')}
            AND upper(full_form_id)=upper({$f->escapeLiteral($fullFormId)})
            AND lower(origin_plugin)=lower({$f->escapeLiteral($originPlugin)})
            AND playthrough_id={$f->escapeLiteral($playthroughId)}
          FOR UPDATE");

                return $row ? intval($row['id']) : null;
            }

            public function touch(int $id, ?string $displayName): bool
            {
                $f = $this->fake;
                $name = $displayName === null ? 'NULL' : $f->escapeLiteral($displayName);
                $f->execQuery("UPDATE public.core_actor_identity
                SET last_seen_at=NOW(), display_name=COALESCE({$name}, display_name)
              WHERE id=" . $id);

                return true;
            }

            public function insertFo4(string $fullFormId, string $originPlugin, string $playthroughId, ?string $displayName): ?int
            {
                $f = $this->fake;
                $name = $displayName === null ? 'NULL' : $f->escapeLiteral($displayName);
                $row = $f->fetchOne("INSERT INTO public.core_actor_identity (game, full_form_id, origin_plugin, playthrough_id, display_name)
         VALUES ({$f->escapeLiteral('fo4')}, {$f->escapeLiteral($fullFormId)}, {$f->escapeLiteral($originPlugin)}, {$f->escapeLiteral($playthroughId)}, {$name})
         RETURNING id");

                return !$row || intval($row['id'] ?? 0) < 1 ? null : intval($row['id']);
            }
        });
        RuntimeContextDao::inject(new class($fake) extends RuntimeContextDao {
            public function __construct(private object $fake) {}

            public function loadedContext(string $sessionId, string $playthroughId, int $generation, int $sequence, string $runtimeVariant): ?array
            {
                $f = $this->fake;
                $row = $f->fetchOne("SELECT c.context_payload, c.playthrough_id, c.session_id, c.generation, c.snapshot_sequence, c.history_event_id
        FROM public.core_runtime_context c JOIN public.core_runtime_session r USING (session_id)
        WHERE c.session_id={$f->escapeLiteral($sessionId)} AND c.generation={$generation} AND c.snapshot_sequence={$sequence}
            AND c.playthrough_id={$f->escapeLiteral($playthroughId)} AND r.runtime_variant={$f->escapeLiteral($runtimeVariant)}");

                return $row ?: null;
            }
        });
        ActorActivationDao::inject(new class($fake) extends ActorActivationDao {
            public function __construct(private object $fake) {}

            public function upsertActive(string $sessionId, int $generation, int $actorIdentityId, string $source): bool
            {
                $f = $this->fake;
                $f->execQuery("INSERT INTO public.core_actor_activation
            (session_id, generation, actor_identity_id, source, state)
         VALUES ({$f->escapeLiteral($sessionId)}, {$generation}, {$actorIdentityId}, {$f->escapeLiteral($source)}, 'active')
         ON CONFLICT (session_id, generation, actor_identity_id) DO UPDATE SET
            source = CASE
                WHEN public.core_actor_activation.source='manual' THEN 'manual'
                ELSE EXCLUDED.source
            END,
            state='active', last_seen_at=NOW()");

                return true;
            }
        });
    }

    /** RuntimeContextDao::latestSnapshot() answered by $fake->fetchOne() with the legacy newest-snapshot query; other methods have no session. Cleared by reset(). */
    public static function latestSnapshotFrom(object $fake): void
    {
        RuntimeContextDao::inject(new class($fake) extends RuntimeContextDao {
            public function __construct(private object $fake) {}

            public function latestSnapshot(string $sessionId, int $generation): ?array
            {
                $f = $this->fake;
                $row = $f->fetchOne("SELECT snapshot_sequence AS latest, playthrough_id, context_payload->'loaded_context' AS loaded_context
           FROM public.core_runtime_context
          WHERE session_id={$f->escapeLiteral($sessionId)} AND generation={$generation}
          ORDER BY snapshot_sequence DESC LIMIT 1");

                return $row ?: null;
            }
        });
    }

    /**
     * Forgets every DAO that an earlier test configured with Database::boot*(). A test whose fixture owns its PostgreSQL connection
     * (and an open transaction on it) needs its DAOs bound to that fixture, not to a session booted by another test.
     */
    public static function forgetBooted(): void
    {
        foreach (\Synth\Core\DbManager\Database::DAOS as $daoClass) {
            if (method_exists($daoClass, 'inject')) {
                $daoClass::inject(null);
            }
        }
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

    /** ContextEventDao::latestWorldContextRow() answered by the test's current $GLOBALS['db']->fetchAll() with the legacy query. */
    public static function worldContextFromGlobalDb(): void
    {
        ContextEventDao::inject(new class extends ContextEventDao {
            public function __construct() {}

            public function latestWorldContextRow(): ?array
            {
                $rows = $GLOBALS['db']->fetchAll("SELECT party, data
           FROM core_context_event
          WHERE type='world_context'
            AND COALESCE(party, '') <> ''
          ORDER BY localts DESC, ts DESC, rowid DESC
          LIMIT 1");

                return is_array($rows) && $rows !== [] ? $rows[0] : null;
            }
        });
    }

    /** PluginsDao::findRowByName() answered by $fake->fetchOne() with the legacy plugin query. */
    public static function pluginsFrom(object $fake): void
    {
        PluginsDao::inject(new class($fake) extends PluginsDao {
            public function __construct(private object $fake) {}

            public function findRowByName(string $pluginName): ?array
            {
                $name = method_exists($this->fake, 'escape') ? $this->fake->escape($pluginName) : str_replace("'", "''", $pluginName);
                $row = $this->fake->fetchOne("SELECT plugin_name, is_light, compile_index, small_file_compile_index, partial_index, formid_prefix, updated_at
            FROM public.core_plugins
            WHERE LOWER(plugin_name) = LOWER('{$name}')
            LIMIT 1");

                return is_array($row) && $row !== [] ? $row : null;
            }
        });
    }

    /** ItemDescriptionDao::combinedDescriptionByName() answered by $fake->fetchAll() with the legacy combined-catalogue query. */
    public static function combinedDescriptionsFrom(object $fake): void
    {
        ItemDescriptionDao::inject(new class($fake) extends ItemDescriptionDao {
            public function __construct(private object $fake) {}

            public function combinedDescriptionByName(string $name): ?string
            {
                $escaped = method_exists($this->fake, 'escape') ? $this->fake->escape($name) : str_replace("'", "''", $name);
                $rows = $this->fake->fetchAll("SELECT description FROM combined_descriptions WHERE LOWER(name) = LOWER('{$escaped}') LIMIT 1");

                return !empty($rows) && !empty($rows[0]['description']) ? (string) $rows[0]['description'] : null;
            }
        });
    }

    /** ActorIdentityDao::identityRow() answered by $fake->fetchOne() with the legacy identity query. */
    public static function identityRowFrom(object $fake): void
    {
        ActorIdentityDao::inject(new class($fake) extends ActorIdentityDao {
            public function __construct(private object $fake) {}

            public function identityRow(int $id, ?string $game = null): ?array
            {
                $row = $this->fake->fetchOne('SELECT full_form_id,origin_plugin,playthrough_id FROM public.core_actor_identity WHERE '
                    .($game !== null ? "game='{$game}' AND " : '')."id={$id}");

                return is_array($row) && $row !== [] ? $row : null;
            }
        });
    }

    /** NpcDao's by-name and by-actor reads answered by $fake->fetchOne() with the legacy queries. */
    public static function npcFrom(object $fake): void
    {
        NpcDao::inject(new class($fake) extends NpcDao {
            public function __construct(private object $fake) {}

            private function literal(string $value): string
            {
                return method_exists($this->fake, 'escapeLiteral') ? $this->fake->escapeLiteral($value) : "'".str_replace("'", "''", $value)."'";
            }

            private function fetchRow(string $sql): ?array
            {
                $row = $this->fake->fetchOne($sql);

                return is_array($row) && $row !== [] ? $row : null;
            }

            public function metadataByName(string $name): ?array
            {
                return $this->fetchRow("SELECT metadata FROM core_npc WHERE npc_name = '".str_replace("'", "''", $name)."' LIMIT 1");
            }

            public function voiceByName(string $name): ?array
            {
                return $this->fetchRow('SELECT voiceid, extended_data FROM public.core_npc WHERE npc_name='.$this->literal($name).' LIMIT 1');
            }

            public function voiceByActor(string $formId, string $originPlugin, string $playthroughId): ?array
            {
                $form = $this->literal($formId);
                $plugin = $this->literal($originPlugin);
                $playthrough = $this->literal($playthroughId);

                return $this->fetchRow("SELECT n.voiceid,n.extended_data FROM public.core_npc n
            JOIN public.core_actor_identity a ON a.id=n.actor_identity_id
            WHERE a.game='fo4' AND upper(a.full_form_id)=upper({$form})
                AND lower(a.origin_plugin)=lower({$plugin}) AND a.playthrough_id={$playthrough}");
            }
        });
    }

    /** ActionIntentDao::inventoryAncestry() answered by $fake->fetchOne() with the legacy ancestry query (its identifying parts). */
    public static function inventoryFrom(object $fake): void
    {
        ActionIntentDao::inject(new class($fake) extends ActionIntentDao {
            public function __construct(private object $fake) {}

            public function inventoryAncestry(bool $retainsGiftOwner, string $actionId, string $idempotencyKey, string $receiptRequestId, string $canonicalReceiptJson,
                string $sessionId, string $turnId, int $generation, int $sequence, string $runtimeVariant): ?array
            {
                $lit = fn(string $value): string => $this->fake->escapeLiteral($value);
                $row = $this->fake->fetchOne("SELECT i.action_name,i.capability,i.request_id AS origin_request_id,a.full_form_id,a.origin_plugin,a.playthrough_id,
                    e.canonical_event,t.result,b.full_form_id AS recipient_form_id,
                    b.origin_plugin AS recipient_plugin,b.playthrough_id AS recipient_playthrough_id
                FROM public.core_action_intent i
                JOIN public.core_actor_identity a ON a.id=i.actor_identity_id AND a.game='fo4'
                WHERE i.action_id=".$lit($actionId).' AND i.idempotency_key='.$lit($idempotencyKey).' AND i.session_id='.$lit($sessionId)
                    .' AND i.turn_id='.$lit($turnId).' AND i.generation='.$generation.' AND r.runtime_variant='.$lit($runtimeVariant));

                return is_array($row) && $row !== [] ? $row : null;
            }
        });
    }

    /** WorkerJobDao's speech status reads answered by $fake->fetchOne() with the legacy queries. */
    public static function workerJobFrom(object $fake): void
    {
        WorkerJobDao::inject(new class($fake) extends WorkerJobDao {
            public function __construct(private object $fake) {}

            public function ttsStatusById(int $jobId, string $cacheKey): ?array
            {
                $row = $this->fake->fetchOne("SELECT state,deadline_at>clock_timestamp() AS within_deadline FROM public.core_worker_job
        WHERE id={$jobId} AND job_type='tts' AND payload->>'schema'='synth.tts.job.v1'
        AND cache_key=".$this->fake->escapeLiteral($cacheKey));

                return is_array($row) && $row !== [] ? $row : null;
            }

            public function ttsActiveByCacheKey(string $cacheKey): ?array
            {
                $row = $this->fake->fetchOne("SELECT state,deadline_at>clock_timestamp() AS within_deadline FROM public.core_worker_job
            WHERE job_type='tts' AND payload->>'schema'='synth.tts.job.v1'
            AND state IN ('pending','running') AND deadline_at>clock_timestamp()
            AND cache_key=".$this->fake->escapeLiteral($cacheKey).' LIMIT 1');

                return is_array($row) && $row !== [] ? $row : null;
            }
        });
    }

    public static function reset(): void
    {
        ActionIntentDao::inject(null);
        ActorIdentityDao::inject(null);
        WorkerJobDao::inject(null);
        PluginsDao::inject(null);
        ItemDescriptionDao::inject(null);
        ContextEventDao::inject(null);
        \Synth\Core\DbManager\Dao\DiaryResultDao::inject(null);
        RequestExecutionDao::inject(null);
        HistoryEventDao::inject(null);
        NpcDao::inject(null);
        \Synth\Core\DbManager\Dao\MemoryDao::inject(null);
        WorkerJobDao::inject(null);
        WorldknowledgeArticleDao::inject(null);
        NpcBiographyTemplateDao::inject(null);
        WorldknowledgeAuditDao::inject(null);
        RuntimeContextDao::inject(null);
    }

    /**
     * ConversationManager's DAO reads answered by the fake: cancelConversation() runs the legacy three statements through
     * $fake->fetchAll()/execQuery() and conversationRankSql() builds the legacy text with $fake->escapeLiteral().
     */
    public static function conversationFrom(object $fake): void
    {
        RequestExecutionDao::inject(new class($fake) extends RequestExecutionDao {
            public function __construct(private object $fake) {}

            public function cancelConversation(string $sessionId, int $generation, string $ownRequestId, string $conversationId, string $capability): array
            {
                $session = $this->fake->escapeLiteral($sessionId);
                $conversation = $this->fake->escapeLiteral($conversationId);
                $own = $this->fake->escapeLiteral($ownRequestId);
                $members = "SELECT request_id FROM public.core_protocol_event WHERE session_id={$session}
            AND generation={$generation} AND request_id<>{$own}
            AND event_type IN ('input_text','input_audio','trigger')
            AND canonical_event->'capabilities' ? '".$capability."'
            AND canonical_event#>>'{payload,conversation_id}'={$conversation}";
                $cancelled = $this->fake->fetchAll("UPDATE public.core_request_execution SET
                state=CASE WHEN state='complete' AND event_type IN ('input_text','input_audio') THEN 'complete' ELSE 'cancelled' END,
                response_body=NULL,error_code='request_cancelled',
                completed_at=CASE WHEN state='complete' AND event_type IN ('input_text','input_audio') THEN completed_at ELSE NOW() END
            WHERE session_id={$session} AND generation={$generation} AND state IN ('claimed','streaming','complete')
                AND request_id IN ({$members}) RETURNING request_id,turn_id");
                $cancelled = is_array($cancelled) ? $cancelled : [];
                $this->fake->execQuery("UPDATE public.core_worker_job SET state='cancelled', updated_at=NOW()
            WHERE session_id={$session} AND generation={$generation} AND state IN ('pending','running')
                AND request_id IN ({$members})");
                $ids = array_column($cancelled, 'request_id');
                if ($ids !== []) {
                    $list = implode(',', array_map(fn($id) => $this->fake->escapeLiteral($id), $ids));
                    $this->fake->execQuery("UPDATE public.core_runtime_session SET active_turn_id=NULL, updated_at=NOW()
                WHERE session_id={$session} AND active_generation={$generation}
                    AND (active_turn_id, active_turn_sequence) IN (
                        SELECT turn_id, (canonical_event->>'context_sequence')::bigint FROM public.core_protocol_event
                        WHERE session_id={$session} AND generation={$generation} AND request_id IN ({$list}))");
                }

                return $ids;
            }
        });
        HistoryEventDao::inject(new class($fake) extends HistoryEventDao {
            public function __construct(private object $fake) {}

            public function conversationRankSql(string $sessionId, string $conversationId): string
            {
                $session = $this->fake->escapeLiteral($sessionId);
                return "(type NOT IN ('chat','inputtext','inputtext_s','backgroundchat') OR EXISTS (SELECT 1 FROM public.core_history_event conv_h
            JOIN public.core_protocol_event conv_e ON conv_e.session_id=conv_h.session_id AND conv_e.request_id=conv_h.request_id
                AND conv_e.generation=conv_h.generation
            WHERE conv_h.id=history_event_id AND conv_h.session_id={$session}
                AND conv_e.canonical_event#>>'{payload,conversation_id}'=".$this->fake->escapeLiteral($conversationId).'))';
            }
        });
    }

            public function cancelConversation(string $sessionId, int $generation, string $ownRequestId, string $conversationId, string $capability): array
            {
                $session = $this->fake->escapeLiteral($sessionId);
                $conversation = $this->fake->escapeLiteral($conversationId);
                $own = $this->fake->escapeLiteral($ownRequestId);
                $members = "SELECT request_id FROM public.core_protocol_event WHERE session_id={$session}
            AND generation={$generation} AND request_id<>{$own}
            AND event_type IN ('input_text','input_audio','trigger')
            AND canonical_event->'capabilities' ? '".$capability."'
            AND canonical_event#>>'{payload,conversation_id}'={$conversation}";
                $cancelled = $this->fake->fetchAll("UPDATE public.core_request_execution SET
                state=CASE WHEN state='complete' AND event_type IN ('input_text','input_audio') THEN 'complete' ELSE 'cancelled' END,
                response_body=NULL,error_code='request_cancelled',
                completed_at=CASE WHEN state='complete' AND event_type IN ('input_text','input_audio') THEN completed_at ELSE NOW() END
            WHERE session_id={$session} AND generation={$generation} AND state IN ('claimed','streaming','complete')
                AND request_id IN ({$members}) RETURNING request_id,turn_id");
                $cancelled = is_array($cancelled) ? $cancelled : [];
                $this->fake->execQuery("UPDATE public.core_worker_job SET state='cancelled', updated_at=NOW()
            WHERE session_id={$session} AND generation={$generation} AND state IN ('pending','running')
                AND request_id IN ({$members})");
                $ids = array_column($cancelled, 'request_id');
                if ($ids !== []) {
                    $list = implode(',', array_map(fn($id) => $this->fake->escapeLiteral($id), $ids));
                    $this->fake->execQuery("UPDATE public.core_runtime_session SET active_turn_id=NULL, updated_at=NOW()
                WHERE session_id={$session} AND active_generation={$generation}
                    AND (active_turn_id, active_turn_sequence) IN (
                        SELECT turn_id, (canonical_event->>'context_sequence')::bigint FROM public.core_protocol_event
                        WHERE session_id={$session} AND generation={$generation} AND request_id IN ({$list}))");
                }

                return $ids;
            }

            public function conversationRankSql(string $sessionId, string $conversationId): string
            {
                $session = $this->fake->escapeLiteral($sessionId);
                return "(type NOT IN ('chat','inputtext','inputtext_s','backgroundchat') OR EXISTS (SELECT 1 FROM public.core_history_event conv_h
            JOIN public.core_protocol_event conv_e ON conv_e.session_id=conv_h.session_id AND conv_e.request_id=conv_h.request_id
                AND conv_e.generation=conv_h.generation
            WHERE conv_h.id=history_event_id AND conv_h.session_id={$session}
                AND conv_e.canonical_event#>>'{payload,conversation_id}'=".$this->fake->escapeLiteral($conversationId).'))';
            }

    /** NpcDao::linkedToActor() answered by $fake->fetchAll() (one lookup per call), whatever the SQL text. */
    public static function linkedActorsFrom(object $fake): void
    {
        NpcDao::inject(new class($fake) extends NpcDao {
            public function __construct(private object $fake) {}

            public function linkedToActor(string $formId, string $originPlugin, string $playthroughId): array
            {
                return $this->fake->fetchAll('linked actors '.$formId.'|'.$originPlugin.'|'.$playthroughId);
            }
        });
    }

            public function linkedToActor(string $formId, string $originPlugin, string $playthroughId): array
            {
                return $this->fake->fetchAll('linked actors '.$formId.'|'.$originPlugin.'|'.$playthroughId);
            }

    /** MemoryDao::latestDiaryEntry() answered by $fake->fetchOne() with the legacy diary query. */
    public static function latestDiaryFrom(object $fake): void
    {
        \Synth\Core\DbManager\Dao\MemoryDao::inject(new class($fake) extends \Synth\Core\DbManager\Dao\MemoryDao {
            public function __construct(private object $fake) {}

            public function latestDiaryEntry(string $companion): ?array
            {
                $row = $this->fake->fetchOne("SELECT topic, summary AS content
                 FROM public.core_memory
                 WHERE source_type = 'diary' AND lower(trim(companions)) = lower('{$this->fake->escape($companion)}')
                 ORDER BY gamets DESC, localts DESC, rowid DESC
                 LIMIT 1");

                return is_array($row) ? $row : null;
            }
        });
    }

            public function latestDiaryEntry(string $companion): ?array
            {
                $row = $this->fake->fetchOne("SELECT topic, summary AS content
                 FROM public.core_memory
                 WHERE source_type = 'diary' AND lower(trim(companions)) = lower('{$this->fake->escape($companion)}')
                 ORDER BY gamets DESC, localts DESC, rowid DESC
                 LIMIT 1");

                return is_array($row) ? $row : null;
            }

    /**
     * The middleterm dispatch statements of WorkerJobDao (retire, next due job, owner discovery) answered by $fake with their
     * legacy SQL text: execQuery() for the retiring UPDATE and the discovery CTE, fetchOne() for the due-job read.
     */
    public static function middletermDispatchFrom(object $fake): void
    {
        WorkerJobDao::inject(new class($fake) extends WorkerJobDao {
            public function __construct(private object $fake) {}

            public function retireMiddletermJobs(): bool
            {
                return $this->fake->execQuery("UPDATE public.core_worker_job j SET state=CASE WHEN deadline_at<=clock_timestamp() OR attempts>=3
        THEN 'failed' ELSE 'stale' END,lease_token=NULL,lease_until=NULL,last_error='middleterm_owner_expired'
        WHERE job_type='middleterm' AND state IN ('pending','running')") !== false;
            }

            public function nextDueMiddleterm(): ?int
            {
                $row = $this->fake->fetchOne("SELECT id FROM public.core_worker_job WHERE job_type='middleterm' AND available_at<=clock_timestamp()
        ORDER BY available_at,id LIMIT 1");

                return is_array($row) && isset($row['id']) ? (int) $row['id'] : null;
            }

            public function discoverMiddletermOwner(): ?array
            {
                $result = $this->fake->execQuery("WITH candidate AS (SELECT r.session_id FROM public.core_runtime_session r) SELECT c.* FROM candidate c");

                return $result instanceof \PgSql\Result ? (pg_fetch_assoc($result) ?: null) : null;
            }
        });
    }

            public function retireMiddletermJobs(): bool
            {
                return $this->fake->execQuery("UPDATE public.core_worker_job j SET state=CASE WHEN deadline_at<=clock_timestamp() OR attempts>=3
        THEN 'failed' ELSE 'stale' END,lease_token=NULL,lease_until=NULL,last_error='middleterm_owner_expired'
        WHERE job_type='middleterm' AND state IN ('pending','running')") !== false;
            }

            public function nextDueMiddleterm(): ?int
            {
                $row = $this->fake->fetchOne("SELECT id FROM public.core_worker_job WHERE job_type='middleterm' AND available_at<=clock_timestamp()
        ORDER BY available_at,id LIMIT 1");

                return is_array($row) && isset($row['id']) ? (int) $row['id'] : null;
            }

            public function discoverMiddletermOwner(): ?array
            {
                $result = $this->fake->execQuery("WITH candidate AS (SELECT r.session_id FROM public.core_runtime_session r) SELECT c.* FROM candidate c");

                return $result instanceof \PgSql\Result ? (pg_fetch_assoc($result) ?: null) : null;
            }

    /**
     * The World Knowledge reads and the audit write answered by a test's own fake `$db`: each double rebuilds the legacy
     * SQL text and asks the fake (fetchAll, fetchOne, escape, execQuery), so what the test scripts is what the DAO returns.
     */
    public static function worldKnowledgeReadsFrom(object $fake): void
    {
        WorldknowledgeArticleDao::inject(new class($fake) extends WorldknowledgeArticleDao {
            public function __construct(private object $fake) {}

            public function effectiveCatalog(): array
            {
                return $this->fake->fetchAll("SELECT entry_id, topic, aliases, canonical_topic, topic_desc, knowledge_class, topic_desc_basic,
                    knowledge_class_basic, tags, category, source_kind, catalog_id, catalog_version, is_active
               FROM synth.worldknowledge_effective
              ORDER BY canonical_topic");
            }

            public function fallbackCatalog(): array
            {
                return $this->fake->fetchAll("SELECT topic, ''::text AS aliases, lower(btrim(split_part(topic, ',', 1))) AS canonical_topic,
                    topic_desc, knowledge_class, topic_desc_basic, knowledge_class_basic,
                    tags, category, 'custom'::text AS source_kind, NULL::text AS catalog_id,
                    NULL::text AS catalog_version, TRUE AS is_active
               FROM public.core_worldknowledge_article
              ORDER BY lower(btrim(split_part(topic, ',', 1)))");
            }

            public function effectiveRowsForSignals(array $signals): array
            {
                return $this->fake->fetchAll('SELECT topic, aliases, canonical_topic FROM synth.worldknowledge_effective WHERE signals IN ('.$this->quoted($signals).')');
            }

            public function articleRowsForSignals(array $signals): array
            {
                return $this->fake->fetchAll('SELECT topic FROM public.core_worldknowledge_article WHERE signals IN ('.$this->quoted($signals).')');
            }

            private function quoted(array $signals): string
            {
                return implode(',', array_map(fn($signal): string => "'".$this->fake->escape($signal)."'", $signals));
            }
        });
        NpcBiographyTemplateDao::inject(new class($fake) extends NpcBiographyTemplateDao {
            public function __construct(private object $fake) {}

            public function worldknowledgeTagsByName(string $npcName): ?array
            {
                $row = $this->fake->fetchOne("SELECT worldknowledge_tags FROM synth.combined_bio_templates"
                    ." WHERE lower(npc_name)=lower('".$this->fake->escape($npcName)."') LIMIT 1");

                return is_array($row) && $row !== [] ? $row : null;
            }
        });
        WorldknowledgeAuditDao::inject(new class($fake) extends WorldknowledgeAuditDao {
            public function __construct(private object $fake) {}

            public function record(array $values): bool
            {
                $literals = array_map(fn($value): string => $value === null ? 'NULL' : $this->fake->escapeLiteral(strval($value)), $values);

                return (bool) $this->fake->execQuery('INSERT INTO public.core_worldknowledge_audit ('.implode(',', array_keys($values))
                    .') VALUES ('.implode(',', $literals).')');
            }
        });
    }

            public function effectiveCatalog(): array
            {
                return $this->fake->fetchAll("SELECT entry_id, topic, aliases, canonical_topic, topic_desc, knowledge_class, topic_desc_basic,
                    knowledge_class_basic, tags, category, source_kind, catalog_id, catalog_version, is_active
               FROM synth.worldknowledge_effective
              ORDER BY canonical_topic");
            }

            public function fallbackCatalog(): array
            {
                return $this->fake->fetchAll("SELECT topic, ''::text AS aliases, lower(btrim(split_part(topic, ',', 1))) AS canonical_topic,
                    topic_desc, knowledge_class, topic_desc_basic, knowledge_class_basic,
                    tags, category, 'custom'::text AS source_kind, NULL::text AS catalog_id,
                    NULL::text AS catalog_version, TRUE AS is_active
               FROM public.core_worldknowledge_article
              ORDER BY lower(btrim(split_part(topic, ',', 1)))");
            }

            public function effectiveRowsForSignals(array $signals): array
            {
                return $this->fake->fetchAll('SELECT topic, aliases, canonical_topic FROM synth.worldknowledge_effective WHERE signals IN ('.$this->quoted($signals).')');
            }

            public function articleRowsForSignals(array $signals): array
            {
                return $this->fake->fetchAll('SELECT topic FROM public.core_worldknowledge_article WHERE signals IN ('.$this->quoted($signals).')');
            }

            private function quoted(array $signals): string
            {
                return implode(',', array_map(fn($signal): string => "'".$this->fake->escape($signal)."'", $signals));
            }

            public function worldknowledgeTagsByName(string $npcName): ?array
            {
                $row = $this->fake->fetchOne("SELECT worldknowledge_tags FROM synth.combined_bio_templates"
                    ." WHERE lower(npc_name)=lower('".$this->fake->escape($npcName)."') LIMIT 1");

                return is_array($row) && $row !== [] ? $row : null;
            }

            public function record(array $values): bool
            {
                $literals = array_map(fn($value): string => $value === null ? 'NULL' : $this->fake->escapeLiteral(strval($value)), $values);

                return (bool) $this->fake->execQuery('INSERT INTO public.core_worldknowledge_audit ('.implode(',', array_keys($values))
                    .') VALUES ('.implode(',', $literals).')');
            }
}
