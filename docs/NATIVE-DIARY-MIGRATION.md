# Native diary migration

Status: manual NPC, Player and Narrator client/ingress, configuration, workers and separate saved journals are connected.
Server sleep/wait policy, admission and saved cooldowns are connected; client observation is not.
Source/build/HTTP evidence exists; gameplay, automatic engine triggers and management remain incomplete.
This document does not reduce the full parity goal to disabling legacy behavior.

## Evidence and required behavior

Frozen references: Dialectic c6e92f375a44a680affc22c8ba378320650b92da and
DialecticServer 907eb634fc49b47abe3490ea8229977ad0b0f6be.
The reference SleepWaitEventHandler emits waitstart/goodnight; GameLoop and HTTPManager
carry these events. Server dynamic_update_util implements nearby/follower, player and
Narrator diaries, sleep/wait automation, per-character/profile inheritance, cooldowns,
selected diary connectors and first-person diary prompts. Latest-diary prompt inclusion
is separately enabled. These are required behaviors, not interchangeable with summaries
or dynamic personality updates.

SYNTH now accepts capability-gated v2 diary_request through its native codec/client/ingress.
Legacy processor/comm.php commands still call name-based diary helpers. The
legacy latest-diary reader selects diarylog by people/name and descending game time,
without actor/playthrough/save provenance. Native calls refuse that lookup and all three
legacy generation entry points. The new native reader uses only owned immutable entries;
manual NPC, Player and Narrator jobs can originate from the client. Legacy v1/admin diary storage is unchanged.

Schema 20260905025 adds diary_inputs, diary_input_sources and diary_results. The internal
manual-NPC capture helper verifies a canonical completed-or-active diary_request owner,
freezes its exact snapshot, canonical actor/profile link, saved profile revision, bounded
visible history, prior diary and supplied instruction/connector/token settings. Publication
requires the completed original request under the active-generation lock. Replays are
idempotent; conflicting or corrupt results, changed ownership and unavailable dependencies
are refused. Reflection history is derived with empty people/party, not witnessed speech.
The reader verifies hashes, original owner, full prior chain, profile dependencies and source
visibility against the selected save frontier. An explicitly observed audience NPC can read
its own saved state without requiring a selected target; ordinary history remains target-scoped.

Schema 20260905026 connects manual NPC generation to durable diary jobs. Admission uses
the linked profile's diary connector (including the existing forced-Player2 policy), not
the dynamic-profile or conversation connector. A missing diary connector returns disabled;
manual requests are independent of the automatic-diary toggle. Diary instruction, character
prompt head, bounded diary history limit and connector token limit resolve before admission.
Actual first-person messages retain the saved profile/history/prior diary and are frozen with
the input. Source evidence is labelled as data and subjective prior reflection is separated.
Only credentials/provider configuration for the selected connector ID remain live.

The scheduled dynamic-profile task also dispatches diary jobs, after completing its existing
work. Dispatch waits for request completion. An actor can have only one live diary attempt;
jobs use three attempts, a 30-minute deadline, two-minute leases and ten-second retry backoff.
Accepted output is checkpointed against its exact input hash before immutable publication and
ready state commit together. Recovery does not regenerate accepted text; lost leases and save
loads prevent checkpoint/publication. Failures retain fixed codes without provider details.
Remote calls can repeat across a response-before-checkpoint crash window.

The journal/worker fixture inserts diary_request owner records directly. It also
runs the actual scheduled-task callback in a fresh PHP process through the real connector
to a loopback fake provider: one HTTP call, ready publication, save-visible reflection and zero
calls on a second task run. Separate HTTP coverage now exercises the actual main pipeline
with a disposable bootstrap, real claim/admission/replay, invalid payload/capability/actor/
snapshot/lifecycle rejection, disabled configuration and all-or-nothing batch admission.
This is not paid provider or in-game proof.

The client advertises diary.manual.npc/player/narrator, recognizing exact /diary, /diary player and /diary narrator commands.
It copies the pointed NPC and game snapshot, then publishes/binds that snapshot and sends
the manual request on its bounded background network lane. There is no nearest-NPC fallback:
flat uses the crosshair; VR uses the controller ray or HMD gaze. Missing targets fail locally.
The server accepts0-16 canonical NPCs and optional Player/Narrator selection,
and reason=manual|sleep|wait; at least one role must be selected and each selected role requires its capability.
Sleep/wait additionally require their matching diary.automatic.sleep/wait observation capability,
negotiated at initialization. The current client advertises neither and emits manual requests only.
Jobs and the replayable three-line
queued/disabled acknowledgment commit atomically. Queued does not mean generation finished.
Normal subsequent dialogue no longer cancels these diary jobs; save generations and explicit
cancellation retain their fences. Server completion becomes save-visible only in a later
acknowledged snapshot. Client completion checks now use /diary_status.php with the exact
original admission owner. Short background requests never dispatch providers or replace the
queued receipt. Ready validates input/result/source provenance, then both adapters capture a
fresh game-thread snapshot for co-save acknowledgement before reporting next-save inclusion.
One diary is tracked at a time, with five-second idle checks/two-second HTTP timeout, a
30-minute tracking deadline and three executed checkpoint attempts. Load/halt retires tracking.

