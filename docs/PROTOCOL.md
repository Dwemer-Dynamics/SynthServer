# SYNTH protocol contract

## Locally installed player caps payment (2026-09-08)

The maintained server now implements `take_caps_from_player` with a separately
acknowledged `action.player_caps_inventory` contract. NPC performer and player
target remain stored dialogue roles; the paired inventory receipt instead names
the player donor and NPC receiver. Only the player's observed exact currency can
authorize the required integer amount. Read-only rechat cannot issue payment.
See the [player payment wire contract](../protocol/v2/README.md) for dependencies,
receipt ownership, agreement semantics and compatibility limits. The matched flat
client08f5d2d and compatible servercd59050/c020de9 are locally installed; the HTTP
endpoint returns the exact player-payment ACK. Client readiness and paired receipt
forwarding are tested against lower/malformed ACKs and reinitialization. This is
source, synthetic-pipeline and deployment proof, not manual game acceptance.

## Current transfer status (2026-09-08)

Equipment, Consume, paired GiveItemTo and GiveCapsTo are locally installed in
the matched flat client/server candidate. Manual gameplay acceptance remains open.
Older staged notes below are historical. Caps require flat v2, their exact
init_caps_inventory_accepted acknowledgement and all paired-transfer dependencies.
Read-only rechat cannot issue mutations through either catalog fallback or direct
emission. See the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)) for builds, hashes and outstanding flat/VR gates.

## Staged post-action inventory receipts (2026-09-08)

SOURCE ONLY / NOT ENABLED. A v2 action_result event may carry the tagged
synth.action.result.v2 result with a closed inventory object: canonical actor,
items and complete/partial/unavailable observation. Unavailable requires no rows.
The existing v1 result remains valid and unchanged. The extension requires
action.inventory_observation, dialogue.turn_ownership and a positive original
context_sequence. More than 32 rows additionally requires flat context.inventory_512;
512 rows is the maximum and the existing request-byte limit still applies.

The server binds observations to the stored EquipItem/UnequipItem intent, its
canonical actor/playthrough, parent request, turn, generation, context, lane and
capabilities, with an active runtime/turn and non-retired intent. Exact repeats are
idempotent; conflicting observations cannot overwrite the first terminal result.
The original context snapshot is never rewritten.

The plugin does NOT yet advertise this capability. Its forwarding path and ordinary
action-chain prompt/CheckInventory/client inventory views are now source-connected.
Receipt admission reads the original context; consumers overlay only accepted exact
ancestry (64 actions / 8 MiB maximum), without changing original snapshots.
Cross-rechat ancestry, explicit readiness and full-main/model acceptance remain pending.
Wire/database tests do not establish end-to-end AI freshness or gameplay acceptance.

## Server-only rechat route evidence (2026-09-07)

Native v2 turn-owned dialogue now persists independent emitted route evidence
in synth_meta.response_routes (schema 20260907001). It does not add response
wire fields: the client still receives the existing closed dialogue payload.
Route rows alone do not prove a completed parent. The evidence reader verifies
the canonical owner, complete stored response and exact line hashes.
One-child admission, mode/budget selection and readiness acknowledgement are
still pending; dialogue.rechat.ownership remains disabled.

## Staged native rechat parent contract (2026-09-07)

SOURCE ONLY / NOT ENABLED. Protocol v2 capability dialogue.rechat.ownership
requires dialogue.turn_ownership. Rechat triggers using it require paired
reply_to_request_id and reply_to_line_id plus a positive context_sequence.
IDs contain 1-128 ASCII characters: first alphanumeric, then alphanumeric or
._:-. Parent fields are rejected on non-rechat or capability-off requests;
the parent request cannot equal the current request. V1 and capability-off
wire shapes are unchanged.

The client retains the actual eligible Line request/line IDs in one
PendingRechat bundle with its copied actor, snapshot and original context,
turn, generation and cancellation binding. Queue dispatch moves that bundle;
halt, supersession and actor suppression clear it together.

The plugin does not advertise this capability. SynthClient's
uses_rechat_parent_contract checks wire selection, not acknowledged server
support. The server validates the shape but rejects owned rechat with
owned_rechat_not_ready before legacy binding. Schema acceptance is not proof
of a completed parent. Enablement still requires explicit server readiness,
durable emitted routes, completed-parent admission and replay-safe decisions.

## Extended inventory transport (2026-09-06)

V2 flat `context.inventory_512` is explicitly acknowledged by
`init_inventory_512_accepted`. It allows 512 rows per actor under the existing
1 MiB ingress budget; old peers and VR keep 32. Trimming stays partial and owned
by the original snapshot. See protocol/v2/README.md for negotiation, byte-budget
and incomplete native/prompt coverage boundaries.

## Native actor-event source contract (2026-09-06)

Optional v2 flat capability `context.actor_events` permits `payload.actor_events`.
The following source contract is extended by the negotiated delivery checkpoint
in protocol/v2/README.md. Flat host capture is wired behind exact native
availability, negotiation and lifecycle ownership; reactions remain unimplemented.

