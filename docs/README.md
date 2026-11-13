# Synthserver documentation index

Where to find the current notes for each part of the server, and the source and tests behind
them. This index only points to existing documents. Status and open checks stay in
[HANDOFF.md](HANDOFF.md) and [ALPHA-ACCEPTANCE.md](ALPHA-ACCEPTANCE.md); an HTTP or PHPUnit check
is never in-game evidence.

- [NPC-BIOGRAPHY-REPAIR.md](NPC-BIOGRAPHY-REPAIR.md): authored layout, guarded existing-profile repair and runtime SPECIAL ownership.

## Start here

- [README](../README.md): topology, install, configuration, tests and release audit.
- [CONTRIBUTING](../CONTRIBUTING.md): branches, rules, code and comment conventions, PR checks.
- [AGENTS](../AGENTS.md): engineering and data-safety rules.
- [HANDOFF.md](HANDOFF.md): current source, runtime topology, deferred work.
- [SERVER-FILE-LAYOUT.md](SERVER-FILE-LAYOUT.md): which folder owns what.
- [PROTOCOL.md](PROTOCOL.md) and [protocol/README.md](../protocol/README.md): the wire contract
  mirrored with the SYNTH client.

## Components

Paths are relative to the repository root. Tests are in `unittests/tests/` unless shown
otherwise. "Nearest tests" are suites that exercise that code; they do not prove the whole
feature. "No dedicated note" means the behavior is described only in the handoff, matrix or
source comments.

