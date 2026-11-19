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
| `relationship` | `Relationships/RelationshipWorker` | `service/relationship_dispatch.php` (thin hook over `Relationships/RelationshipRunner`) |
| `middleterm` | `Memory/MiddleTermWorker` | manager task `middleterm`, via `service/task_dispatch.php` |
| `dynamicprofile` | `Profiles/DynamicProfileWorker` | manager task `dynamicprofile`, via `service/task_dispatch.php` |
| `player2health` | `Player2/Player2HealthWorker` | manager task `player2health`, via `service/task_dispatch.php` |
| `extension-events` | `ExtensionEvents/ExtensionEventsWorker` | native: drains `ExtensionManager`'s `FileEventQueue` (no database) |
| `tts` | `Speech/SpeechWorker` | `service/tts_dispatch.php --loop --max-seconds=N` (one dispatch window per run) |
| `profile` | `Profiles/ProfileAutofillWorker` | `processor/profile_autofill_worker.php`, on demand only |

All but `extension-events` extend `Legacy/LegacyDispatchWorker` (the task workers through `Legacy/LegacyTaskWorker`), a thin adapter: each pass launches the dispatch script in a
fresh CLI process (it opens its own connection), counts a pass as one unit when the script prints its
completion line, and treats a non-zero exit as a failed unit without relaying any output. The legacy
dispatch scripts still reserve and lease their own jobs, so running them through the supervisor and
through `service/manager.php` at once is safe but redundant. `pendingUnits()` returns 1, because a
dispatch script cannot count without reserving work; `--check` therefore only says "a pass is worth
trying". A native worker class replaces its adapter when the logic moves out of `service/`.

`service/tts_worker.php <job-id>` (one speech job), `service/memory_worker.php` and `service/relationship_worker.php` (the latter is a hook into `Relationships/RelationshipRunner`)
are the per-job runners that the dispatchers launch; they are the work itself and stay where they are.

## Lanes and cadence

The supervisor runs one **lane** per process (`WorkerRegistry::LANES`), so slow work in one lane never delays another:

| Lane | Workers | Cadence (`WorkerRegistry::FIXED_SECONDS`) |
| --- | --- | --- |
| `general` | `memory`, `relationship`, `middleterm`, `dynamicprofile`, `player2health`, `extension-events` | 5 seconds after each run, what the manager's tick was |
| `speech` | `tts` | 1 second after each run (10-second dispatch window, the dispatcher polls every 0.5 s inside it) |

`profile` is in no lane: `synthSpawnProfileAutofillWorker` starts `php bin/worker.php profile --job=<file>` detached for each
request, as it started the script before. A worker without a fixed cadence uses the scheduler's idle back-off.
Each lane has its own lock and heartbeat: `log/worker-supervisor.{lock,json}` (general) and
`log/worker-supervisor-speech.{lock,json}`.

## Commands

```text
php bin/worker.php list
php bin/worker.php <name> --check
php bin/worker.php <name> [--max-units=N] [--max-seconds=S] [--loop [--sleep=S]]
php bin/worker-supervisor.php [--workers=a,b] [--idle-seconds=30] [--max-idle-seconds=300] [--max-seconds=120]
```

## Runtime

`service/start.sh` is the one launcher (the launcher and `lib/background_processor.php` start it and probe
the heartbeat port 12348 that it keeps bound, unchanged). It starts the `speech` and `general` supervisors, each
restarted if it exits. `SYNTH_LEGACY_MANAGER=1` (or `start.sh --legacy-manager`) starts the previous runtime instead,
the shell speech loop plus `service/manager.php`; it stays for one release and is also used when
`vendor/autoload.php` is missing. Do not run both at once: the legacy manager's `native_memory` and
`native_relationship` tasks are hooks over the same registry workers.

## Legacy

`service/` (`manager.php`, `*_worker.php`, `*_dispatch.php`, `processors/`) and the `*_worker.php`
scripts in `processor/` are frozen for new features. A new worker is a class here, started from
`bin/`; the legacy supervisor gets at most a thin hook that launches it.
See [docs/SERVER-FILE-LAYOUT.md](../docs/SERVER-FILE-LAYOUT.md).
