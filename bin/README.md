# bin

Command-line entry points: `worker.php` (run one worker once), a worker supervisor, notification and
maintenance scripts. Scripts here have no namespace and stay thin: parse arguments, call a worker
or manager, print a result and exit with a status code. They contain no business rules and no SQL.

Deployment and developer helpers (`deploy-*.ps1`, schema and import tools) stay in `tools/`. The
worker supervisor here is the default runtime, started by `service/start.sh`; `service/manager.php` runs only
with `SYNTH_LEGACY_MANAGER=1` for one release (see [workers/README.md](../workers/README.md)). See [docs/SERVER-FILE-LAYOUT.md](../docs/SERVER-FILE-LAYOUT.md).

`notify-server.php` runs the optional wake-up channel (`managers/Notification/README.md`). It is not started by default.
