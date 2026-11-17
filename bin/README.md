# bin

Command-line entry points: `worker.php` (run one worker once), a worker supervisor, notification and
maintenance scripts. Scripts here have no namespace and stay thin: parse arguments, call a worker
or manager, print a result and exit with a status code. They contain no business rules and no SQL.

Deployment and developer helpers (`deploy-*.ps1`, schema and import tools) stay in `tools/`. The
legacy launcher `service/manager.php` and `service/start.sh` stay until the worker supervisor lands
here. See [docs/SERVER-FILE-LAYOUT.md](../docs/SERVER-FILE-LAYOUT.md).
