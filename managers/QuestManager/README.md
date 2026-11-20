# QuestManager

Namespace home for the quest-aware knowledge logic that lived in `lib/` (see [docs/QUEST-AWARE-KNOWLEDGE.md](../../docs/QUEST-AWARE-KNOWLEDGE.md)).
The old `lib/` paths are thin `require_once` hooks over these files, so every entry point, test and stored row is unchanged.

| File | What it holds | Old home |
| --- | --- | --- |
| `Native/quest_knowledge.php` | The derived, per-reader quest knowledge view and its prompt block. | `lib/quest_knowledge.php` |
| `Native/quest_access.php` | Reviewed catalogue access: canonical topics and the quest gate for World Knowledge. | `lib/quest_access.php` |
| `Native/quest_reaction.php` | Tracked-objective change detection and the quest reaction binding. | `lib/quest_reaction.php` |

The reads over `core_runtime_context`, `core_protocol_event` and `synth.actor_identities` stay as they are: those tables have no DAO yet.
