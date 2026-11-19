# Relationships worker

- `RelationshipWorker`: the registered `relationship` worker; launches `service/relationship_dispatch.php`.
- `RelationshipRunner`: the work itself. `dispatch()` runs the inline, evaluation and interaction lanes once;
  `runJob()` runs one persisted job (`service/relationship_worker.php <job-id>`). Both service scripts are thin hooks.
- `Native/`: the job reservation, dispatch, provider-call and interaction-queue functions (`synth_relationship_*`
  worker files) formerly in `lib/`. They read and publish state through `managers/Relationships/Native/`.
- The manager's `native_relationship` entry point (`service/processors/native_relationship`) is a thin hook over the
  registry.
