# drivers/tts/Native

Speech, voice, audio and provider helpers moved from lib/.

Files: `fuz_convert.php` `media_store.php` `wav_utils.php` 

The matching `lib/` paths are thin `require_once` hooks over these files, so entry points, tools and tests are unchanged. The functions are still global; they move into namespaced classes in later steps.