## Role-specific configuration boundary

lib/synth_diary_config.php now resolves bounded instructions, diary history limits and token
limits without loading credentials or invoking providers. Native NPC admission uses it now.
All three native diary roles now use this resolver. Player additionally requires diary_enabled even for manual requests.

| Role | Connector selection from the frozen reference |
| --- | --- |
| NPC | Effective profile diary connector, including the existing forced-Player2 policy; no ordinary-dialogue fallback. |
| Narrator | Effective profile diary connector, then the profile's connector_id. |
| Player | Explicit player diary_connector_id, otherwise first configured CORE_CONNECTOR_SUMMARY, CORE_CONNECTOR_PLAYER, CORE_CONNECTOR_PROFILES. If that selected core record is unavailable, use the established legacy diary-driver resolver, not another core ID. |

NPC/Narrator metadata overrides global diary prompt/history and prompt head; character prompt
head wins over profile and global values. Player uses its own diary prompt and global head/history,
not an NPC profile. Blank token limits are inherited from the selected driver's max_tokens,
then MAX_TOKENS_MEMORY, then2048. Positive configured limits remain authoritative. Only bounded
settings and connector ID/driver/source are returned; credentials and provider metadata are not.
The Player worker preserves legacy-driver selection separately from positive core connector IDs;
it never maps a driver to an arbitrary record or fakes an ID. Auto enablement and saved cooldowns
are separate server admission gates, not restrictions on manual requests. Player's master
diary_enabled setting is distinct and remains required, matching the frozen processor/comm.php.

### Installed-driver and KoboldCpp execution prerequisite

The legacy diary-driver resolver now retains installed JSON driver names. Previously it rewrote
openaijson/openrouterjson/koboldcppjson to absent filenames, allowing an unrelated fallback to win.
Older short names resolve toward the maintained driver; nested/CSV preference order remains intact.
Only supported provider files can be selected; paths, NULs and connector-directory helpers cannot.
Resolution without persistence does not mutate CONNECTORS_DIARY or log raw configuration.

KoboldCpp now supplies the fast_request method used by native diary workers. Selected core URL,
sampling settings and badge are loaded instead of inheriting an unrelated global endpoint.
Background text uses the documented /v1/chat/completions endpoint with the exact supplied messages,
not the dialogue-only PHP JSON/action templates. KoboldCpp's own chat adapter/template must match
the loaded model; the existing SYNTH template setting still applies to regular streaming dialogue.
No model/service configuration is changed automatically. Frozen tokens and message/output ownership
cannot be replaced by extra parameters. Native provider cancellation, bounded HTTP/response sizes,
plain-text result validation and normal diary checkpoint/retry handling remain in force.
Reference: https://github.com/LostRuins/koboldcpp/wiki/ and its documented OpenAI-compatible chat API.
Loopback connector/task evidence is not a real KoboldCpp model or in-game acceptance claim.
Player admission now uses this prerequisite and freezes the explicit core or legacy selection.

### Automatic server policy and saved cooldowns

NPC automatic enablement and wait opt-in inherit profile metadata, with non-null/nonblank
extended_data overrides. Player requires its master diary_enabled and auto_diary_enabled, plus
auto_diary_wait_enabled on wait. Narrator uses its automatic toggle on both sleep and wait; the
reference imposes neither a separate wait toggle nor diary_enabled master gate for this role.
Explicit Narrator settings override the legacy NARRATOR_AUTO_DIARY_ENABLED environment fallback.
No user settings are changed or enabled by deployment.

The selected automatic policy and cooldown duration are frozen before provider admission.
DIARY_COOLDOWN defaults to30 real seconds; only NPC profiles override it, with negatives clamped
to0. Admission uses the active-generation transaction lock to check pending work and unacknowledged
results before inserting a new automatic job for the actor/role. Successful automatic publications
start the cooldown; manual entries and failed jobs do not. Sleep and wait share that role's timer.
The cooldown reader filters the same complete, hash-valid prior/profile/source chain used by diary
recall, then selects its latest automatic publication timestamp. A later manual entry cannot hide
the previous automatic timer. Earlier saves discard future timers; accepted descendant saves retain
their ancestor timer. This does not read or write the reference's mutable name-keyed conf_opts timer.

Existing status, bounded retries, configured provider execution and saved-checkpoint machinery also
handle automatic requests. Disposable HTTP coverage includes a mixed NPC/Player/Narrator wait batch,
the configured scheduled Player provider, sleep/wait cooldown sharing, manual bypass, pending-work
deduplication, failure without cooldown and save/fork selection. Real engine observation remains the
next client milestone; no sleep/wait capability is advertised from animation or elapsed game time.