A batch has `batch_id=actor:<positive wire-safe serial>`, nonnegative integer
`age_ms` and 1-32 ordered events. Age measures delivery delay since the bound
scene; each event's immutable `capture_delay_ms` measures original callback to
scene capture (0-2000 ms). Retrying never replaces the scene or refreshes source
time. Batch IDs are scoped to session, generation and playthrough.

Each event carries kind (death/equipped/unequipped), canonical actor identity,
original secondary FormID, optional resolved secondary identity, optional base
item metadata, original reference, uint16 unique ID, capture delay and explicit
player knowledge. An unresolved nonzero killer ID stays nonzero with null identity.
Death carries no item/reference/unique ID. Equipment carries no secondary actor;
base item labels do not establish stack counts, consumption or instance identity.

Admission resolves every named identity against exactly one enabled actor state
in the owned context. Only the player's own equip/unequip can claim
`player_equipment`; all other source records remain `unavailable`. Nearby
presence or later line-of-sight is not evidence of witnessing the earlier event.
Actor states remain bounded to 16 and require player/audience membership; bounded
delivery retains all event identities without inventing witness eligibility.

Schema migration 20260906004 adds `synth_meta.actor_event_batches` for raw source.
Source insertion and the player-only `actor_equipment` history projection share
the context transaction. NPC events never enter that projection or eventlog.
Raw actor batches are removed from the shared prompt snapshot, not from stored
evidence. History is player-only, has no inferred location or NPC witnesses, and
obeys the existing context/save frontier. Duplicate batches retain their original
context and age; changed bodies/player identities conflict and roll back. Inactive
or wrong-generation owners cannot persist or reuse a prior batch receipt.

## Native acknowledged player reactions (2026-09-06)

Optional v2 flat dialogue.player_reactions also requires context.player_events.
Only a complete correlated three-line init_player_reactions_accepted handshake
enables it. That marker also acknowledges requested player-history and existing
quest features; init_player_events_accepted still enables history only. v1 and
VR cannot activate or send player reactions.

trigger kind player_reaction carries only actor and event_id (player:<positive
wire-safe serial>). Client narration, game time, visual capture and previous quest
sequence are forbidden. The server derives level_up/combat_end and text from the
original immutable history, exact session/generation/player/playthrough and current
save frontier. A currently active, visible, available audience NPC is required.
The player must be alive, enabled, out of combat, at least the observed level,
and no earlier on the game clock. The current context must have been received
within ten seconds; original capture age plus server elapsed time must be at most
sixty seconds. Retries never refresh the original age.

The first admitted trigger for that owned event ID is the only reaction candidate,
even across different context sequences. Ineligibility returns
player_reaction_suppressed without a model call. Existing levelup/combat_end profile
settings, chance and atomic RPG cooldown still apply. The bound native cue overrides
legacy/custom victory assumptions after prompt loading, without changing policy
extras: combat ending does not prove victory, kills, enemies, weapons or witnesses.
Level-up does not prove a perk, skill, reward or cause.

Client history ACKs can retain up to sixteen independent reaction candidates.
They expire sixty seconds after the original steady-clock observation and are
discarded on identity/timeline discontinuity. History delivery continues when no
speaker exists or reactions are unsupported. Existing owner/crosshair/nearest
active-NPC selection is shared with quest reactions. Worker context must be no
older than two seconds; an accepted queue submission consumes the candidate once.
Normal turn/generation cancellation applies. One-shot responses cannot enqueue
actions or rechat. Flat dispatch is connected; VR remains explicitly gated off.
Source/build/SQL/HTTP validation is not gameplay acceptance.


## Native sampled player history (2026-09-06)

Optional v2 flat context.player_events permits one player_event object on a context
publication. The client must first receive a complete three-line init response with
init_player_events_accepted. This marker also acknowledges any requested quest-event,
quest-tracking and quest-reaction capabilities implemented by this server; older
markers retain their existing meaning and never enable player events. v1 and VR
neither send nor negotiate this extension.

player_event contains event_id (player:<positive wire-safe serial>), kind
(level_up|combat_end), before and after states, interval_ms and age_ms. A state has
only positive game_time_ticks, level 1..32767 and boolean in_combat. Times/levels
must not reverse; level_up requires a strict increase, combat_end requires true
then false. interval_ms is positive and age_ms is nonnegative; both are wire-safe
integers. No text, action, victory, kills, weapons or witness claims are allowed.
The envelope's exact player identity owns the transition; native encoding checks
both original samples against that identity, generation and lane.

Delivery attaches original evidence to a newly captured scene, never republishes an
old scene to acquire an event sequence. Workers reject scenes older than two seconds;
transient failures retain the same event identity/facts for another fresh capture.
The client requires a complete context_accepted response before consuming the FIFO
head; game-thread receipts carry exact serial ownership. Terminal rejection is
observable. Sampling stays independent of response work, while delivery is bounded
and yields to current dialogue/input. Unsupported servers leave capture disabled.

