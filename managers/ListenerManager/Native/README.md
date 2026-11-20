# managers/ListenerManager/Native

Ingress validation, request and response framing, protocol state and runtime helpers moved from lib/.

Files: `request.php` `synth_ingress.php` `synth_location.php` `synth_runtime.php` 

The matching `lib/` paths are thin `require_once` hooks over these files, so entry points, tools and tests are unchanged. The functions are still global; they move into namespaced classes in later steps.
