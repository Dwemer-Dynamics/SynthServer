<?php

// Legacy path. The class now lives in managers/CharacterManager/CharacterManager.php; this file keeps the old name
// \Synth\Managers\CharacterManager working for existing callers until they use the new namespace.
require_once __DIR__.'/CharacterManager/CharacterManager.php';
class_alias(\Synth\Managers\CharacterManager\CharacterManager::class, 'Synth\\Managers\\CharacterManager');