Server admission validates the current player/time evidence, writes one immutable
player_event history row under the session transaction/lock, and finalizes its
context/save frontier atomically. Schema migration 20260906003 adds the matching
partial unique index. Same session/generation/playthrough/event ID plus identical
player and facts is a retry; conflicting evidence fails the transaction. age_ms is
delivery metadata rather than event identity, and retries never rewrite the first
insertion age or original save frontier. Event time comes from the original after
sample; location is unspecified and only the player is marked as an observer.
Passive history does not activate NPCs, create model jobs or replace dialogue owners.

Automatic level-up/combat-end reactions are a separate unfinished gate. They must
bind this durable event to a fresh eligible active NPC, deduplicate reaction admission,
apply existing RPG settings/chance/cooldown, and avoid legacy combat prompts that
assume defeated enemies. History admission alone is not gameplay or response proof.

## Native owned quest reactions (2026-09-06)

Optional v2 flat quest_updated requires dialogue.quest_reactions plus context.quest_tracking
and an explicitly negotiated init_quest_reactions_accepted or init_player_events_accepted response. It binds a positive
previous_context_sequence strictly older than the current owned context. No client prose
or visual binding is accepted; the server recomputes the change and speaker eligibility.
v1 and VR do not enable it. See QUEST-REACTIONS.md and protocol/v2 for the full contract.

## Native quest tracking evidence (2026-09-06)

Protocol v2 context quests may carry an optional boolean `tracked`. True and
false are observed player tracking states; omission means unavailable. Explicit
null is invalid on the wire. The field requires the flat lane and negotiated
`context.quest_tracking`; v1 quest schemas remain unchanged. Fallout 4 can track
multiple quests, so this is per quest, not a guessed single selected entry.

The flat adapter copies the pinned CommonLibF4 `QUEST_DATA::flags` kActive bit
inside the existing bounded game-thread journal capture. It does not call
Papyrus, add a hook, spin on a lock, or perform network work. VR leaves the
optional value unset. Tracking participates in immutable snapshot equality, so
toggling it is a journal change even when stage and objective text are unchanged.

For an admitted v2 flat init, the server acknowledges requested support:

| Requested supported capabilities | Status code |
| --- | --- |
| Quest events only | `init_quest_events_accepted` |
| Quest tracking only | `init_quest_tracking_accepted` |
| Both | `init_quest_events_tracking_accepted` |

No opt-in, legacy and VR retain `init_accepted`. The client requires the complete
correlated start/status/end acknowledgement and its requested capability before
sending tracking. An older server's quest-events acknowledgement still enables
quest capture, but tracking is stripped from contexts and read-quests results.
Failed initialization clears both acknowledgements.

Server-owned prompt/history projections normalize absent tracking to null and
label it unavailable. History comparison preserves the distinction between null
and false while ignoring JSON object-key order. The original context stays
immutable. Tracking is journal knowledge, not proof that an NPC heard an event.
This does not yet enable automatic quest-reaction dispatch.


## Passive world observations and native history (2026-09-06)

The existing v2 context message can be published without a target, activation or
model request. Both native entry pumps consider changed location/cell/worldspace/
weather/interior labels on their existing two-second background cadence. Clock-only
changes do not trigger passive publication. Admission is single-flight, idle-only,
menu/cancellation/generation-aware and rechecked against newer acknowledged frames.
Ordinary successful context publication acknowledges the same observed state.
Failure does not acknowledge it; later eligible observations may retry. This samples
world facts, not every intervening movement or a proven entry/exit event.

V2 derived world, nearby-item and point-of-interest observations now enter eventlog
and immutable native history in one database statement within context admission's
transaction. Their owner is payload.snapshot_sequence, not the envelope's zero
context_sequence. The referenced snapshot/playthrough and active runtime generation
must exist. World observations belong to the captured player; no Narrator speaker
or NPC visibility is invented. Existing exact-reader and saved-frontier rules apply.
Before admission commits, the new context frontier includes its own derived
observations as well as preceding history. Finalization selects only this exact
session/generation/playthrough/snapshot/request's observation rows while the existing
native-writer lock remains held. Failure rolls back admission; later dialogue,
workers and future observations cannot advance an acknowledged checkpoint.
Loading an older anchor retains its own observations but excludes later branches.
Mutable log edits cannot rewrite that history.
Legacy v1 writes remain unchanged; no old records are backfilled or given guessed
ownership. There is no new wire field, schema migration or product version.

## Captured audience completeness (2026-09-06)

Both context payload versions accept optional `audience_observation`: `complete`,
`partial`, or `unavailable`. Unavailable requires an empty audience. This describes
the exported captured actor set, not every actor in the world and not the separate
player/target state. Missing quality on an old snapshot means partial when its
audience is nonempty, otherwise unavailable; stored history is never rewritten.

The native adapter reports partial when the nearest-64 retention limit omits
actors, handles/identities/positions cannot be resolved, or snapshot construction
rejects a candidate. No process lists/bootstrap means unavailable. An exhaustive
successful empty traversal is complete. Supplemental exact-actor capture and
hearing/participant filtering downgrade completeness when they add/omit identities.
This change does not cap source traversal, change targeting, create departure
events or prove bounded frame time. No actor pointers are retained across frames.

