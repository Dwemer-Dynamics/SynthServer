<?php

require_once __DIR__.'/synth_memory_worker.php';

// Discover one completed native turn, reserve its session briefly, then do all provider work outside SQL locks.
function synth_memory_dispatch($db, ?callable $generate = null): ?int
{
    $settings = \Synth\Bootstrap::config('FEATURES')['MEMORY_EMBEDDING'] ?? [];
    $automatic = $settings['AUTO_CREATE_SUMMARYS'] ?? true;
    if ($automatic === false || in_array(strtolower(trim(strval($automatic))),['0','false','off','no'],true)) return null;
    if (!$db->isTransactionIdle()) throw new LogicException('Memory dispatch requires an idle database connection');
    $minimumEvents = max(1,min(150,intval($settings['AUTO_CREATE_SUMMARY_MIN_EVENTS'] ?? 5)));
    $intervalTicks = max(1,min(1000000,intval($settings['AUTO_CREATE_SUMMARY_INTERVAL'] ?? 10))) * 100000;
    if ($db->execQuery('BEGIN ISOLATION LEVEL READ COMMITTED') === false) throw new RuntimeException('Unable to begin memory dispatch');
    try {
        if ($db->execQuery("SET LOCAL statement_timeout='2s'") === false) throw new RuntimeException('Unable to bound memory dispatch');
        // The reservation runs on the same shared session, inside the transaction begun above.
        try {
            $origin = \Synth\Core\DbManager\Dao\RuntimeSessionDao::current($db)->reserveMemoryDispatch();
        } catch (\Doctrine\DBAL\Exception $error) {
            throw new RuntimeException('Unable to select memory dispatch',0,$error);
        }
        if ($db->execQuery('COMMIT') === false) throw new RuntimeException('Unable to reserve memory dispatch');
    } finally {
        if (!$db->isTransactionIdle()) $db->execQuery('ROLLBACK');
    }
    if (!$origin) return null;
    $jobId = synth_memory_with_origin($db,$origin,static function(array $event) use ($db,$minimumEvents,$intervalTicks) {
        // Recover due attempts first, then frozen-but-unscheduled inputs. Never endlessly recapture a covered batch.
        $candidate = $db->withNativeRequestLock(static function($connection,array $event) use ($db) {
            $jobs = \Synth\Core\DbManager\Dao\WorkerJobDao::current($db);
            $job = synth_memory_dao_call('Unable to select memory retry',
                static fn() => $jobs->nextRetryableMemoryJobId($event['session_id'],$event['turn_id'],$event['generation'],strval($event['context_sequence'])));
            if ($job !== null) return ['job'=>$job];
            $batch = synth_memory_dao_call('Unable to recover memory input',
                static fn() => \Synth\Core\DbManager\Dao\MemoryBatchDao::current($db)->nextUnscheduledBatchId(synth_memory_visible_batch_cte($db,$event),$event['session_id'],$event['request_id']));
            if ($batch !== null) return ['batch'=>$batch];
            $context=synth_memory_response_context($connection,$db,$event);
            if (!$context || !$context['target_identity_id']) return ['full'=>true];
            return ['full'=>synth_memory_dao_call('Unable to check memory capacity',
                static fn() => $jobs->countLiveMemoryJobsForActor($context['target_identity_id']))>=2];
        },2000);
        if (isset($candidate['job'])) return $candidate['job'];
        if (!empty($candidate['full'])) return null;
        $batchId = $candidate['batch'] ?? synth_memory_rebuild_batch($db) ?? synth_memory_capture_batch($db,$minimumEvents,$intervalTicks);
        return $batchId === null ? null : synth_memory_enqueue($db,$batchId);
    });
    return $jobId === null ? null : synth_memory_run_persisted_job($db,$jobId,$generate);
}
