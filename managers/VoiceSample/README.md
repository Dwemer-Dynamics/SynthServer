# VoiceSample

Inspects the stored voice samples under `data/voices` and previews an NPC's saved voice.

| Class | Role |
| --- | --- |
| `VoiceSampleStore` | Read-only: lists samples (metadata only), reads WAV headers, tells silent audio from audible, validates voice ids and reference text. Writes nothing. |
| `VoicePreviewManager` | Speaks one short line with an NPC's saved voice through the connector dialogue would use. Saves nothing; one preview at a time. |
| `VoicePreviewException` | A failure with the HTTP status and fixed client-facing text the route answers with. |

Routes: `api/v1/voice/samples.php` (list) and `api/v1/voice/preview.php` (preview). Both are dashboard
routes behind the management guard; the preview also needs the dashboard session token. Imports
(`vsx.php`) call `VoiceSampleStore::assertAudible()` so a silent decode is refused before storage.

`VoicePreviewManager` reads NPC, profile and connector rows through the legacy `NpcMaster`, `CoreProfile` and
`TTSConnector` classes, and runs one `core_npc_master` query for the Studio picker. They move to the NPC and
connector system entities with the schema redesign. See `docs/VOICE-CLONING.md` for the endpoint contract.
