# Notification

The optional wake-up channel: a small process that tells a connected SYNTH plugin to look again. It is a hint and
nothing more.

- **Owns:** the plugin-facing WebSocket listener and the loopback publish listener (`NotifyServer`, run by
  `bin/notify-server.php`); the best-effort publisher the server's own workers call (`NotifyPublisher`); the closed hint
  frames (`NotifyHint`); where it listens (`NotifyConfig`).
- **Carries:** `{"schema":"synth.notify.hint.v1","type":"welcome"}` once per connection and
  `{"type":"wake","topic":"speech"}` when stored speech state changed. Schema:
  `protocol/v2/schemas/notify-hint.schema.json`. No message text, no identifiers, no secrets.
- **Refuses:** any publish that is not `POST /notify` with a known topic; any plugin frame it would have to interpret
  (plugin frames are never read, only ping and close are answered); more than 16 clients; a publish listener that is
  not loopback.
- **Does not:** replace fetching. The plugin still reads status, text and audio over the ordinary HTTP protocol and
  keeps polling as the default and the fallback.

## Capability negotiation

A client lists `notify.wake` in a v2 `init` only when its INI opts in. The init status then carries
`notify_wake: {"port": N}` only while the channel is running (`NotifyPublisher::acknowledgedPort()` probes the loopback
publish port). An old server never sends it, an old client never asks for it, and a server whose channel is stopped
sends nothing, so the client polls exactly as before in every case.

## Running it

```sh
php bin/notify-server.php                      # plugin port 8091 on 127.0.0.1, publish port 8092 on 127.0.0.1
php bin/notify-server.php --host=0.0.0.0       # remote hosting: bind where the remote-hosting guide binds the server
```

`SYNTH_NOTIFY_HOST`, `SYNTH_NOTIFY_PORT` and `SYNTH_NOTIFY_INTERNAL_PORT` set the same values for a launcher. The
default is loopback. For a gaming PC reaching a server PC, bind the plugin port to the address the remote-hosting guide
uses for the server (typically `0.0.0.0` inside WSL behind a Windows port rule, as for the HTTP port) and open only that
port; never forward the publish port. The channel has no authentication because a frame carries nothing a listener could
use. The plugin connects to the host of its `BaseUrl` on the acknowledged port.

`service/manager.php` and `service/start.sh` do not start it. Start it yourself, or leave it off.

## Publishers

`service/tts_worker.php` publishes `speech` when a speech job reaches any terminal state, after its status is stored.
`NotifyPublisher::publish()` returns false quickly when the channel is absent and never throws.
