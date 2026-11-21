<?php

// Test doubles that let a test keep answering a DAO read from its own scripted fake `$db`. The double builds the legacy
// SQL text and asks the fake, so the fake's rows are what the DAO returns. Call reset() when the test is done.

use Synth\Core\DbManager\Dao\RequestExecutionDao;
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

    public static function reset(): void
    {
        RequestExecutionDao::inject(null);
        WorkerJobDao::inject(null);
    }
}