The server preserves the field with its exact context sequence and supplies it
to text/audio/trigger context and the native main prompt. An omission must not be
interpreted as departure, death or world absence. Complete attests only this
capture's discovery set. Deploy server first: older strict servers reject the new
optional field until their coordinated schema/ingress update is installed.

## Current NPC combat target observations

Optional v2 capability `context.combat_targets` permits actor-state `combat_target`.
Its closed fields are `observation` (`identified|none|unavailable`), `actor` (exact
canonical identity with displayed name, or null) and `race` (captured label, or
empty). Identified targets require a live, enabled combat owner, a distinct target
in the same playthrough and a nonzero form ID. None/unavailable carries no identity.
The observation never adds the opponent to audience, witnesses or history readers.

The client sends this field only after a complete correlated v2 init explicitly
acknowledges `combat_targets: true`. An older server leaves it omitted; v1 remains
unchanged. Only the actual response speaker receives its own current observation,
including a prepared NPC different from the scene focus. Generic prompt states and
structured internal/history copies omit the field. Missing or ambiguous frames
render unavailable, without substituting nearby names or older history. See
[COMBAT-TARGETS.md](COMBAT-TARGETS.md) for capture and independent gameplay gates.

## Health and action-point observation availability (2026-09-05)

Actor states accept optional booleans `health_percent_available` and
`action_points_percent_available`, independently. The numeric fields retain
their0..100 wire shape for older payloads; a number is authoritative only when
its corresponding availability flag is explicitly true. Missing flags on older
snapshots are unknown, not proof of a successful read. No history is backfilled.

Native capture emits false with a zero placeholder for a missing definition,
non-finite current/permanent actor value or non-positive denominator. Observed
zero remains true/0. The existing ratio to permanent actor value and0..100 clamping
are unchanged; temporary-effect/full engine percentage semantics are not newly
established by these flags. No additional actor-value engine calls were added.

Saved snapshots retain original numbers/flags. AI prompt projections emit null
for unavailable or legacy-unattested percentages and preserve each independent
flag. Inspect-actor action detail likewise emits null instead of a numeric default
when that snapshot did not attest availability. Selected-NPC detail enrichment
preserves the originally frozen scalar values and flags rather than mixing reads.
Roll out the accepting server before the emitting client; strict older servers
reject unknown optional fields. Both protocol lanes share this additive contract.


## Faction-list completeness (2026-09-05)

Actor states accept optional `faction_completeness`: `complete`, `partial` or
`unavailable`. This is separate from `faction_observation`, which identifies
the source as effective runtime membership, base-only membership or unavailable.
Complete base-only data does not attest current runtime memberships.
Only complete effective data can support absence from the current faction list.
An unavailable list must be empty and use unavailable observation mode; conversely,
complete/partial requires effective or base_only mode. Both protocol lanes accept
older payloads without this field. Their completeness is interpreted conservatively
as partial when a source was observed, otherwise unavailable; never rewritten in
historical snapshots. Server-first coordinated local deployment is required before
the new client emits this optional field to strict older servers.

Native capture downgrades completeness when invalid entries are omitted or the
32-result cap applies. A failed/nonblocking capture remains unavailable. Current
actor and linked NPC metadata carry completeness alongside factions. Prompt caps
(16 for player/selected NPC,8 for bystanders) also downgrade complete to partial.
Neither incomplete membership nor a base-only empty list means an NPC has no
runtime affiliations. This field is not companion affinity.


## Nearby reference observation quality (2026-09-05)

Both context lanes accept optional nearby_items_observation and
points_of_interest_observation: complete, partial, cached or unavailable. Omitted
legacy quality means partial for nonempty rows and unavailable for empty rows.
Unavailable requires an empty array, but never means there are no nearby objects.

Native completeness covers supported object types in the player's current parent
cell within 4096 game units of the flat camera or VR HMD observer. It is not a scan
of adjacent exterior cells or a line-of-sight guarantee. Each scan visits at most
512 cell-reference entries under a nonblocking try-lock, then processes copied
references on the game thread. Output retains at most 32 items and 16 points of
interest. Scan/output caps or unrepresentable candidates mark the affected lists
partial. Missing cell/identity, bootstrap or lock failure is unavailable. Flat
non-dialogue snapshots explicitly mark retained observations cached, including old
distances/looking-at values; moving cells invalidates that cache.

Saved source payloads remain immutable. Both dialogue and trigger prompt paths
carry quality; their 16-item/12-point caps downgrade complete lists to partial.
Derived event metadata and human-readable summaries retain quality. No historical
event backfill is performed. inspect_surroundings carries the two quality fields
and downgrades completeness at its row/byte budget; successful actor information
does not imply fresh or complete object knowledge. Cached rows remain labeled
cached, not current. Existing per-object weight/value/count/lock estimates and
actual flat/VR capture timing still need separate semantic/gameplay acceptance.


