<?php

// Legacy path. The class now lives in managers/ContextManager/ContextManager.php; this file keeps the old name
// \Synth\Managers\ContextManager working for existing callers until they use the new namespace.
require_once __DIR__.'/ContextManager/ContextManager.php';
class_alias(\Synth\Managers\ContextManager\ContextManager::class, 'Synth\\Managers\\ContextManager');
