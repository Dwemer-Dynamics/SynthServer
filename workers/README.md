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

## Legacy

`service/` (`manager.php`, `*_worker.php`, `*_dispatch.php`, `processors/`) and the `*_worker.php`
scripts in `processor/` are frozen for new features. A new worker is a class here, started from
`bin/`; the legacy supervisor gets at most a thin hook that launches it.
See [docs/SERVER-FILE-LAYOUT.md](../docs/SERVER-FILE-LAYOUT.md).