## Active quest observation quality (2026-09-05)

Both context lanes accept optional active_quests_observation: complete, partial,
cached or unavailable. Unavailable requires an empty array but means unknown, not
no quests. Legacy nonempty arrays are partial; legacy empty arrays are unavailable.
Flat dialogue captures use a nonblocking try-lock, at most 128 target entries and
256 objective instances, retaining at most 16 quests. Other flat snapshots label
previously captured quest data cached. Bootstrap and failed lock acquisition are unavailable.

Each quest optionally declares objectives_observation: complete, partial or unavailable.
Unavailable requires zero/empty wire placeholders; prompts expose its count as null.
Objective capture retains at most eight texts per quest and marks bounded or missing
text partial. VR's pinned headers cannot attest displayed objective state/text or
complete non-target discovery: quest lists are partial and objectives unavailable.

Only a complete fresh list replaces the playthrough quest projection. Partial lists
update observed quest identities only; cached, unavailable and legacy empty lists do
not modify it. Unobserved projection rows can therefore remain historical. Request
prompts use the exact immutable saved snapshot and its quality, never that projection.
read_quests refuses cached/unavailable observations as a fresh read. Its 1024-byte
result budget downgrades truncated lists to partial. These limits do not establish
measured game-thread timing or flat/VR gameplay acceptance.


## Inventory observation quality (2026-09-05)

Both protocol lanes accept optional actor-state `inventory_observation`:
`complete`, `partial`, or `unavailable`. An explicit unavailable observation requires
an empty inventory array. New clients always send the field. Older payloads remain
valid and retain their original saved bytes: during prompt construction, missing
quality means partial for nonempty data and unavailable for empty data, never complete.
The server downgrades complete to partial whenever prompt filtering removes an item.

Capture is still game-thread-only with a nonblocking inventory try-lock. Skipped
capture, a missing inventory list or lock contention is unavailable, not observed empty.
A full successful scan may attest complete, including an actually observed empty list.
The scan accepts at most32 items, inspects at most128 entries and64 linked stacks per
entry; truncation, an incomplete stack count or omitted unrepresentable data is partial.
It does not enable deep player capture or add blocking retries.

`check_inventory` returns unavailable when capture is unavailable. Otherwise its
JSON detail carries inventory_observation and truncated. The1024-byte response budget
also downgrades completeness, including when no item fits. Complete-empty and
partial-empty are distinct. Correlated actor, generation, request and result handling
are unchanged. Install the paired server support before the updated client.
This quality contract does not prove gameplay observation, physical visibility or
safety of untested game/runtime combinations.


The byte-identical schemas, payload catalog and golden fixtures live in `protocol/`
in both RANGROO/SYNTH and RANGROO/Synthserver. `protocol/v1` is the compatibility
contract. `protocol/v2/README.md` defines exact request-owned context binding.

The server accepts both versions. Init pins the version to a runtime generation;
there is no silent version downgrade or latest-context fallback for v2. A new
generation is required to change versions or playthrough after context publication.
The coordinated native client now selects v2; older v1 sessions remain supported.

Canonical identities, actions, speech and opaque media keep their v1 schemas. Context
has a dedicated v2 payload schema for optional loaded_context; other leaf payloads
retain v1. v2 event and response envelopes add context_sequence.
Bound requests require an acknowledged positive sequence; init/context/cancel/halt
require zero. All v2 stream lines echo the binding. An action result must also match
its original action request's sequence. Ambiguous speaker names are rejected.

Validation: PHP ingress tests plus the SYNTH client verifier validate both versions;
each version has its own manifest, and CI checks both. Unknown fields, unsupported
versions, invalid bindings and stale/cross-session/runtime-lane work fail closed.
Build/SQL/HTTP evidence is separate from actual Fallout and VR headset acceptance.

Empty halt payloads preserve the JSON object/list distinction through decoding and
canonical persistence: {} is valid, [] is not. Generated action IDs and idempotency
keys include the runtime session and generation so repeated native request counters
in a later process do not collide with a previous session's actions.

The optional v2 dialogue.turn_ownership capability makes turn_id stable across a
logical response chain. A higher acknowledged context sequence can establish a new
root; obsolete rechat/action continuations return superseded_turn. Context publication
alone does not interrupt an owner. The paired native client advertises this capability.
Migration synth_protocol_schema 20260904001 adds owner fields and nullable worker
session attribution. Cancel/Halt/reload are session scoped, and late completion cannot
revive cancelled executions. Provider/response/action/deferred-speech guards enforce
coarse cancellation boundaries; immediate provider abort and legacy persistence
isolation remain unfinished. See protocol/v2/README.md for the exact contract.

Negotiated dialogue.turn_cancel (with dialogue.turn_ownership) permits cancel_request_id
to identify an acknowledged context publication. This cancels its exact logical turn
and prevents a later dialogue claim on the cancelled anchor. Old cancels cannot clear
a newer sequence. Ordinary request IDs and non-negotiated clients remain request scoped.

