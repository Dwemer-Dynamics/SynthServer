# WorldKnowledgeManager (namespace home)

The `Synth\Managers\WorldKnowledgeManager` class stays in `managers/WorldKnowledgeManager.php`. This folder holds the World Knowledge and Fallout content logic that lived in `lib/`; the old `lib/` paths are thin `require_once` hooks over these files, so entry points, tools and tests are unchanged.

| Folder | What it holds | Old home |
| --- | --- | --- |
| `Native/worldknowledge_catalog.php`, `_topic.php`, `_vocabulary.php` | The shipped factory catalogue and its validation, topic identifiers, everyday vocabulary. | `lib/` |
| `Native/worldknowledge_runtime.php`, `_retrieval.php`, `_forced_context.php`, `_llm_service.php` | Effective settings, deterministic retrieval, forced context and the LLM topic extractor. | `lib/` |
| `Fallout/fallout_content_pack.php`, `_catalog.php`, `_stats.php`, `fallout_catalogue_sql.php` | The Fallout content pack installers and catalogue SQL connection. | `lib/` |
