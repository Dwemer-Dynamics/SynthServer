<?php

// Test doubles that let a test keep answering a DAO read from its own scripted fake `$db`. The double builds the legacy
// SQL text and asks the fake, so the fake's rows are what the DAO returns. Call reset() when the test is done.

use Synth\Core\DbManager\Dao\RequestExecutionDao;
use Synth\Core\DbManager\Dao\ActionIntentDao;
use Synth\Core\DbManager\Dao\ActorIdentityDao;
use Synth\Core\DbManager\Dao\NpcDao;
use Synth\Core\DbManager\Dao\WorkerJobDao;

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

    /** ActorIdentityDao::identityRow() answered by $fake->fetchOne() with the legacy identity query. */
    public static function identityFrom(object $fake): void
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

            private function row(string $sql): ?array
            {
                $row = $this->fake->fetchOne($sql);

                return is_array($row) && $row !== [] ? $row : null;
            }

            public function metadataByName(string $name): ?array
            {
                return $this->row("SELECT metadata FROM core_npc WHERE npc_name = '".str_replace("'", "''", $name)."' LIMIT 1");
            }

            public function voiceByName(string $name): ?array
            {
                return $this->row('SELECT voiceid, extended_data FROM public.core_npc WHERE npc_name='.$this->literal($name).' LIMIT 1');
            }

            public function voiceByActor(string $formId, string $originPlugin, string $playthroughId): ?array
            {
                $form = $this->literal($formId);
                $plugin = $this->literal($originPlugin);
                $playthrough = $this->literal($playthroughId);

                return $this->row("SELECT n.voiceid,n.extended_data FROM public.core_npc n
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
        NpcDao::inject(null);
        WorkerJobDao::inject(null);
        RequestExecutionDao::inject(null);
    }
}
