# MemoryManager

Namespace `Synth\Managers\MemoryManager`. One home for the memory, diary and middle-term logic that was spread
over `lib/`. The old `lib/` paths are thin `require_once` hooks over these files, so every entry point, test and
stored row is unchanged.

| Folder | What it holds | Old home |
| --- | --- | --- |
| `Native/synth_memory.php`, `synth_memory_recall.php` | Memory visibility, ordering, source evidence and recall (`core_memory`, `core_memory_log`). | `lib/` |
| `Native/memory_ranking.php`, `memory_helper_vectordb.php` | Ranking and the embedding/vector helper used by recall and `debug/util_memory_subsystem.php`. | `lib/` |
| `Native/synth_diary_{state,scope,config,browser,status}.php` | Diary state, scope, settings, browser and request status. | `lib/` |
| `Native/synth_middleterm.php`, `_approval`, `_scope`, `_regeneration` | Middle-term narrative read model, approval and regeneration policy. | `lib/` |

The worker side (memory jobs and dispatch, diary jobs, middle-term jobs) is in
[`workers/Memory/`](../../workers/Memory/). The functions are moved, not rewritten: they still use the
request-scoped `$db` helper and SQL. Moving them onto `core/entities/system` classes waits for the memory DAOs
from the schema redesign (`core_memory`, `core_memory_log`, `core_context_event`), none of which exist yet.

chevo splits the same work into `workers/MemoryContext`, `MemorySources`, `MemoryEmbedding` and `MiddleTerm`
around a `managers/MemoryManager` interface; SYNTH keeps `workers/Memory/` (`MemoryWorker`, `MiddleTermWorker`)
until the DAOs land.
