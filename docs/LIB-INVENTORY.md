# lib/ inventory

`lib/` should hold only a few small final classes (chevo keeps six). This lists every file under `lib/` and where it goes. The slices `L1` onward are the planned reviewable moves; each leaves a thin hook at the old path for legacy callers, and moved code that runs raw SQL on a table that has a DAO switches to the DAO. No tables are added by this work (the schema session owns migrations).

Kinds: **keep**, **hook (done)** (already a thin hook over a moved file), **manager**, **entity / DAO**, **driver**, **Synth\Lib class**, **split**, **schema (owned elsewhere)**.

## Summary

| Kind | Files |
| --- | --- |
| manager | 108 |
| hook (done) | 40 |
| schema (owned elsewhere) | 23 |
| driver | 10 |
| entity / DAO | 9 |
| Synth\Lib class | 9 |
| keep (lib class) | 6 |
| keep (bootstrap) | 2 |
| split | 2 |
| driver / db adapter | 2 |
| keep (shim) | 1 |
| **total** | 212 |

## Planned slices

| Slice | Scope |
| --- | --- |
| L0 | this inventory |
| L1 | `quest_*` to `managers/QuestManager` (done) |
| L2 | `worldknowledge_*` and `fallout_*` to `managers/WorldKnowledgeManager` (done) |
| L3 | `core/*.class.php` connectors, profiles and NPC master onto the existing DAOs (moved; DAO adoption pending) |
| L4 | `visual_context*` to `managers/VisualContext` (done) |
| L5 | rechat, auto chat, greeting, management guard (done) |
| L6 | tts, voice, stt, itt, audio and LLM driver helpers (done) |
| L7 | status, backup, save state, profile workers, supervisor, plugins (done) |
| L8 | actions: catalogue, jev, attack, wait, gift, social, combat (done) |
| L9 | prompt, context, character helpers (done) |
| L10 | ingress, request, response, protocol state, runtime (done) |
| L11 | small `Synth\Lib` helpers and GameTime (done) |
| L12 | split `chat_helper_functions.php` and `data_functions.php` |

## Files