| Component | Notes | Source entry points | Nearest tests |
|---|---|---|---|
| Ingress and protocol | [PROTOCOL.md](PROTOCOL.md), [json-data-transfer-audit.md](json-data-transfer-audit.md) | `main.php`, `lib/synth_ingress.php`, `lib/synth_protocol_state.php`, `protocol/` | `JsonSchemaCatalogTest`, `RequestStreamingTest`; CI protocol manifest check |
| Native v2 dialogue (`/talk`, speech receipts) | No dedicated note; [HANDOFF.md](HANDOFF.md), [ALPHA-ACCEPTANCE.md](ALPHA-ACCEPTANCE.md) | `api/v2/routes.php`, `api/v2/dispatch.php`, `bootstrap.php`, `managers/`, `core/`, `drivers/` | `NativeDialogueTurnTest`; `unittests/loopback/` (opt-in) |
| Legacy dialogue pipeline | [FEATURE-PARITY-MATRIX.md](FEATURE-PARITY-MATRIX.md), [MIGRATION-SOURCE-AUDIT.md](MIGRATION-SOURCE-AUDIT.md) | `main_synth_pipeline.php`, `processor/`, `prompts/`, `connector/` | `ResponseRuntimeRegressionTest`, `RequestStreamingTest` |
| Characters and authored content | [FALLOUT-CONTENT.md](FALLOUT-CONTENT.md), [FALLOUT-CATALOGUE-SQL.md](FALLOUT-CATALOGUE-SQL.md), [CHARACTER-COMPLETION.md](CHARACTER-COMPLETION.md), [NATIVE-NPC-PROFILE-OWNERSHIP.md](NATIVE-NPC-PROFILE-OWNERSHIP.md), [OBSERVED-EQUIPMENT.md](OBSERVED-EQUIPMENT.md) | `lib/fallout_catalogue_sql.php`, `lib/fallout_content_pack.php`, `lib/synth_profile_jobs.php`, `lib/core/npc_master.class.php`, `tools/build-fallout-content-sql.py` | `FalloutCatalogueSqlTest`, `CharacterCompletionCatalogTest`, `EquipmentPromptTest` |
| World knowledge and quests | [WORLDKNOWLEDGE-PARITY-V1.md](WORLDKNOWLEDGE-PARITY-V1.md), [LORE-ACCURACY.md](LORE-ACCURACY.md), [QUEST-AWARE-KNOWLEDGE.md](QUEST-AWARE-KNOWLEDGE.md), [QUEST-REACTIONS.md](QUEST-REACTIONS.md) | `lib/worldknowledge_*.php`, `lib/quest_knowledge.php`, `lib/quest_access.php`, `lib/quest_reaction.php` | `WorldKnowledgeTest`, `QuestKnowledgeTest`, `QuestAccessPolicyTest` |
| Memory and middle-term narrative | [NATIVE-MEMORY-RECOVERY.md](NATIVE-MEMORY-RECOVERY.md), [NATIVE-MIDDLETERM-MIGRATION.md](NATIVE-MIDDLETERM-MIGRATION.md) | `lib/synth_memory*.php`, `lib/synth_middleterm*.php`, `service/processors/native_memory/`, `service/processors/middleterm/` | `MemoryTest`, `NativeMemoryRecoveryTest` |
| Relationships | [NATIVE-RELATIONSHIP-MIGRATION.md](NATIVE-RELATIONSHIP-MIGRATION.md) | `lib/synth_relationship*.php`, `service/relationship_worker.php` | `NativeRelationshipDispatchTest`, `RelationshipRuntimeTest` |
| Rechat and idle exchanges | [NATIVE-RECHAT-OWNERSHIP.md](NATIVE-RECHAT-OWNERSHIP.md) | `lib/synth_rechat_*.php` | `NativeTriggerHistoryPromptTest`, `ResponseRuntimeRegressionTest` |
| Diaries | [NATIVE-DIARY-MIGRATION.md](NATIVE-DIARY-MIGRATION.md), [NATIVE-DIARY-INSPECTION.md](NATIVE-DIARY-INSPECTION.md) | `lib/synth_diary_*.php`, `ui/diaries.php`, `ui/diary_book.php` | `DiaryTest`, `DiaryBrowserTest` |
| Actions, holds and combat | [JEV-ACTIONS.md](JEV-ACTIONS.md), [CONVERSATION-HOLDS.md](CONVERSATION-HOLDS.md), [COMBAT-TARGETS.md](COMBAT-TARGETS.md) | `functions/`, `lib/core/action_catalog.php`, `lib/synth_wait_actions.php`, `lib/synth_jev_actions.php`, `lib/synth_attack_actions.php`, `lib/synth_combat_target.php` | `ActionCatalogTest`, `NativeWaitActionTest`, `JevActionTest`, `ConversationHoldTest`, `CombatTargetTest` |
| Follower errands (social tasks) | No dedicated note; [HANDOFF.md](HANDOFF.md#saved-follower-conversation-tasks-candidate) | `lib/synth_social_tasks.php`, `lib/synth_social_contract.php` | `SocialTaskContractTest`, `SocialTaskStateTest` |
| Speech (TTS, STT, voices) | [VOICE-CLONING.md](VOICE-CLONING.md), [CURATED-VOICE-CATALOG.md](CURATED-VOICE-CATALOG.md), [INWORLD-VOICE-REPAIR.md](INWORLD-VOICE-REPAIR.md), [CARTESIA-PROSODY.md](CARTESIA-PROSODY.md) | `tts/`, `stt/`, `lib/synth_tts*.php`, `lib/synth_stt_pipeline.php`, `service/tts_worker.php`, `vsx.php` | `CanonicalTtsBindingTest`, `CartesiaProsodyTest`, `SttOutcomeTest` |
| Background processor and health | [HANDOFF.md](HANDOFF.md#runtime-topology) | `service/manager.php`, `service/start.sh`, `lib/background_processor.php`, `health.php` | `BackgroundProcessorTest` |
| Database schema and migrations | [CONTRIBUTING](../CONTRIBUTING.md#rules), [HANDOFF.md](HANDOFF.md#fresh-install) | `debug/db_updates.php`, `lib/core/database_schema/`, `data/database_default.sql`, `tools/bootstrap-database.php` | `DatabaseTestCase` suites (disposable PostgreSQL only) |
| LLM providers | [CODEX-LOCAL-PROVIDER.md](CODEX-LOCAL-PROVIDER.md) | `connector/`, `lib/core/llm_connector.class.php`, `drivers/llm/OpenAiCompatibleDriver.php`, `tools/codex_bridge.py` | `tools/test_codex_*.py` |
| Install, deploy and launchers | [README](../README.md#install), [WINDOWS-LAUNCHER.md](WINDOWS-LAUNCHER.md) | `tools/create-synth-db-wsl.ps1`, `tools/deploy-synthserver-wsl.ps1`, `start-dev.ps1`, `tools/windows-launcher/` | Launcher tests only: `tools/windows-launcher/Tests/`, `tools/codex-launcher/Test-Startup.ps1`, `tools/test_codex_startup.py`; install and deploy scripts have no automated test |
| Management UI | No dedicated note; [HANDOFF.md](HANDOFF.md#roleplay-page-cleanup) | `ui/`, `ui/api/`, `ui/core/` | `DashboardContentCatalogTest` |
| Release-tree audit and publication | [server-release-file-audit.md](server-release-file-audit.md), [PUBLIC-SNAPSHOT.md](PUBLIC-SNAPSHOT.md), [THIRD_PARTY_NOTICES.md](../THIRD_PARTY_NOTICES.md) | `tools/audit-release-tree.ps1` | The audit itself |

Cross-cutting status: [FEATURE-PARITY-MATRIX.md](FEATURE-PARITY-MATRIX.md). Off-game client/server
check: [unittests/loopback/README.md](../unittests/loopback/README.md).

## Documentation audit (2026-11-13)

- **Current entry points.** README, CONTRIBUTING, AGENTS, HANDOFF, ALPHA-ACCEPTANCE, PROTOCOL and
  SERVER-FILE-LAYOUT agree on branch policy, loopback topology and evidence boundaries.
- **Feature notes.** Most notes are per-feature records with scope, evidence and open checks.
  The table above supplies source entry points where a note names none.
- **Retired paths.** [NATIVE-DIARY-INSPECTION.md](NATIVE-DIARY-INSPECTION.md),
  [NATIVE-MIDDLETERM-MIGRATION.md](NATIVE-MIDDLETERM-MIGRATION.md),
  [NATIVE-RELATIONSHIP-MIGRATION.md](NATIVE-RELATIONSHIP-MIGRATION.md) and one paragraph of
  [PROTOCOL.md](PROTOCOL.md) describe `ui/api/native*.php` endpoints that are no longer in the
  tree. The [handoff](HANDOFF.md#roleplay-page-cleanup) records their retirement.
  [JEV-ACTIONS.md](JEV-ACTIONS.md) cites `lib/stt_target_jev.php` as a HerikaServer reference,
  not a local file.
- **Gaps.** No dedicated note for native v2 dialogue (`api/v2/`, `managers/`), follower errands
  or the management UI. The class comments in `managers/` and `core/dbmanager/repositories/` are
  currently the best description of the native dialogue owners.
- **Reference guidance** reused or excluded for the conventions:
  [provenance/CURRENT-HERIKA-ALIGNMENT.md](provenance/CURRENT-HERIKA-ALIGNMENT.md#engineering-guidance-references).

## Keeping docs current

Update the feature note, the handoff and the acceptance checklist; do not start a chronological
ledger. Add a row here only when a component gains a note or a new source root. Use relative
links with the exact Git path case (for example `docs/HANDOFF.md`, `docs/provenance/`).
