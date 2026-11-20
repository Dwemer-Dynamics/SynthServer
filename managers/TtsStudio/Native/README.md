# managers/TtsStudio/Native

Speech, voice, audio and provider helpers moved from lib/.

Files: `cartesia_generation.php` `npc_tts_status.php` `player_tts_helpers.php` `quickstart_service_choices.php` `speech_cache.php` `synth_tts.php` `synth_tts_jobs.php` `synth_tts_plan.php` `synth_tts_worker.php` `synth_worker_origin.php` `tts_pronunciation.php` `tts_pronunciation_preview.php` `tts_studio_provider_detection.php` `voice_catalog.php` `voice_clone_resolver.php` `voice_clone_sync.php` `voice_sample.php` 

The matching `lib/` paths are thin `require_once` hooks over these files, so entry points, tools and tests are unchanged. The functions are still global; they move into namespaced classes in later steps.
