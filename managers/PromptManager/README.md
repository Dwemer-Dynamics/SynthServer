# PromptManager

`Synth\Managers\PromptManager\PromptManager::messages()` is pure prompt assembly: the NPC profile fields, the
bounded context blocks the caller already rendered (World Knowledge, quest knowledge, latest diary, skills), the
situation JSON, the heard history and the player's text. It reads no database and calls no provider.

`lib/synth_prompt_manager.php` (a second, unused prompt builder with its own database reads) was removed; nothing
called it. The `synth_*_prompt` settings that name it in their descriptions are owned by the schema/config work.

`managers/PromptManager.php` is a legacy alias hook.