Optional v2 runtime.retirement lets Halt establish a halted session before init arrives,
covering retirement after a lost initialization acknowledgement. Late same-generation
init cannot revive it; a higher generation can initialize. Unknown-session Halt without
this capability retains its prior rejection. See the mirrored v2 README for limitations.

Optional context.saved_anchor carries the loaded co-save's last acknowledged context.
It is fixed by the first context in a generation and persisted as bounded metadata.
Exact historical resolution requires matching playthrough and runtime lane; missing
anchors remain unavailable. It is not a unique save ID or completed history watermark,
and does not yet scope narrative/memory retrieval or authorize rollback. See v2 README.

## Native PipVision capture ownership (2026-09-05)

Native v2 sessions use `synth.visual_context.capture.v2` with the acknowledged
`context_sequence` and the same `turn_id` as the capture's immutable context.
The client publishes that snapshot before image processing and passes the returned
`capture_id` in its v2 `external_reaction` payload. `synth.visual_context.response.v1`
remains the closed capture-result envelope; its request/turn/generation/capture ID
must all match. Ordinary triggers omit `capture_id`; v1 triggers reject it.

Ingress checks initialized protocol/lane/generation, completed non-cancelled context,
frozen subject and nearby actors, matching observed world values, and supersession.
The v2 world FormID fields stay empty because the snapshot does not attest those IDs.
Completion locks the session and exact capture claim, rechecks ownership, then commits
the description or portrait metadata without holding a transaction during image/model work.

An explicit reaction reads only its successful capture with matching actor, session,
generation, turn and sequence; missing/mismatched captures never select a gallery fallback.
Current and ancestral ambient descriptions require an accepted typed visual result within
the exact snapshot's ordered native history frontier. Both require actor/playthrough/lane
ownership and matching accepted result bytes. A pre-result snapshot cannot import a later
completion in the same generation. Legacy captures are never backfilled.

After a successful image/reaction chain, the client acknowledges another immutable context
to advance the co-save frontier past completion. Pending/failed acknowledgement is reported
without undoing the accepted reply. Capture work registers its context with the independent
turn-cancel lane before image processing. A cancelled anchor blocks late server completion.

Gallery active/delete/lock controls remain respected; locking only extends scene TTL, never
ancestry visibility. These controls are current global policy, not saved description state.

Native description corrections use `ui/api/native_visual_context.php`, separate from the
game protocol. GET takes exact session/generation/context_sequence. Confirmed CSRF-protected
POST additionally takes capture_id, expected_revision and description (nonempty UTF-8,
at most 12000 bytes, or explicit null to restore the original). Names cannot select an actor.
Writes are session-locked, optimistic and refused when a newer unsaved correction exists.
The typed `visual_manual` journal has no game request ID and no dialogue/hearing recipients.
Only subsequent acknowledged snapshots can include the new revision; prior snapshots and
forks keep their earlier state. Hash-checked corrections never mutate the original image
result. Reset is a new revision, not deletion. Legacy gallery edits remain separate.
The native gallery is read-only for description text and directs users to scoped corrections.
Actual capture, correction, acknowledgment, save/load and speech still require manual game proof.

### Native profile refresh ownership

The v2 game envelope is unchanged. Internal profile batches now use
`synth.profile_update_batch.v2` with the exact canonical request and frozen NPC row/actor
links. Requested actors must be in the acknowledged target/audience snapshot; admission
does not register unknown identities. Narrator inclusion is an explicit batch member.
The background processor verifies the persisted completed request/hash and active runtime,
then restores the original context and checks each link before provider work and final writes.
Actor history comes from that actor's saved native frontier. Narrator history uses the
canonical player's recorded visibility, including snapshots without a selected NPC; it
does not read omniscient global speech.
The provider runs outside the database transaction; final profile/Narrator writes acquire
the native session lock and reject old generations. NPC rows are locked while the original
link is rechecked. Request and connector globals are restored after processing.

Legacy non-native batches and newly queued explicit v1 batches keep their legacy path.
Old native-looking batches without enough ownership evidence are refused, not reassigned
to the current session; request a fresh native refresh.

Schema 20260905023 adds immutable `synth_meta.profile_revisions` for native generated
personality, occupation, skills, speechstyle and goals. Each result retains its real
profile-refresh request, canonical actor (player for Narrator), role, exact context,
prior revision, frozen profile/history and actual field prompts, configured connector ID,
and JSONB input/result hashes. It publishes typed derived history without inventing speech.
The base NPC/Narrator configuration is not overwritten or automatically backfilled.
Native dialogue globals and subsequent refresh inputs overlay only revisions acknowledged
by the selected snapshot or its explicit saved ancestry. Complete accepted provenance and
the full prior chain are required. Earlier saves cannot inherit later generated fields.
Legacy profile writes retain their prior behavior. Current base edits remain configuration,
not save-versioned manual development; visible generated fields take precedence in prompts.

