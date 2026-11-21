<?php

// Test doubles that let a test keep answering a DAO read from its own scripted fake `$db`. The double builds the legacy
// SQL text and asks the fake, so the fake's rows are what the DAO returns. Call reset() when the test is done.

use Synth\Core\DbManager\Dao\HistoryEventDao;
use Synth\Core\DbManager\Dao\RequestExecutionDao;

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

    public static function reset(): void
    {
        RequestExecutionDao::inject(null);
        HistoryEventDao::inject(null);
    }
}
