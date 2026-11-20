# Legacy core classes

The global-namespace connector, profile, NPC master, narrator and player classes that lived in `lib/core/`. They sit beside the DAOs that replace them (`ApiBadgeDao`, `LlmConnectorDao`, `SttConnectorDao`, `IttConnectorDao`, `TtsConnectorDao`, `ProfileDao`, `NpcActorDao`) and the `ConfigManager` scopes for narrator and player settings. The old `lib/core/*.class.php` paths are thin `require_once` hooks, so callers and tests are unchanged.

This move does not change behaviour. Each class's row access moves onto its DAO in a later step, one class at a time, with the legacy class shrinking to a hook.