Final publication checks the original link, active generation, unchanged base dynamic fields
and prior revision under the session lock. Conflicting replay and a newer unacknowledged
result reject publication. Provider calls are outside the lock.

Schema 20260905024 connects native batches to `worker_jobs` as one idempotent job per
request/actor/role. The batch is removed only in the transaction that creates those jobs.
A claimed/streaming refresh waits for completion; a definitively invalid completed owner
is retained under `dynamic_profiles_failed_*` rather than repeatedly blocking dispatch.
All selected field prompts, token options, saved history, base/prior profile and connector
ID are persisted in `profile_job_inputs` before the first provider call. Credentials remain
live configuration and are never copied into the frozen input. The existing field prompt
builder is reused in provider-free preparation mode. Each accepted field has an independent
hash-checked checkpoint; retries do not regenerate completed fields or reload prompts.

Jobs allow three attempts, a 30-minute deadline and a two-minute fencing lease renewed
before each unfinished field. A different live worker cannot own the same canonical actor.
The scheduled dynamic-profile task recovers expired attempts, uses ten-second retry backoff,
and retires old generations or terminal failures while retaining diagnostic state. Invalid
hashes/inputs are terminal. Final complete-set publication and ready state commit together.
Failures never publish a partial selected field set. Provider calls can repeat if a process
dies after the remote response but before its checkpoint; only publication is exactly-once.
This is not a claim of exactly-once remote billing. Real configured-provider and manual
in-game save/load acceptance remain separate from controlled worker/protocol tests.

Schema 20260905025 adds native NPC diary inputs, source references and accepted
results. Storage originally landed without public admission; the manual v2 client path
is described below. Internal capture verifies
the original canonical protocol/request records and freezes the acknowledged actor/snapshot,
saved profile revision, bounded visible history, prior diary and supplied generation settings.
Publication requires the completed owner under the native active-generation lock, preserves
input/result hashes, and emits a derived reflection with no audience. It never writes diarylog.
The latest-diary prompt reader accepts only entries whose full prior/profile/source provenance
is valid and visible from the selected saved frontier; legacy lookup remains v1-only.
NPC and Narrator roles share this journal machinery but never share entries.

Schema 20260905026 adds a unique diary-input link to worker_jobs and input-hash-bound accepted
text checkpoints. Manual NPC admission freezes profile diary settings, actual messages and the
selected diary connector before creating its job transactionally. No connector means disabled;
automatic-diary toggles do not block explicit manual requests. Diary history is bounded to200
records and total frozen input to1MiB. No credentials are stored in the frozen input.
The existing dynamic-profile task dispatches up to five eligible completed-owner diary jobs per
tick. Claiming serializes by canonical actor; retries use three attempts, a 30-minute deadline,
two-minute fencing lease and ten-second backoff. Accepted text is checkpointed before journal
publication and ready state commit together. A changed input cannot accept an earlier checkpoint.
Session-generation and lease checks reject late side effects. Provider calls remain outside
transactions and may repeat if a process dies before checkpointing; publication is idempotent.

