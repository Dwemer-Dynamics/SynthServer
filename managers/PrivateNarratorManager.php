<?php

// Legacy path. The class now lives in managers/Narrator/PrivateNarratorManager.php; this file keeps the old name
// \Synth\Managers\PrivateNarratorManager working for existing callers until they use the new namespace.
require_once __DIR__.'/Narrator/PrivateNarratorManager.php';
class_alias(\Synth\Managers\Narrator\PrivateNarratorManager::class, 'Synth\\Managers\\PrivateNarratorManager');
