# managers/PromptManager/Native

Prompt, context and character helpers moved from lib/.

Files: `prompt_composition.php` `prompt_injections.php` `synth_condition_prompt.php` `synth_equipment_prompt.php` `synth_history_trigger_prompt.php` 

The matching `lib/` paths are thin `require_once` hooks over these files, so entry points, tools and tests are unchanged. The functions are still global; they move into namespaced classes in later steps.
