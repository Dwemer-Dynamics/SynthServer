<?php

\Synth\Bootstrap::setAt('TASKS', 'player2health', []);
\Synth\Bootstrap::setPath('TASKS', ['player2health', 'fn'], function () {
    $enginePath = \Synth\Bootstrap::engineRoot();
    if (!\Synth\Bootstrap::has('db')) {
        \Synth\Bootstrap::set('db', new sql());
    }

    require_once $enginePath . 'lib/player2_health.php';
    synthPlayer2HealthTick();
});
