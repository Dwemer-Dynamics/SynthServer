# Relationships manager

Namespace `Synth\Managers\Relationships`. One home for everything relationship-related; the old `lib/` and
`ext/relationship_system/` paths are thin `require_once`/`include` hooks over these files (they stay so existing
entry points, tests and the hard includes in `main_synth_pipeline.php` and `ui/core/npc_master.php` keep working).

| Folder / file | What it holds | Old home |
| --- | --- | --- |
| `AuthoredRelationships.php` | Authored, directed NPC-to-NPC relationship config. Validates rows, picks present or mentioned targets, bounds and escapes the authored text, renders the `[AUTHORED RELATIONSHIPS]` prompt block, defines precedence with the evaluator. Phase A is behind `AUTHORED_RELATIONSHIPS_ENABLED` and reads a fixture (`AUTHORED_RELATIONSHIPS_FIXTURE`); storage arrives with the schema redesign. | (new) |
| `Native/` | The protocol-based evaluator's manager side: relationship state read/publish, graph scope, policy, control, manual and result state, pair evidence, responder, response capture, prompt context and the runtime settings helper (`synth_relationship*.php`, `relationship_response.php`, `relationship_runtime.php`). | `lib/` |
| `Legacy/RelationshipManager.php` | The global `RelationshipManager` class: tiers, types, `#REL` command parsing, context text from `core_npc_master` extended data. The native evaluator still calls it. | `lib/relationship_manager.php` |
| `Legacy/*.php` (the rest) | The `relationship_system` extension: `context_pre`, `postrequest`, the async queue and daemon `worker`, `relationship_llm`, the editor and the `analyze_relationships` endpoint. | `ext/relationship_system/` |

The worker side (jobs, dispatch, interaction queue and results) is in
[`workers/Relationships/`](../../workers/Relationships/). Stored data, SQL, prompts and behaviour are unchanged by
the move; only file locations and `__DIR__`-relative requires changed.

Design, bounds and the storage addendum for authored relationships:
[docs/AUTHORED-RELATIONSHIPS.md](../../docs/AUTHORED-RELATIONSHIPS.md).
