# Workers

Background jobs that need no player interaction (memory, relationships, profile completion, TTS
queues). One folder per worker: `workers/<Name>/`, namespace `Synth\Workers\<Name>\*`.

## Rules every worker follows

1. **One run, bounded work.** A run handles a limited number of units or seconds, then exits. It never
   loops or sleeps; repeating is the supervisor's job (`bin/`).
2. **Progress comes from the data.** No private cursor: compare the sources with what already exists
   and do only what is missing, so a repeated or interrupted run is harmless.
3. **Results are written once**, in one short statement or transaction, under a condition that rejects
   duplicates. Discard an answer that a save load or switch made stale.
4. **No work, no cost.** Nothing pending means no LLM or TTS call.
5. **Failures wait.** Retry later with a growing delay instead of looping.
6. **Layering.** Workers call managers and `core/entities/system` classes, and drivers through
   managers. They never call `api/`, never contain SQL, and hold no transaction across an LLM, TTS or
   HTTP call.

## Framework

| Piece | Role |
| --- | --- |
| `WorkerInterface` | `name()`, `description()`, `pendingUnits()`, `run(WorkerLimits): WorkerReport`. |
| `WorkerLimits` | Unit and second budget of one run. A started unit always finishes. |
| `WorkerReport` | Outcome of a run: `done` (exit 0), `more_pending` (3), `idle` (4), `failed` (1), as one JSON line. |
| `WorkerRegistry` | Name to class map. A new worker is added to its `WORKERS` constant and nowhere else. |
| `Supervisor/WorkerScheduler` | Pure scheduling: repeat pending work soon, back off when idle, wake later workers after progress. |
| `Supervisor/WorkerSupervisor` | Launches `bin/worker.php <name>` per due worker as a child process; heartbeat `log/worker-supervisor.json`, lock `log/worker-supervisor.lock`. |

The framework is **storage-agnostic**: no class here opens a database connection or names a table. A
worker decides where its progress lives (job leases today; whatever the schema redesign chooses
later), so the contracts survive that change unchanged.

## Registered workers

| Name | Class | Wraps (legacy, unchanged) |
| --- | --- | --- |
| `memory` | `Memory/MemoryWorker` | `service/memory_dispatch.php` |
| `relationship` | `Relationships/RelationshipWorker` | `service/relationship_dispatch.php` |
| `extension-events` | `ExtensionEvents/ExtensionEventsWorker` | native: drains `ExtensionManager`'s `FileEventQueue` (no database) |

`memory` and `relationship` extend `Legacy/LegacyDispatchWorker`, a thin adapter: each pass launches the dispatch script in a
fresh CLI process (it opens its own connection), counts a pass as one unit when the script prints its
completion line, and treats a non-zero exit as a failed unit without relaying any output. The legacy
dispatch scripts still reserve and lease their own jobs, so running them through the supervisor and
through `service/manager.php` at once is safe but redundant. `pendingUnits()` returns 1, because a
dispatch script cannot count without reserving work; `--check` therefore only says "a pass is worth
trying". A native worker class replaces its adapter when the logic moves out of `service/`.

TTS (`service/tts_worker.php`) and profile workers are SYNTH-specific and are not registered yet.

## Commands

```text
php bin/worker.php list
php bin/worker.php <name> --check
php bin/worker.php <name> [--max-units=N] [--max-seconds=S] [--loop [--sleep=S]]
php bin/worker-supervisor.php [--workers=a,b] [--idle-seconds=30] [--max-idle-seconds=300] [--max-seconds=120]
```

Nothing starts the supervisor by default. `service/start.sh` and `service/manager.php` are unchanged,
so runtime behaviour is the same until a deployment chooses to run the supervisor.

## Legacy

`service/` (`manager.php`, `*_worker.php`, `*_dispatch.php`, `processors/`) and the `*_worker.php`
scripts in `processor/` are frozen for new features. A new worker is a class here, started from
`bin/`; the legacy supervisor gets at most a thin hook that launches it.
See [docs/SERVER-FILE-LAYOUT.md](../docs/SERVER-FILE-LAYOUT.md).
