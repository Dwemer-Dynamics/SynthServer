<?php

\Synth\Bootstrap::setAt('TASKS', "rolemaster", []);
\Synth\Bootstrap::setPath('TASKS', ["rolemaster", "explicit_only"], true);
\Synth\Bootstrap::setPath('TASKS', ["rolemaster", "fn"], function() {

    $enginePath = \Synth\Bootstrap::engineRoot();

    // Initialize function parameters before requiring functions.php
    \Synth\Bootstrap::set('FUNCTION_PARM_INSPECT', []);
    \Synth\Bootstrap::set('FUNCTION_PARM_MOVETO', []);
    \Synth\Bootstrap::set('F_NAMES', []);

    require_once($enginePath . "lib/runtime_bootstrap.php");
    synthRuntimeBootstrapIfNeeded($enginePath, [
        'load_general_settings' => true,
        'load_stt_connector' => false,
        'load_player_name' => true,
        'load_narrator' => true,
    ]);
    if (!\Synth\Bootstrap::has('db')) { \Synth\Bootstrap::set('db', new sql()); }
    require_once($enginePath . "prompts/command_prompt.php");
    require_once($enginePath . "lib/chat_helper_functions.php");
    require_once($enginePath . "lib/data_functions.php");
    require_once($enginePath . "lib/rolemaster_bored.php");


    SaveOriginalSYNTHName();
    \Synth\Bootstrap::set('SYNTH_NAME', "(actor)");

    require($enginePath . "functions/functions.php");

    // Make functions.php data global


    \Synth\Bootstrap::set('FUNCTIONS_ARE_ENABLED', false);

    \Synth\Bootstrap::set('CURRENT_CONNECTOR', \Synth\Bootstrap::config("CONNECTORS_DIARY"));


    if (!\Synth\Bootstrap::has('db')) { \Synth\Bootstrap::set('db', new sql()); }
    if (!\Synth\Bootstrap::db()) {
        throw new Exception("Database connection established, but an error occurred during initialization.");
    }

    // Some functions need this setted
    //$res=\Synth\Bootstrap::db()->fetchAll("select max(gamets)+1 as gamets,max(ts)+1 as ts  from core_context_event order by gamets desc limit 1 offset 0");
    $res=\Synth\Bootstrap::db()->fetchAll("SELECT max(gamets)+1 as gamets, max(ts)+1 as ts FROM core_context_event "); // without order is faster, pgsql can use a better exec plan
    if ($res) {
        \Synth\Bootstrap::set('gameRequest', ["inputtext"]);
        \Synth\Bootstrap::setAt('gameRequest', 2, $res[0]["gamets"]+1);
    } else {
        Logger::error("Empty recordset. ".__FILE__." ".__LINE__." ".__FUNCTION__);
    }

    if (isset(\Synth\Bootstrap::config('argv')[2])) {
        if (\Synth\Bootstrap::config("argv")[2]=="instruction") {
            Logger::info("Loading instruction command");
            require_once("cmd" . DIRECTORY_SEPARATOR . "instruction.php");
        } else if (\Synth\Bootstrap::config("argv")[2]=="suggestion") {
            Logger::info("Loading suggestion command");
            require_once("cmd" . DIRECTORY_SEPARATOR . "suggestion.php");
        } else if (\Synth\Bootstrap::config("argv")[2]=="impersonation") {
            Logger::info("Loading impersonation command");
            require_once("cmd" . DIRECTORY_SEPARATOR . "impersonation.php");
        }  else if (\Synth\Bootstrap::config("argv")[2]=="smart_impersonation") {
            Logger::info("Loading smart_impersonation command");
            require_once("cmd" . DIRECTORY_SEPARATOR . "smart_impersonation.php");
        }
    }


});
?>
