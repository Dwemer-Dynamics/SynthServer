# managers/Conversation/Rechat

Rechat admission, selection, history, preparation, emission and routes, plus auto chat, player rewrite and auto greeting, moved from lib/.

Files: `auto_greeting.php` `synth_autochat.php` `synth_player_rewrite.php` `synth_rechat_admission.php` `synth_rechat_emission.php` `synth_rechat_history.php` `synth_rechat_prepare.php` `synth_rechat_profile.php` `synth_rechat_routes.php` `synth_rechat_selection.php` 

The matching `lib/` paths are thin `require_once` hooks over these files, so entry points, tools and tests are unchanged. The functions are still global; they move into namespaced classes in later steps.