| File | Lines | Kind | Target | Slice |
| --- | --- | --- | --- | --- |
| `lib/LocalAccess.php` | 156 | keep (lib class) | stays; one of the small final classes chevo keeps in lib/ | - |
| `lib/PlayerName.php` | 58 | keep (lib class) | stays; one of the small final classes chevo keeps in lib/ | - |
| `lib/PronunciationDictionary.php` | 196 | keep (lib class) | stays; one of the small final classes chevo keeps in lib/ | - |
| `lib/RequestBody.php` | 59 | keep (lib class) | stays; one of the small final classes chevo keeps in lib/ | - |
| `lib/SameOriginRequest.php` | 92 | keep (lib class) | stays; one of the small final classes chevo keeps in lib/ | - |
| `lib/actor_event.php` | 4 | hook (done) | `managers/ContextManager/Native/actor_event.php` | done (L9) |
| `lib/auditing.php` | 4 | hook (done) | `managers/ServerStatus/Native/auditing.php` | done (L7) |
| `lib/auto_greeting.php` | 4 | hook (done) | `managers/Conversation/Rechat/auto_greeting.php` | done (L5) |
| `lib/autoload.php` | 14 | keep (bootstrap) | stays until every caller uses `Synth\Bootstrap`; `lib/autoload.php` is the one autoloader entry | - |
| `lib/automatic_backup.php` | 4 | hook (done) | `managers/ServerStatus/Native/automatic_backup.php` | done (L7) |
| `lib/background_processor.php` | 4 | hook (done) | `workers/Supervisor/Native/background_processor.php` | done (L7) |
| `lib/cartesia_generation.php` | 4 | hook (done) | `managers/TtsStudio/Native/cartesia_generation.php` | done (L6) |
| `lib/chat_helper_functions.php` | 5589 | split | split by concern into the managers above (diary/context/prompt/profile/memory blocks, tokens, name helpers), leaving a hook; the two biggest files, done last | L12 |
| `lib/classes/Logger.php` | 568 | keep (lib class) | stays; one of the small final classes chevo keeps in lib/ | - |
| `lib/compact_context_history.php` | 4 | hook (done) | `managers/ContextManager/Native/compact_context_history.php` | done (L9) |
| `lib/core/action_catalog.php` | 4 | hook (done) | `managers/ActionsManager/Native/action_catalog.php` | done (L8) |
| `lib/core/activity_status.php` | 4 | hook (done) | `managers/ServerStatus/Native/activity_status.php` | done (L7) |
| `lib/core/api_badge.class.php` | 4 | hook (done) | `core/dbmanager/Legacy/api_badge.class.php` | done (L3) |
| `lib/core/core_profiles.class.php` | 4 | hook (done) | `core/dbmanager/Legacy/core_profiles.class.php` | done (L3) |
| `lib/core/database_schema/bio_template_actor_map.sql` | 55 | schema (owned elsewhere) | core/dbmanager/migrations (schema session); stays, no tables added here | schema session |
| `lib/core/database_schema/core_action.sql` | 94 | schema (owned elsewhere) | core/dbmanager/migrations (schema session); stays, no tables added here | schema session |
| `lib/core/database_schema/core_api_badge.sql` | 115 | schema (owned elsewhere) | core/dbmanager/migrations (schema session); stays, no tables added here | schema session |
| `lib/core/database_schema/core_itt_connector.sql` | 23 | schema (owned elsewhere) | core/dbmanager/migrations (schema session); stays, no tables added here | schema session |
| `lib/core/database_schema/core_llm_connector.sql` | 135 | schema (owned elsewhere) | core/dbmanager/migrations (schema session); stays, no tables added here | schema session |
| `lib/core/database_schema/core_narrator.sql` | 58 | schema (owned elsewhere) | core/dbmanager/migrations (schema session); stays, no tables added here | schema session |
| `lib/core/database_schema/core_npc_master.sql` | 150 | schema (owned elsewhere) | core/dbmanager/migrations (schema session); stays, no tables added here | schema session |
| `lib/core/database_schema/core_npc_master_history.sql` | 111 | schema (owned elsewhere) | core/dbmanager/migrations (schema session); stays, no tables added here | schema session |
| `lib/core/database_schema/core_player.sql` | 60 | schema (owned elsewhere) | core/dbmanager/migrations (schema session); stays, no tables added here | schema session |
| `lib/core/database_schema/core_profiles.sql` | 204 | schema (owned elsewhere) | core/dbmanager/migrations (schema session); stays, no tables added here | schema session |
| `lib/core/database_schema/core_stt_connector.sql` | 111 | schema (owned elsewhere) | core/dbmanager/migrations (schema session); stays, no tables added here | schema session |
| `lib/core/database_schema/core_tts_connector.sql` | 114 | schema (owned elsewhere) | core/dbmanager/migrations (schema session); stays, no tables added here | schema session |
| `lib/core/database_schema/core_tts_pronunciation.sql` | 26 | schema (owned elsewhere) | core/dbmanager/migrations (schema session); stays, no tables added here | schema session |
| `lib/core/database_schema/general_settings.sql` | 40 | schema (owned elsewhere) | core/dbmanager/migrations (schema session); stays, no tables added here | schema session |
| `lib/core/database_schema/global_settings_presets.sql` | 10 | schema (owned elsewhere) | core/dbmanager/migrations (schema session); stays, no tables added here | schema session |
| `lib/core/database_schema/playthrough_metadata.sql` | 31 | schema (owned elsewhere) | core/dbmanager/migrations (schema session); stays, no tables added here | schema session |
| `lib/core/database_schema/relationship_async_queues.sql` | 23 | schema (owned elsewhere) | core/dbmanager/migrations (schema session); stays, no tables added here | schema session |
| `lib/core/database_schema/synth_private_narrator.sql` | 116 | schema (owned elsewhere) | core/dbmanager/migrations (schema session); stays, no tables added here | schema session |
| `lib/core/database_schema/synth_protocol.sql` | 982 | schema (owned elsewhere) | core/dbmanager/migrations (schema session); stays, no tables added here | schema session |
| `lib/core/database_schema/synth_social_tasks.sql` | 32 | schema (owned elsewhere) | core/dbmanager/migrations (schema session); stays, no tables added here | schema session |
| `lib/core/database_schema/visual_context.sql` | 106 | schema (owned elsewhere) | core/dbmanager/migrations (schema session); stays, no tables added here | schema session |
| `lib/core/database_schema/worldknowledge_parity_v1.sql` | 226 | schema (owned elsewhere) | core/dbmanager/migrations (schema session); stays, no tables added here | schema session |
| `lib/core/game_plugins.php` | 4 | hook (done) | `managers/ExtensionManager/Native/game_plugins.php` | done (L7) |
| `lib/core/import_rules.class.php` | 4 | hook (done) | `core/dbmanager/Legacy/import_rules.class.php` | done (L3) |
| `lib/core/itt_connector.class.php` | 4 | hook (done) | `core/dbmanager/Legacy/itt_connector.class.php` | done (L3) |
| `lib/core/llm_connector.class.php` | 4 | hook (done) | `core/dbmanager/Legacy/llm_connector.class.php` | done (L3) |
| `lib/core/local_llm_setup.php` | 4 | hook (done) | `drivers/llm/Native/local_llm_setup.php` | done (L6) |
| `lib/core/narrator.class.php` | 4 | hook (done) | `core/dbmanager/Legacy/narrator.class.php` | done (L3) |
| `lib/core/npc_master.class.php` | 4 | hook (done) | `core/dbmanager/Legacy/npc_master.class.php` | done (L3) |
| `lib/core/player.class.php` | 4 | hook (done) | `core/dbmanager/Legacy/player.class.php` | done (L3) |
| `lib/core/settings_presets.php` | 264 | manager | `ConfigManager` (settings schema, presets, loaders); `settings.php` is the legacy loader that fills globals | config session |
| `lib/core/stt_connector.class.php` | 4 | hook (done) | `core/dbmanager/Legacy/stt_connector.class.php` | done (L3) |
| `lib/core/tts_connector.class.php` | 4 | hook (done) | `core/dbmanager/Legacy/tts_connector.class.php` | done (L3) |
| `lib/core/tts_studio_provider_detection.php` | 4 | hook (done) | `managers/TtsStudio/Native/tts_studio_provider_detection.php` | done (L6) |
| `lib/data_functions.php` | 8098 | split | split by concern into the managers above (diary/context/prompt/profile/memory blocks, tokens, name helpers), leaving a hook; the two biggest files, done last | L12 |
| `lib/db_connection_settings.php` | 59 | driver / db adapter | `core/dbmanager` adapter (the `sql` class); stays while raw SQL callers remain and shrinks as DAOs replace them | schema session |
| `lib/dynamic_update_util.php` | 4 | hook (done) | `workers/Profiles/Native/dynamic_update_util.php` | done (L7) |
| `lib/emote_moods.php` | 4 | hook (done) | `managers/CharacterManager/Native/emote_moods.php` | done (L9) |
| `lib/eventlog_helper.php` | 4 | hook (done) | `managers/MessageHandler/Native/eventlog_helper.php` | done (L10) |
| `lib/fallout_catalogue_sql.php` | 4 | hook (done) | `managers/WorldKnowledgeManager/Fallout/fallout_catalogue_sql.php` | done (L2) |
| `lib/fallout_content_catalog.php` | 4 | hook (done) | `managers/WorldKnowledgeManager/Fallout/fallout_content_catalog.php` | done (L2) |
| `lib/fallout_content_pack.php` | 4 | hook (done) | `managers/WorldKnowledgeManager/Fallout/fallout_content_pack.php` | done (L2) |
| `lib/fallout_stats.php` | 4 | hook (done) | `managers/WorldKnowledgeManager/Fallout/fallout_stats.php` | done (L2) |
| `lib/fuz_convert.php` | 4 | hook (done) | `drivers/tts/Native/fuz_convert.php` | done (L6) |
| `lib/itt_service.php` | 4 | hook (done) | `drivers/itt/Native/itt_service.php` | done (L6) |
| `lib/lazy_xml.php` | 4 | hook (done) | `drivers/llm/Native/lazy_xml.php` | done (L11) |
| `lib/legacy_row_management.php` | 4 | hook (done) | `core/dbmanager/Legacy/legacy_row_management.php` | done (L11) |
| `lib/llm_randomizer.php` | 4 | hook (done) | `drivers/llm/Native/llm_randomizer.php` | done (L6) |
| `lib/log_trim.php` | 4 | hook (done) | `managers/ServerStatus/Native/log_trim.php` | done (L7) |
| `lib/logger.php` | 47 | keep (shim) | global `Logger` shim over `Synth\Lib\Logger`; goes when callers use the namespaced class | - |
| `lib/management_request_guard.php` | 263 | Synth\Lib class | `Synth\Lib\ManagementRequestGuard` (next to LocalAccess and SameOriginRequest) | L5 |
| `lib/media_store.php` | 4 | hook (done) | `drivers/tts/Native/media_store.php` | done (L6) |
| `lib/memory_helper_vectordb.php` | 4 | hook (done) | `managers/MemoryManager/Native/memory_helper_vectordb.php` | done |
| `lib/memory_ranking.php` | 4 | hook (done) | `managers/MemoryManager/Native/memory_ranking.php` | done |
| `lib/minimet5_service.php` | 4 | hook (done) | `drivers/llm/Native/minimet5_service.php` | done (L6) |
| `lib/misc_ui_functions.php` | 157 | Synth\Lib class | `Synth\Lib` helpers (lazy XML, tokenizer cost, semaphore, misc UI, utils, dynamic model, legacy row management) | L11 |
| `lib/model_dynmodel.php` | 4 | hook (done) | `drivers/llm/Native/model_dynmodel.php` | done (L11) |
| `lib/narrator_actions.php` | 4 | hook (done) | `managers/ActionsManager/Native/narrator_actions.php` | done (L8) |
| `lib/npc_tts_status.php` | 4 | hook (done) | `managers/TtsStudio/Native/npc_tts_status.php` | done (L6) |
| `lib/observation_reaction.php` | 4 | hook (done) | `managers/ContextManager/Native/observation_reaction.php` | done (L9) |
| `lib/observed_dialogue.php` | 4 | hook (done) | `managers/ContextManager/Native/observed_dialogue.php` | done (L9) |
| `lib/player2_health.php` | 4 | hook (done) | `workers/Player2/Native/player2_health.php` | done (L7) |
| `lib/player_event.php` | 4 | hook (done) | `managers/ContextManager/Native/player_event.php` | done (L9) |
| `lib/player_reaction.php` | 4 | hook (done) | `managers/ContextManager/Native/player_reaction.php` | done (L9) |
| `lib/player_tts_helpers.php` | 4 | hook (done) | `managers/TtsStudio/Native/player_tts_helpers.php` | done (L6) |
| `lib/playthrough_schema.php` | 4 | hook (done) | `managers/SaveState/Native/playthrough_schema.php` | done (L7) |
| `lib/playthrough_snapshot.php` | 4 | hook (done) | `managers/SaveState/Native/playthrough_snapshot.php` | done (L7) |
| `lib/plugin_package_manager.php` | 4 | hook (done) | `managers/ExtensionManager/Native/plugin_package_manager.php` | done (L7) |
| `lib/postgresql.class.php` | 1172 | driver / db adapter | `core/dbmanager` adapter (the `sql` class); stays while raw SQL callers remain and shrinks as DAOs replace them | schema session |
| `lib/power_awareness.php` | 4 | hook (done) | `managers/ActionsManager/Native/power_awareness.php` | done (L8) |
| `lib/profile_autofill_async.php` | 4 | hook (done) | `workers/Profiles/Native/profile_autofill_async.php` | done (L7) |
| `lib/prompt_composition.php` | 4 | hook (done) | `managers/PromptManager/Native/prompt_composition.php` | done (L9) |
| `lib/prompt_injections.php` | 4 | hook (done) | `managers/PromptManager/Native/prompt_injections.php` | done (L9) |
| `lib/provider_stream.php` | 4 | hook (done) | `drivers/llm/Native/provider_stream.php` | done (L6) |
| `lib/quest_access.php` | 4 | hook (done) | `managers/QuestManager/Native/quest_access.php` | done (L1) |
| `lib/quest_knowledge.php` | 4 | hook (done) | `managers/QuestManager/Native/quest_knowledge.php` | done (L1) |
| `lib/quest_reaction.php` | 4 | hook (done) | `managers/QuestManager/Native/quest_reaction.php` | done (L1) |
| `lib/quickstart_service_choices.php` | 4 | hook (done) | `managers/TtsStudio/Native/quickstart_service_choices.php` | done (L6) |
| `lib/relationship_manager.php` | 4 | hook (done) | `managers/Relationships/Legacy/RelationshipManager.php` | done |
| `lib/relationship_response.php` | 4 | hook (done) | `managers/Relationships/Native/relationship_response.php` | done |
| `lib/relationship_runtime.php` | 4 | hook (done) | `managers/Relationships/Native/relationship_runtime.php` | done |
| `lib/request.php` | 4 | hook (done) | `managers/ListenerManager/Native/request.php` | done (L10) |
| `lib/response.php` | 4 | hook (done) | `managers/MessageHandler/Native/response.php` | done (L10) |
| `lib/rolemaster_bored.php` | 4 | hook (done) | `managers/ContextManager/Native/rolemaster_bored.php` | done (L9) |
| `lib/rpg_comment.php` | 4 | hook (done) | `managers/ActionsManager/Native/rpg_comment.php` | done (L8) |
| `lib/runtime_bootstrap.php` | 508 | keep (bootstrap) | stays until every caller uses `Synth\Bootstrap`; `lib/autoload.php` is the one autoloader entry | - |
| `lib/save_rollback.php` | 4 | hook (done) | `managers/SaveState/Native/save_rollback.php` | done (L7) |
| `lib/schema_clone_function.sql` | 305 | schema (owned elsewhere) | core/dbmanager/migrations (schema session); not moved here, no tables added | schema session |
| `lib/scriptproxy_fallout.php` | 4 | hook (done) | `managers/ActionsManager/Native/scriptproxy_fallout.php` | done (L8) |
| `lib/semaphore_manager.class.php` | 4 | hook (done) | `managers/ServerStatus/Native/semaphore_manager.class.php` | done (L11) |
| `lib/settings.php` | 1623 | manager | `ConfigManager` (settings schema, presets, loaders); `settings.php` is the legacy loader that fills globals | config session |
| `lib/speech_cache.php` | 4 | hook (done) | `managers/TtsStudio/Native/speech_cache.php` | done (L6) |
| `lib/synth_action_inventory.php` | 4 | hook (done) | `managers/ActionsManager/Native/synth_action_inventory.php` | done (L8) |
| `lib/synth_actor_identity_labels.php` | 4 | hook (done) | `managers/CharacterManager/Native/synth_actor_identity_labels.php` | done (L9) |
| `lib/synth_ambient_npc.php` | 4 | hook (done) | `managers/ContextManager/Native/synth_ambient_npc.php` | done (L9) |
| `lib/synth_attack_actions.php` | 4 | hook (done) | `managers/ActionsManager/Native/synth_attack_actions.php` | done (L8) |
| `lib/synth_audience_control.php` | 4 | hook (done) | `managers/ContextManager/Native/synth_audience_control.php` | done (L9) |
| `lib/synth_autochat.php` | 4 | hook (done) | `managers/Conversation/Rechat/synth_autochat.php` | done (L5) |
| `lib/synth_biography.php` | 4 | hook (done) | `managers/CharacterManager/Native/synth_biography.php` | done (L9) |
| `lib/synth_character_forms.php` | 4 | hook (done) | `managers/CharacterManager/Native/synth_character_forms.php` | done (L9) |
| `lib/synth_character_voice.php` | 4 | hook (done) | `managers/CharacterManager/Native/synth_character_voice.php` | done (L9) |
| `lib/synth_combat_target.php` | 4 | hook (done) | `managers/ActionsManager/Native/synth_combat_target.php` | done (L8) |
| `lib/synth_command_payload.php` | 4 | hook (done) | `managers/ActionsManager/Native/synth_command_payload.php` | done (L8) |
| `lib/synth_condition_prompt.php` | 4 | hook (done) | `managers/PromptManager/Native/synth_condition_prompt.php` | done (L9) |
| `lib/synth_control.php` | 4 | hook (done) | `managers/ContextManager/Native/synth_control.php` | done (L9) |
| `lib/synth_dialogue_policy.php` | 4 | hook (done) | `managers/ContextManager/Native/synth_dialogue_policy.php` | done (L9) |
| `lib/synth_diary_browser.php` | 4 | hook (done) | `managers/MemoryManager/Native/synth_diary_browser.php` | done |
| `lib/synth_diary_config.php` | 4 | hook (done) | `managers/MemoryManager/Native/synth_diary_config.php` | done |
| `lib/synth_diary_jobs.php` | 4 | hook (done) | `workers/Memory/Native/synth_diary_jobs.php` | done |
| `lib/synth_diary_scope.php` | 4 | hook (done) | `managers/MemoryManager/Native/synth_diary_scope.php` | done |
| `lib/synth_diary_state.php` | 4 | hook (done) | `managers/MemoryManager/Native/synth_diary_state.php` | done |
| `lib/synth_diary_status.php` | 4 | hook (done) | `managers/MemoryManager/Native/synth_diary_status.php` | done |
| `lib/synth_equipment_prompt.php` | 4 | hook (done) | `managers/PromptManager/Native/synth_equipment_prompt.php` | done (L9) |
| `lib/synth_gift_menu.php` | 4 | hook (done) | `managers/ActionsManager/Native/synth_gift_menu.php` | done (L8) |
| `lib/synth_history_speech.php` | 4 | hook (done) | `managers/ContextManager/Native/synth_history_speech.php` | done (L9) |
| `lib/synth_history_trigger_prompt.php` | 4 | hook (done) | `managers/PromptManager/Native/synth_history_trigger_prompt.php` | done (L9) |
| `lib/synth_ingress.php` | 4 | hook (done) | `managers/ListenerManager/Native/synth_ingress.php` | done (L10) |
| `lib/synth_jev_actions.php` | 4 | hook (done) | `managers/ActionsManager/Native/synth_jev_actions.php` | done (L8) |
| `lib/synth_location.php` | 4 | hook (done) | `managers/ListenerManager/Native/synth_location.php` | done (L10) |
| `lib/synth_memory.php` | 4 | hook (done) | `managers/MemoryManager/Native/synth_memory.php` | done |
| `lib/synth_memory_dispatch.php` | 4 | hook (done) | `workers/Memory/Native/synth_memory_dispatch.php` | done |
| `lib/synth_memory_recall.php` | 4 | hook (done) | `managers/MemoryManager/Native/synth_memory_recall.php` | done |
| `lib/synth_memory_worker.php` | 4 | hook (done) | `workers/Memory/Native/synth_memory_worker.php` | done |
| `lib/synth_middleterm.php` | 4 | hook (done) | `managers/MemoryManager/Native/synth_middleterm.php` | done |
| `lib/synth_middleterm_approval.php` | 4 | hook (done) | `managers/MemoryManager/Native/synth_middleterm_approval.php` | done |
| `lib/synth_middleterm_jobs.php` | 4 | hook (done) | `workers/Memory/Native/synth_middleterm_jobs.php` | done |
| `lib/synth_middleterm_regeneration.php` | 4 | hook (done) | `managers/MemoryManager/Native/synth_middleterm_regeneration.php` | done |
| `lib/synth_middleterm_scope.php` | 4 | hook (done) | `managers/MemoryManager/Native/synth_middleterm_scope.php` | done |
| `lib/synth_npc_identity.php` | 4 | hook (done) | `managers/CharacterManager/Native/synth_npc_identity.php` | done (L9) |
| `lib/synth_player_gifts.php` | 4 | hook (done) | `managers/ActionsManager/Native/synth_player_gifts.php` | done (L8) |
| `lib/synth_player_rewrite.php` | 4 | hook (done) | `managers/Conversation/Rechat/synth_player_rewrite.php` | done (L5) |
| `lib/synth_private_narrator.php` | 37 | manager | `managers/Narrator` (private narrator protocol; already moved by #120, this is its legacy-name hook) | done (#120) |
| `lib/synth_profile_jobs.php` | 4 | hook (done) | `workers/Profiles/Native/synth_profile_jobs.php` | done (L7) |
| `lib/synth_profile_scope.php` | 4 | hook (done) | `workers/Profiles/Native/synth_profile_scope.php` | done (L7) |
| `lib/synth_profile_state.php` | 4 | hook (done) | `workers/Profiles/Native/synth_profile_state.php` | done (L7) |
| `lib/synth_protocol_state.php` | 4 | hook (done) | `managers/MessageHandler/Native/synth_protocol_state.php` | done (L10) |
| `lib/synth_rechat_admission.php` | 4 | hook (done) | `managers/Conversation/Rechat/synth_rechat_admission.php` | done (L5) |
| `lib/synth_rechat_emission.php` | 4 | hook (done) | `managers/Conversation/Rechat/synth_rechat_emission.php` | done (L5) |
| `lib/synth_rechat_history.php` | 4 | hook (done) | `managers/Conversation/Rechat/synth_rechat_history.php` | done (L5) |
| `lib/synth_rechat_prepare.php` | 4 | hook (done) | `managers/Conversation/Rechat/synth_rechat_prepare.php` | done (L5) |
| `lib/synth_rechat_profile.php` | 4 | hook (done) | `managers/Conversation/Rechat/synth_rechat_profile.php` | done (L5) |
| `lib/synth_rechat_routes.php` | 4 | hook (done) | `managers/Conversation/Rechat/synth_rechat_routes.php` | done (L5) |
| `lib/synth_rechat_selection.php` | 4 | hook (done) | `managers/Conversation/Rechat/synth_rechat_selection.php` | done (L5) |
| `lib/synth_relationship.php` | 4 | hook (done) | `managers/Relationships/Native/synth_relationship.php` | done |
| `lib/synth_relationship_control.php` | 4 | hook (done) | `managers/Relationships/Native/synth_relationship_control.php` | done |
| `lib/synth_relationship_dispatch.php` | 4 | hook (done) | `workers/Relationships/Native/synth_relationship_dispatch.php` | done |
| `lib/synth_relationship_graph_scope.php` | 4 | hook (done) | `managers/Relationships/Native/synth_relationship_graph_scope.php` | done |
| `lib/synth_relationship_interaction.php` | 4 | hook (done) | `managers/Relationships/Native/synth_relationship_interaction.php` | done |
| `lib/synth_relationship_interaction_dispatch.php` | 4 | hook (done) | `workers/Relationships/Native/synth_relationship_interaction_dispatch.php` | done |
| `lib/synth_relationship_interaction_jobs.php` | 4 | hook (done) | `workers/Relationships/Native/synth_relationship_interaction_jobs.php` | done |
| `lib/synth_relationship_interaction_model.php` | 4 | hook (done) | `workers/Relationships/Native/synth_relationship_interaction_model.php` | done |
| `lib/synth_relationship_interaction_queue.php` | 4 | hook (done) | `workers/Relationships/Native/synth_relationship_interaction_queue.php` | done |
| `lib/synth_relationship_interaction_result.php` | 4 | hook (done) | `workers/Relationships/Native/synth_relationship_interaction_result.php` | done |
| `lib/synth_relationship_interaction_state.php` | 4 | hook (done) | `managers/Relationships/Native/synth_relationship_interaction_state.php` | done |
| `lib/synth_relationship_interaction_worker.php` | 4 | hook (done) | `workers/Relationships/Native/synth_relationship_interaction_worker.php` | done |
| `lib/synth_relationship_jobs.php` | 4 | hook (done) | `workers/Relationships/Native/synth_relationship_jobs.php` | done |
| `lib/synth_relationship_manual_state.php` | 4 | hook (done) | `managers/Relationships/Native/synth_relationship_manual_state.php` | done |
| `lib/synth_relationship_pair_evidence.php` | 4 | hook (done) | `managers/Relationships/Native/synth_relationship_pair_evidence.php` | done |
| `lib/synth_relationship_policy.php` | 4 | hook (done) | `managers/Relationships/Native/synth_relationship_policy.php` | done |
| `lib/synth_relationship_prompt.php` | 4 | hook (done) | `managers/Relationships/Native/synth_relationship_prompt.php` | done |
| `lib/synth_relationship_responder.php` | 4 | hook (done) | `managers/Relationships/Native/synth_relationship_responder.php` | done |
| `lib/synth_relationship_result_state.php` | 4 | hook (done) | `managers/Relationships/Native/synth_relationship_result_state.php` | done |
| `lib/synth_relationship_worker.php` | 4 | hook (done) | `workers/Relationships/Native/synth_relationship_worker.php` | done |
| `lib/synth_runtime.php` | 4 | hook (done) | `managers/ListenerManager/Native/synth_runtime.php` | done (L10) |
| `lib/synth_social_contract.php` | 4 | hook (done) | `managers/ActionsManager/Native/synth_social_contract.php` | done (L8) |
| `lib/synth_social_response.php` | 4 | hook (done) | `managers/ActionsManager/Native/synth_social_response.php` | done (L8) |
| `lib/synth_social_tasks.php` | 4 | hook (done) | `managers/ActionsManager/Native/synth_social_tasks.php` | done (L8) |
| `lib/synth_stt_pipeline.php` | 4 | hook (done) | `drivers/stt/Native/synth_stt_pipeline.php` | done (L6) |
| `lib/synth_tts.php` | 4 | hook (done) | `managers/TtsStudio/Native/synth_tts.php` | done (L6) |
| `lib/synth_tts_jobs.php` | 4 | hook (done) | `managers/TtsStudio/Native/synth_tts_jobs.php` | done (L6) |
| `lib/synth_tts_plan.php` | 4 | hook (done) | `managers/TtsStudio/Native/synth_tts_plan.php` | done (L6) |
| `lib/synth_tts_worker.php` | 4 | hook (done) | `managers/TtsStudio/Native/synth_tts_worker.php` | done (L6) |
| `lib/synth_wait_actions.php` | 4 | hook (done) | `managers/ActionsManager/Native/synth_wait_actions.php` | done (L8) |
| `lib/synth_worker_origin.php` | 4 | hook (done) | `managers/TtsStudio/Native/synth_worker_origin.php` | done (L6) |
| `lib/tokenizer_helper_functions.php` | 4 | hook (done) | `managers/ServerStatus/Native/tokenizer_helper_functions.php` | done (L11) |
| `lib/tts_pronunciation.php` | 4 | hook (done) | `managers/TtsStudio/Native/tts_pronunciation.php` | done (L6) |
| `lib/tts_pronunciation_preview.php` | 4 | hook (done) | `managers/TtsStudio/Native/tts_pronunciation_preview.php` | done (L6) |
| `lib/utils.php` | 36 | Synth\Lib class | `Synth\Lib` helpers (lazy XML, tokenizer cost, semaphore, misc UI, utils, dynamic model, legacy row management) | L11 |
| `lib/utils_game_timestamp.php` | 4 | hook (done) | `managers/ContextManager/Native/utils_game_timestamp.php` | done (L11) |
| `lib/visual_context.php` | 4 | hook (done) | `managers/VisualContext/Native/visual_context.php` | done (L4) |
| `lib/visual_context_request.php` | 4 | hook (done) | `managers/VisualContext/Native/visual_context_request.php` | done (L4) |
| `lib/voice_catalog.php` | 4 | hook (done) | `managers/TtsStudio/Native/voice_catalog.php` | done (L6) |
| `lib/voice_clone_resolver.php` | 4 | hook (done) | `managers/TtsStudio/Native/voice_clone_resolver.php` | done (L6) |
| `lib/voice_clone_sync.php` | 4 | hook (done) | `managers/TtsStudio/Native/voice_clone_sync.php` | done (L6) |
| `lib/voice_sample.php` | 4 | hook (done) | `managers/TtsStudio/Native/voice_sample.php` | done (L6) |
| `lib/wav_utils.php` | 4 | hook (done) | `drivers/tts/Native/wav_utils.php` | done (L6) |
| `lib/worldknowledge_catalog.php` | 4 | hook (done) | `managers/WorldKnowledgeManager/Native/worldknowledge_catalog.php` | done (L2) |
| `lib/worldknowledge_forced_context.php` | 4 | hook (done) | `managers/WorldKnowledgeManager/Native/worldknowledge_forced_context.php` | done (L2) |
| `lib/worldknowledge_llm_service.php` | 4 | hook (done) | `managers/WorldKnowledgeManager/Native/worldknowledge_llm_service.php` | done (L2) |
| `lib/worldknowledge_retrieval.php` | 4 | hook (done) | `managers/WorldKnowledgeManager/Native/worldknowledge_retrieval.php` | done (L2) |
| `lib/worldknowledge_runtime.php` | 4 | hook (done) | `managers/WorldKnowledgeManager/Native/worldknowledge_runtime.php` | done (L2) |
| `lib/worldknowledge_topic.php` | 4 | hook (done) | `managers/WorldKnowledgeManager/Native/worldknowledge_topic.php` | done (L2) |
| `lib/worldknowledge_vocabulary.php` | 4 | hook (done) | `managers/WorldKnowledgeManager/Native/worldknowledge_vocabulary.php` | done (L2) |
