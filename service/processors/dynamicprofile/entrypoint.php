<?php

\Synth\Bootstrap::setAt('TASKS', 'dynamicprofile', []);
\Synth\Bootstrap::setPath('TASKS', ['dynamicprofile', 'fn'], function () {
    $enginePath = \Synth\Bootstrap::engineRoot();
    \Synth\Bootstrap::set('ENGINE_PATH', $enginePath);

    if (!\Synth\Bootstrap::has('db')) {
        \Synth\Bootstrap::set('db', new sql());
    }

    require_once $enginePath . 'prompts/command_prompt.php';
    require_once $enginePath . 'lib/chat_helper_functions.php';
    require_once $enginePath . 'lib/data_functions.php';
    require_once $enginePath . 'lib/dynamic_update_util.php';
    
triggerImmediateProfileProcessing();
    require_once $enginePath . 'lib/synth_diary_jobs.php';
    synth_diary_dispatch_jobs(\Synth\Bootstrap::db());
});
