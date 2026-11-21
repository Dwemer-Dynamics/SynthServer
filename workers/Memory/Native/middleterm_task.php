<?php

\Synth\Bootstrap::setAt('TASKS', "middleterm", []);
\Synth\Bootstrap::setPath('TASKS', ["middleterm", "fn"], function() {

    $enginePath = \Synth\Bootstrap::engineRoot();
    \Synth\Bootstrap::set('ENGINE_PATH', $enginePath);


	if (!\Synth\Bootstrap::has('db')) { \Synth\Bootstrap::set('db', new sql()); }

    require_once($enginePath . "prompts/command_prompt.php");
    require_once($enginePath . "lib/chat_helper_functions.php");
    require_once($enginePath . "lib/data_functions.php");
    require_once($enginePath . "lib/utils_game_timestamp.php");

    
// Native narratives use their own immutable journal and never enter the legacy row/name loop below.
    require_once __DIR__ . '/synth_middleterm_jobs.php';
    synth_middleterm_dispatch(\Synth\Bootstrap::db());

    //$results = \Synth\Bootstrap::config("db")->fetchAll("select max(gamets_truncated) as gamets_truncated from core_memory where source_type='summary' AND summary is not null order by gamets_truncated desc limit 1"); //0.8ms
    $lastMemory = \Synth\Core\DbManager\Dao\MemoryDao::current(\Synth\Bootstrap::db())->maxSummaryGamets();

    //$results = \Synth\Bootstrap::config("db")->fetchAll("select max(gamets) as gamets from core_context_event ORDER BY gamets desc limit 1");
    $maxRow = intval(\Synth\Core\DbManager\Dao\ContextEventDao::current(\Synth\Bootstrap::db())->maxGamets());

    $allEnabledMtNpc=\Synth\Core\DbManager\Dao\NpcDao::current(\Synth\Bootstrap::db())->legacyMiddletermNpcs();

    foreach ($allEnabledMtNpc as $npc) {
        $mwdata=json_decode($npc["extended_data"]);
        //echo "[MIDDLETERM] {$npc["npc_name"]} has middleterm memory enabled".PHP_EOL;
        \Synth\Bootstrap::set('SELECTED_NPC', $npc["npc_name"]);
        \Synth\Bootstrap::set('SELECTED_NPC_ID', intval($npc['id']));
        require(__DIR__ . "/middleterm_generate.php");
    }

    $pfi = intval(\Synth\Bootstrap::config("FEATURES")["MEMORY_EMBEDDING"]["AUTO_CREATE_SUMMARY_INTERVAL"] ?? 10) * 100000;
    $minimumEvents = max(1, intval(\Synth\Bootstrap::config("FEATURES")["MEMORY_EMBEDDING"]["AUTO_CREATE_SUMMARY_MIN_EVENTS"] ?? 5));
    $autoCreateValue = strtolower(trim((string)(\Synth\Bootstrap::config("FEATURES")["MEMORY_EMBEDDING"]["AUTO_CREATE_SUMMARYS"] ?? 'true')));
    $autoCreateEnabled = !in_array($autoCreateValue, ['0', 'false', 'off', 'no'], true);

    // PackIntoSummary intentionally leaves roughly one in-game hour unsettled so
    // late events can land in the correct memory bucket. Check the same eligible
    // range here; Fallout 4 can create large game-time jumps that otherwise cause this
    // five-second task to invoke an empty compaction forever.
    $settledCutoff = $maxRow - intval(1 / 0.0000024);
    $eligibleEvents = \Synth\Core\DbManager\Dao\MemoryDao::current(\Synth\Bootstrap::db())->countEventsBetween($lastMemory, $settledCutoff);

    if ($autoCreateEnabled && ($maxRow - $lastMemory) > $pfi && $eligibleEvents >= $minimumEvents) {
        $memoryUtility = escapeshellarg(\Synth\Bootstrap::enginePath() . "/debug/util_memory_subsystem.php");
        Logger::info("[MEMORY] Starting automatic compaction eligible_events={$eligibleEvents} last_memory={$lastMemory} max_event={$maxRow}");
        $shellResult = shell_exec("php {$memoryUtility} compact embed 1 2>&1");

        if ($shellResult === null) {
            Logger::warn("[MEMORY] Automatic compaction returned no process output");
        } else {
            $compactOutput = trim((string)$shellResult);
            if (strlen($compactOutput) > 2000) {
                $compactOutput = substr($compactOutput, -2000);
            }
            Logger::info("[MEMORY] Automatic compaction finished output=" . $compactOutput);
        }
    }

    //unset(\Synth\Bootstrap::config("db"));

});
?>
