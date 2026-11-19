<?php

// Legacy path. The class now lives in managers/SaveState/SaveState.php; this file keeps the old name
// \Synth\Managers\SaveState working for existing callers until they use the new namespace.
require_once __DIR__.'/SaveState/SaveState.php';
class_alias(\Synth\Managers\SaveState\SaveState::class, 'Synth\\Managers\\SaveState');
