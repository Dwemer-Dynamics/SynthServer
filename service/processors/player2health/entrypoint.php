<?php

$GLOBALS['TASKS']['player2health'] = [];
$GLOBALS['TASKS']['player2health']['fn'] = function () {
    $enginePath = \Synth\Bootstrap::engineRoot();
    if (!isset($GLOBALS['db'])) {
        $GLOBALS['db'] = new sql();
    }

    require_once $enginePath . 'lib/player2_health.php';
    synthPlayer2HealthTick();
};
