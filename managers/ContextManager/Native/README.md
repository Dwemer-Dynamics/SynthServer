# managers/ContextManager/Native

Prompt, context and character helpers moved from lib/.

Files: `actor_event.php` `compact_context_history.php` `observation_reaction.php` `observed_dialogue.php` `player_event.php` `player_reaction.php` `rolemaster_bored.php` `synth_ambient_npc.php` `synth_audience_control.php` `synth_control.php` `synth_dialogue_policy.php` `synth_history_speech.php` 

The matching `lib/` paths are thin `require_once` hooks over these files, so entry points, tools and tests are unchanged. The functions are still global; they move into namespaced classes in later steps.
