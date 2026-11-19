# Memory workers

- `MemoryWorker`: the registered `memory` worker; launches `service/memory_dispatch.php`.
- `MiddleTermWorker`: the registered `middleterm` worker; runs the manager task `middleterm`.
- `MemoryRunner`: the memory work itself. `dispatch()` runs one native memory dispatch pass; `runJob()` runs one
  persisted job (`service/memory_worker.php <job-id>`). Both service scripts are thin hooks.
- `Native/`: memory job and dispatch (`synth_memory_worker`, `synth_memory_dispatch`), diary jobs
  (`synth_diary_jobs`), middle-term jobs (`synth_middleterm_jobs`), and the legacy middle-term task
  (`middleterm_task.php`, `middleterm_generate.php`, formerly `service/processors/middleterm/`).
  Recall, ranking, diary state and middle-term policy are in `managers/MemoryManager/Native/`.