Native v2 diary_request accepts 0-16 unique canonical actors, boolean include_player and
include_narrator, and reason=manual|sleep|wait. At least one NPC, Player or Narrator must be selected.
Each selected role requires its own diary.manual.npc/player/narrator capability.
Automatic reasons additionally require diary.automatic.sleep or diary.automatic.wait, matching
the initialized session. These are server admission contracts; the current client does not yet
advertise automatic observation or emit automatic requests. Engine-event wiring is still required.
Only linked actors visible in the exact acknowledged target/audience snapshot are admitted.
Unknown modes/keys, unnegotiated capability, invalid identities and stale ownership reject.
The client /diary command submits one pointed actor on its bounded background network lane.
The exact /diary narrator command needs no target and sends actors=[]/include_narrator=true.
Narrator ownership comes from the canonical player in the acknowledged context, never from
a roleplay alias or fabricated NPC. Its profile diary connector falls back to profile.connector_id.
Its saved profile, player-owned history and prior Narrator diary are frozen independently from NPCs.
Roleplay placeholders render in messages only; canonical identity and stored source evidence stay unchanged.
The existing latest-diary prompt toggle also reads save-visible Narrator entries, with no legacy fallback.
The exact /diary player command is targetless and gated by the existing Player diary_enabled
setting, including manual requests. Auto-diary enablement is separate. Player has its own journal
role, configured presentation name/background/bio fallback/appearance/speech style, player-owned
history and prior Player diary. It never inherits a Narrator dynamic profile or NPC private history.
Core connector selection follows explicit player diary ID, otherwise first configured summary,
player or profiles ID; if that selected core record is unavailable, the configured legacy driver
is selected. Frozen inputs explicitly distinguish {kind:core,id} from {kind:legacy,driver}; legacy
selection has connector_id=null. Workers never infer a core record from that driver or re-resolve
defaults during replay. Old positive-ID-only frozen inputs remain supported. Player's diary is
private reflection; it is not automatically inserted into NPC/Narrator dialogue or legacy diary UI.
Admission atomically commits jobs and the replayable response_start/status/response_end body:
status code diary_queued means at least one job was admitted; diary_disabled means all selected
roles lack eligibility. Detail gives queued/disabled counts, never a completion claim.
Player batches also count a disabled diary setting. Automatic batches count disabled role policy,
active cooldown, pending work/result or absent connector; status accepts old and new receipt forms.
NPC automatic settings inherit profile AUTO_DIARY_ENABLED/AUTO_DIARY_WAIT_ENABLED with explicit
extended_data overrides. Player requires diary_enabled and auto_diary_enabled, plus
auto_diary_wait_enabled for waiting. Narrator uses auto_diary_enabled for both sleep and waiting,
without a separate wait/master diary gate. Its missing setting can use NARRATOR_AUTO_DIARY_ENABLED.
Cooldowns are real seconds: DIARY_COOLDOWN (default30), with NPC profile override only.
Admission serializes under the active session-generation lock and refuses another pending attempt
or unacknowledged result for the same actor/role. Only successful, integrity-valid, save-visible
automatic diary publications start cooldowns; sleep and wait share the role's cooldown. Manual
requests bypass automatic policy/cooldown (Player's master gate still applies). Policy and duration
are frozen before dispatch; changing settings does not redirect replay. Failures start no cooldown.
Earlier saves exclude future cooldowns and descendant saves retain their accepted ancestor state;
no name-based conf_opts timestamp or game-time jump determines this decision.
Any invalid actor/configuration aborts the complete batch; repeated IDs replay original bytes.
Native diary jobs survive a normal newer dialogue turn, but not lifecycle/explicit cancellation.
Generated results require a later acknowledged snapshot for saved visibility. POST the exact
original diary_request to /diary_status.php for a read-only status; the endpoint never claims,
replays or dispatches admission. Replies are exactly start/status/end with original ownership.
Codes: diary_queued/running/ready/failed/cancelled/disabled. Ready validates all admitted jobs,
input/result/source provenance and actor binding, but does not mean saved. Changed/missing/
retired owners reject HTTP409; transient reads return HTTP503. No output content is returned.
Both native adapters capture a fresh game-thread snapshot only after ready; completion
notification requires its local co-save acknowledgement. Earlier saves remain isolated.
This adds no v1 diary behavior. Manual flat/VR acceptance remains outstanding.

Native quest transitions use optional v2 context.quest_events batches; see
protocol/v2/README.md and schemas/quest-events.schema.json for the closed contract.
Flat sessions must register context.quest_events. Native stage/item/failed scalars
are kept separate from sampled quest journals. Visibility requires fresh displayed
objectives in this or a prior same-owner context; missing evidence rejects HTTP409.
Batch conflicts reject HTTP409, persistence failures HTTP500, malformed batches
HTTP422. Every failure rolls back admission. Identical same-owner batch retries
preserve the original history once, even if the quest later leaves the journal.
Client hook activation and automatic AI reactions are not enabled by this contract.

Quest-aware NPC prompts project only that responder's save-visible, directly addressed
player reports and exact authored participant background. Journal capture and scalar
quest transitions remain immutable audit evidence, not NPC hearing or event witnessing.
Only an explicit narrator response role receives the owned player journal. The existing
native journal-action permission and old-result redaction remain in force. This projection
uses the current v2 contract without changing schemas; see [QUEST-AWARE-KNOWLEDGE.md](QUEST-AWARE-KNOWLEDGE.md).
# Native control menu extension

The additive v2 `control.menu` capability permits a `control` event with `context_sequence: 0` and exactly `payload.setting` / `payload.value`. Settings are `status` (empty value), `mode` (the nine allowed mode identifiers), or `model_slot` (`"1"` through `"4"`). It uses normal initialized-session, generation, request replay and capability checks, and never advances the active dialogue turn. Preferences reuse existing `conf_opts` rows inside the session claim transaction. The terminal acknowledgement has status `control_state` and bounded detail `MODE|SLOT`.

Negotiated v2 `input_text` and `input_audio` may additionally include `payload.control` containing exactly `mode` and integer `model_slot`. These are admission-time values, not a read of subsequent global settings. Narrator input owns the narrator response role. Native Director uses the selected NPC's safe structured response/action pipeline; full multi-NPC Director orchestration remains unimplemented. Existing v1 payloads remain unchanged.

## Fresh-scene continuation contract (not activated)

The additive v2 `dialogue.rechat.scene` contract adds a separate
`payload.scene_context_sequence` to an owned rechat trigger. It requires parent
and turn ownership, a positive safe integer newer than `context_sequence`, and
the unchanged parent request/line. Neither chain ancestry nor response ownership
is replaced with the fresh reference. See `protocol/v2/README.md` for activation
requirements across selection, prompts, listener routing, actions and witnessed
history. Client defaults do not advertise this capability. Until those consumers
and the explicit acknowledgement are implemented together, preparation rejects
scene-bearing requests before database/profile/provider work. Existing parent-only
rechat remains unchanged; schema acceptance is not runtime support.
