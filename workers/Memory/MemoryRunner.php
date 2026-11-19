<?php

/**
 * The memory worker's work: one native dispatch pass, and one persisted memory job.
 *
 * `service/memory_dispatch.php` and `service/memory_worker.php` are thin hooks that call this class in a fresh CLI
 * process; each process opens its own database connection through the runtime bootstrap. The job and dispatch
 * functions stay in `workers/Memory/Native/`; recall, ranking, diary state and middle-term policy are in
 * `managers/MemoryManager/Native/`.
 */
declare(strict_types=1);

namespace Synth\Workers\Memory;

use SYNTHProtocolCancelled;
use Throwable;

final class MemoryRunner
{
    /**
     * Runs one memory dispatch pass; returns the process exit code.
     */
    public static function dispatch(string $enginePath): int
    {
        $GLOBALS['ENGINE_ROOT'] = $enginePath;
        require_once $enginePath . 'lib/runtime_bootstrap.php';
        require_once __DIR__ . '/Native/synth_memory_dispatch.php';
        try {
            synthRuntimeBootstrap($enginePath, ['load_general_settings' => true, 'load_stt_connector' => false]);
            $result = \synth_memory_dispatch($GLOBALS['db']);
            if ($result !== null) {
                echo "Native memory dispatch completed.\n";
            }
        } catch (SYNTHProtocolCancelled $error) {
            echo "Native memory dispatch cancelled.\n";
        } catch (Throwable $error) {
            fwrite(STDERR, "Native memory dispatch failed.\n");
            return 1;
        }
        return 0;
    }

    /**
     * Runs one durable memory job; returns the process exit code.
     */
    public static function runJob(string $enginePath, int $jobId): int
    {
        $GLOBALS['ENGINE_ROOT'] = $enginePath;
        require_once $enginePath . 'lib/runtime_bootstrap.php';
        require_once __DIR__ . '/Native/synth_memory_worker.php';
        try {
            synthRuntimeBootstrap($enginePath, ['load_general_settings' => true, 'load_stt_connector' => false]);
            $result = \synth_memory_run_persisted_job($GLOBALS['db'], $jobId);
            echo $result === null ? "Native memory job unavailable.\n" : "Native memory job completed.\n";
        } catch (SYNTHProtocolCancelled $error) {
            echo "Native memory job cancelled.\n";
        } catch (Throwable $error) {
            // Provider bodies, prompts, URLs and credentials must not enter scheduler output.
            fwrite(STDERR, "Native memory job failed.\n");
            return 1;
        }
        return 0;
    }
}