## Implementation contract

1. Add an explicitly capability-gated v2 diary request carrying the exact context sequence,
   canonical selected NPC identities, explicit player/Narrator selection, and a closed
   manual/sleep/wait reason. Require at least one selected role and bounded unique actors.
   Keep player and Narrator as separate roles owned by the canonical player/playthrough.
   Do not reinterpret profile_refresh or a conversational trigger as a diary request.
2. Resolve admission only from the acknowledged target/audience/player snapshot. Persist
   original owner and row/identity bindings atomically with idempotent jobs. Response is
   an accepted/disabled/rejected request acknowledgment, not a diary-completion claim.
   Never run a model on the game thread or before completing the request transaction.
3. Resolve existing diary enablement, auto/wait overrides, profile inheritance, selected
   diary connector/fallback and prompt rules independently for each role. Freeze effective
   configuration, saved profile, visible history, selected prior diary, rendered messages
   and token parameters before dispatch. NPCs must not substitute a dynamic-profile connector;
   preserve the explicit Player fallback chain and Narrator fallback described above.
4. Store a typed immutable diary input/result journal with actor/role, playthrough,
   session/generation/snapshot/request, source/prior references and hashes. Publish one
   derived history item with the accepted result and ready job in one transaction. A diary
   is a character's reflection, not a new witnessed event or proof that another actor heard it.
5. Use bounded fenced jobs, retry/backoff, cancellation and crash recovery. Enforce saved
   cooldown history by actor/role and reason; a discarded future save cannot retain a later
   cooldown. Concurrent requests must not duplicate publication or race the same cooldown.
   Provider calls may repeat across the remote-response/checkpoint crash window; state must not.
6. Latest-diary prompt inclusion reads only accepted, hash-valid, save-visible journal
   results. Respect its existing toggle, bound/escape text and revalidate dependencies.
   No fallback to mutable diarylog/name matching, latest-session guessing or global game time.
   Legacy import needs explicit reviewed identity/save attribution, never an automatic backfill.
7. Connect manual request surfaces and real sleep/wait boundaries through runtime adapters
   and the bounded client worker. Snapshot rest animation or a game-time jump is not sufficient
   evidence of a wait/sleep start. Validate flat and VR observation support independently;
   do not announce unsupported capabilities. Management controls must address native revisions
   without silently updating legacy rows; use the required UI delegation workflow for that work.

## Landing sequence and proof gates

- First complete vertical slice: native manual NPC diary from client request through admission,
  provider-freeze, worker, immutable publication and saved prompt retrieval. Mirror both protocol
  trees and validate both client builds before advertising the capability or deploying it.
- Extend that tested path to player/Narrator and nearby batches, preserving role-specific
  configuration and connector semantics. Then wire sleep/wait automation and saved cooldowns.
- Add native diary inspection/correction/archive semantics and the corresponding management
  controls. Keep original output and attribution available for audit; do not erase source evidence.
- Regression gates: same-name actors, different playthroughs, earlier saves and descendant forks,
  absent target, disabled/inherited settings, wait opt-out, concurrent cooldown checks, frozen
  prompt/connector selection, queue replay, provider failure, process death, stale leases,
  save-load during generation, escaped/bounded latest-context text and unowned legacy rejection.
- Exercise actual scheduled task and configured provider with synthetic data, then require
  manual flat save/load/conversation/diary behavior and independent VR headset acceptance.

Manual Narrator requests use the exact context's canonical player identity and a distinct
narrator role. No NPC row is invented or registered by name. Saved Narrator profile revisions,
player-owned evidence and the full prior diary chain are checked at capture, publication and read.
Roleplay aliases are presentation only; profile placeholders render without rewriting source
evidence. Targetless admission, scheduled configured-connector generation, exact-owner status,
fresh save checkpoints and the existing latest-diary context toggle are connected. No new schema
migration is needed because the journal already has explicit roles.

Manual Player requests use /diary player and diary.manual.player, with no target requirement.
The Player role is separate from Narrator even though both use the same canonical saved player.
Its configured name is presentation only; appearance, background (then bio fallback) and speech
style are bounded, frozen and rendered for its first-person prompt. There is no NPC/Narrator
dynamic profile revision. Its journal checks player-owned source evidence and the full prior
Player chain, including earlier-save exclusion and descendant-fork inclusion. Status/ready/fresh
co-save checkpoint semantics match the other roles. Native Player entries are not inserted into
legacy diarylog or automatically shared with NPC/Narrator dialogue. Native diary management and
player-context recall controls remain separate migration work.

The manual NPC/Narrator workers and journals have PHPUnit, disposable SQL and loopback connector
evidence. Public completion tracking is connected but not game-accepted. Server automatic admission
and saved cooldowns are implemented, but no sleep/wait observation, management migration or gameplay
acceptance is claimed. Remaining work includes engine observation/client batches and management;
the full goal is not reduced to these diary paths.
