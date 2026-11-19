<?php

// Legacy path. The class now lives in managers/PromptManager/PromptManager.php; this file keeps the old name
// \Synth\Managers\PromptManager working for existing callers until they use the new namespace.
require_once __DIR__.'/PromptManager/PromptManager.php';
class_alias(\Synth\Managers\PromptManager\PromptManager::class, 'Synth\\Managers\\PromptManager');
