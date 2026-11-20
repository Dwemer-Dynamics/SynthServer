# managers/MessageHandler/Native

Ingress validation, request and response framing, protocol state and runtime helpers moved from lib/.

Files: `eventlog_helper.php` `response.php` `synth_protocol_state.php` 

The matching `lib/` paths are thin `require_once` hooks over these files, so entry points, tools and tests are unchanged. The functions are still global; they move into namespaced classes in later steps.
