# workers/Profiles/Native

Status, backup, plugin, save-state and worker helpers moved from lib/.

Files: `dynamic_update_util.php` `profile_autofill_async.php` `synth_profile_jobs.php` `synth_profile_scope.php` `synth_profile_state.php` 

The matching `lib/` paths are thin `require_once` hooks over these files, so entry points, tools and tests are unchanged. The functions are still global; they move into namespaced classes in later steps.
