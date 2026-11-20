# drivers/llm/Native

Speech, voice, audio and provider helpers moved from lib/.

Files: `llm_randomizer.php` `local_llm_setup.php` `minimet5_service.php` `provider_stream.php` 

The matching `lib/` paths are thin `require_once` hooks over these files, so entry points, tools and tests are unchanged. The functions are still global; they move into namespaced classes in later steps.
