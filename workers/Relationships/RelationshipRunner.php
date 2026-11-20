<?php

/**
 * The relationship worker's work: one dispatch pass over the three evaluation lanes, and one persisted job.
 *
 * `service/relationship_dispatch.php` and `service/relationship_worker.php` are thin hooks that call this class in a
 * fresh CLI process; each process opens its own database connection through the runtime bootstrap. The evaluation
 * functions stay in `workers/Relationships/Native/` (jobs, dispatch, interaction queue) and in
 * `managers/Relationships/Native/` (state, evidence, policy).
 */
declare(strict_types=1);

namespace Synth\Workers\Relationships;

use SYNTHProtocolCancelled;
use Throwable;

final class RelationshipRunner
{
    /**
     * Runs one pass of the inline, evaluation and interaction lanes; returns the process exit code.
     */
    public static function dispatch(string $enginePath): int
    {
        \Synth\Bootstrap::set('ENGINE_ROOT', $enginePath);
        require_once $enginePath . 'lib/runtime_bootstrap.php';
        require_once __DIR__ . '/Native/synth_relationship_interaction_dispatch.php';
        try {
            synthRuntimeBootstrap($enginePath, ['load_general_settings' => true, 'load_stt_connector' => false]);
        } catch (Throwable $error) {
            fwrite(STDERR, "Native relationship dispatch failed.\n");
            return 1;
        }
        // A failed evaluation lane must not prevent the independent lane from making progress.
        $failed = false;
        foreach ([static fn() => \synth_relationship_dispatch_inline(\Synth\Bootstrap::db()),
            static fn() => \synth_relationship_dispatch(\Synth\Bootstrap::db(), null, true),
            static fn() => \synth_relationship_dispatch_interaction(\Synth\Bootstrap::db())] as $dispatch) {
            try {
                if ($dispatch() !== null) {
                    echo "Native relationship dispatch completed.\n";
                }
            } catch (SYNTHProtocolCancelled $error) {
                echo "Native relationship dispatch cancelled.\n";
            } catch (Throwable $error) {
                fwrite(STDERR, "Native relationship dispatch failed.\n");
                $failed = true;
            }
        }
        return $failed ? 1 : 0;
    }

    /**
     * Runs one durable relationship job; returns the process exit code.
     */
    public static function runJob(string $enginePath, int $jobId): int
    {
        \Synth\Bootstrap::set('ENGINE_ROOT', $enginePath);
        require_once $enginePath . 'lib/runtime_bootstrap.php';
        require_once __DIR__ . '/Native/synth_relationship_worker.php';
        try {
            synthRuntimeBootstrap($enginePath, ['load_general_settings' => true, 'load_stt_connector' => false]);
            $result = \synth_relationship_run_persisted_job(\Synth\Bootstrap::db(), $jobId);
            echo $result === null ? "Native relationship job unavailable.\n" : "Native relationship job completed.\n";
        } catch (SYNTHProtocolCancelled $error) {
            echo "Native relationship job cancelled.\n";
        } catch (Throwable $error) {
            fwrite(STDERR, "Native relationship job failed.\n");
            return 1;
        }
        return 0;
    }
}
