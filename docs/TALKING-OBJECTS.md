# Talking objects (design)

A talking object is a private narrator bound to one world object (a terminal, radio, holotape, weapon or similar). The
player addresses it, and it answers in its own voice and persona with its own private transcript. It is an extension of
the private narrator in [PRIVATE-NARRATOR.md](PRIVATE-NARRATOR.md), not a second system: definitions, immutable
revisions, the save-checkpointed state, the completed-audio receipt and the NPC-isolation rules are unchanged.

Status: design plus protocol acceptance. The server validates and refuses object requests with
`narrator_object_unavailable` and never advertises `narrator_object`; storage lands with the schema redesign (see
[Storage addendum](#storage-addendum-for-the-schema-redesign)). Nothing here has been run in game.

Reference behaviour: CHIM 4 `docs/design/narrators.md` and chevo's talking-object checkpoints (`NarratorCheckpoints`
object bindings). SYNTH ports the ideas, not the files.

## Terms

- **Object definition**: a private narrator definition with `scope = object`. It is authored in the server UI like any
  narrator (name, personality, background, speaking style, voice, profile) plus an optional `object_hint` (what the
  thing is, for the prompt). It is never selectable, never returned in `available`, and is not the scene narrator.
- **Binding**: the one world object an object definition belongs to inside one save lineage. Written once, immutable.
- **Address**: the client-side routing choice that sends typed input and push-to-talk to one bound object instead of
  NPCs or the selected narrator.

## 1. Binding by stable object identity

The wire identity is `object` = `{ref_plugin, ref_id, base_plugin, base_id}`, normalised the way the CHIM 4 server does:

| Field | Plugin-origin reference | Runtime-created reference |
| --- | --- | --- |
| `ref_plugin` | file the reference originates from | `null` |
| `ref_id` | `0x00` + the plugin-local ID (light plugins: 12 bits); load-order digits are zeroed so a changed load-order index does not rename the object | the full `0xFFxxxxxx` ID |
| `base_plugin` / `base_id` | always a plugin and its local ID | same |

Rules (enforced by the server schema and again by the client before any request):

- Match is exact on all four fields. The client never adopts another reference with the same base, name or position, so
  two identical radios stay two objects.
- Actors are refused (robots, see [section 6](#6-fo4-specifics)), as are stacks (`count > 1`), objects whose base is
  created at runtime, and objects that already have a definition bound to them.
- A binding is written once. A definition already bound is never rebound, and an object never gets a second
  definition in the same state. A different object needs a different definition.
- `object` carries no name. A copied `display_name` (at most 64 characters, untrusted) travels beside it in a turn only
  to describe the object in the prompt; it is not identity.
- Runtime-created (`0xFF`) references are only as stable as the game keeps them. When the ID no longer resolves, the turn
  is refused and nothing else is adopted. The binding stays dormant in the state and works again if the ID returns.

Binding is created in game: the player looks at an object, runs `/narrator object bind <number or name>` naming an
unbound object definition from the library, and the server applies that definition's current revision into the state
(it counts against the existing 16 applied definitions per save) and records the binding in the same state. The state
carries it, so loading an older save does not see a newer binding.

## 2. Addressing the object

Look-at plus command (hotkey follows the same path):

1. `/narrator object` reports what the crosshair/look cone selects and whether it is bound.
2. `/narrator object bind <number or name>` binds it (above).
3. `/narrator object talk` addresses it. Typed chat and push-to-talk then go to the object; the selected narrator stays
   selected. `/narrator npcs` or `/narrator talk` leaves it.
4. A hotkey (flat key, VR semantic controller action) calls the same operation as `/narrator object talk`; it is a
   settings-catalog follow-up and is not part of the first client change.

Targeting is engine-free and uses the copied snapshot: the single eligible candidate in `nearby_items` /
`points_of_interest` with `looking_at` or `held`, within 300 units, with no second candidate within 40 units of its
distance (ambiguity refuses; it never guesses). Addressing is checked again for every turn on the exact identity: the
object must still be in the snapshot, same cell, within 350 units. Leaving range, a cell change (the object is no
longer in the snapshot), a load (a new runtime generation) or `/narrator npcs`/`talk` ends it and tells the player. Looking
away does not. A dialogue Stop does not end it.

## 3. Persona and voice

Persona is the definition's revision (name, personality, background, style) plus `object_hint` and the copied object
facts (display name, kind, location facts already allowed for typed narrator turns). The system prompt names the object
as the speaker's body or medium and keeps the existing rule: spoken words only, at most four sentences, nobody in the
world hears it. The voice and provider come from the revision's voice and profile exactly as for a narrator, resolved
live. v1 speech is centred like the narrator, because it is VR-safe and needs no emitter. A positional emitter at the
object is a later, optional presentation change and needs no protocol change.

## 4. Per-object transcript and the checkpoint rules

The existing state already keeps a transcript head per definition (`persona_heads`) beside the selected head. An object's
transcript is simply the chain for its own definition. Nothing is invented:

- the player's input is recorded before the provider call; the reply enters the transcript only on a completed-audio
  receipt that names the exact request, definition, revision and parent state;
- cancel, selection change, edit, load and session replacement retire pending output (an object turn is lane-global
  single flight, like a narrator turn);
- an acknowledged context checkpoint captures the state, and the next save stores that checkpoint; an older save keeps
  its older bindings and transcripts;
- object text never reaches NPC history, journals, memory or prompts;
- `narrator_command` `transcript` may carry `definition_id` to page that object's transcript with the existing
  state-bound cursors.

## 5. Protocol

Capability `narrator.object.v1` (requires `narrator.private.v1`, `context.saved_anchor`, `dialogue.turn_ownership`); the
server answers with the status flag `narrator_object` only when it can honour it. Schemas live in
`protocol/v2/schemas/narrator.schema.json`.

| Message | Change |
| --- | --- |
| `narrator_command` | new action `bind_object` with `definition_id` and `object`. `transcript` may add `definition_id`. |
| `narrator_turn` | optional `object` plus optional `object_name`. With `object`, `definition_id` names the object definition, `kind` is `typed` or `voice` (never `scene`), and the selection is not consulted. |
| `narrator_state` | adds `object_definitions` (library, at most 16) and `objects` (bindings in this state, at most 16). `available` stays narrators only. |
| `narrator_receipt`, `narrator_reply` | unchanged; they already name the definition, revision and parent state. |

Server checks for an object turn: capability granted; state ID current; `object` normalises and equals the stored binding
of `definition_id` exactly; the definition is object-scoped; no scene turn; the usual provider/speech checks. Refusal
codes: `narrator_object_unavailable` (capability or storage absent), `narrator_object_unbound`,
`narrator_object_mismatch`, `narrator_object_bound`, `narrator_object_invalid`. A request without the capability, or an
object payload on a definition that is not object-scoped, is a schema failure.

## Storage addendum for the schema redesign

Target: PR #63's narrator domain (`core_narrator_definition`, `core_narrator_transcript`, `core_narrator_checkpoint`).
No new table. Implement only after the step that lands the narrator domain.

1. `core_narrator_definition` (per-definition `settings json`): add `scope` (`narrator` default, `object`) and
   `object_hint` to `settings`. Scope is fixed at creation and is not editable in a revision. A migration is not needed
   in a fresh baseline; the legacy table is replaced.
2. `core_narrator_checkpoint.manifest json`: add `objects` = map `definition_id -> {ref_plugin, ref_id, base_plugin,
   base_id}`, beside `applied_revisions` and `persona_heads`. A `bind_object` writes a new checkpoint row with action
   `bind` (extend the action enumeration with `bind`). Uniqueness (one binding per definition, one definition per object)
   is checked against the parent manifest inside the same transaction that writes the checkpoint; the claimed-request
   session lock already serialises it.
3. `core_narrator_transcript` already keys entries by narrator/persona; object turns reuse it with the object's
   `definition_id` and `persona_heads`.
4. `core_narrator_generation` (pending output) already names definition and revision; no column added.
5. DAO: `NarratorDAO::bindObject(parentToken, definitionId, binding)` and a read of `manifest.objects`; the repository
   layer must keep provider and speech calls outside the transaction.

Open for #63: whether `scope` belongs in `settings` or as a real column for the library list filter (a column is cheaper
for the UI query; the manifest and transcript need nothing).

## 6. FO4 specifics

- **Terminals** are references to a terminal base (`TERM`), non-actors. They are persistent plugin references, the best
  v1 case. The client already classifies them as the `terminal` point-of-interest kind.
- **Robots are actors** (Protectrons, Mr. Handy, Assaultrons, Eyebots, Sentrybots). They are refused here on purpose: a
  robot already speaks through the NPC path with its own profile, voice, history and memory, and it is allowed by the
  client's conversational-class rules. A bound narrator on an actor would duplicate that identity and leak private text
  beside NPC memory. Inactive robots stay actors and stay on the NPC path. A "talking robot" is an NPC profile, not an
  object.
- **Radios, holotapes, weapons and held items.** World references of type `ACTI`, `FURN`, `CONT`, `NOTE`, `MISC`,
  `BOOK`, `KEYM`, `WEAP`, `ARMO`, `ALCH`, `AMMO`, `INGR` are eligible; `held` is the engine's grabbed object, which keeps
  its reference. Items inside an inventory have no reference; v1 does not bind them. An equipped weapon or a holotape in
  the Pip-Boy needs an instance identity probe first (does a reference or unique instance survive drop, pickup, storage
  and save/load?). Until that evidence exists the client refuses inventory items.
- **Companion/settlement ownership:** a workshop-owned terminal is an ordinary persistent reference and is eligible.
- **Flat versus VR:** targeting is snapshot-based, so both lanes share it. VR may use the controller pointer later; flat
  uses the crosshair/look cone. The hotkey follows the existing semantic-action path in each lane.
- **Gameplay evidence** (flat and VR), persistence of `0xFF` references and the item-identity probe are all open and are
  listed in [ALPHA-ACCEPTANCE.md](ALPHA-ACCEPTANCE.md) when the client change lands.

## Delivery plan

1. This design and the closed protocol additions (this change), with server refusal acceptance.
2. Paired client change: targeting, codec, `/narrator object` commands, refusal handling.
3. After the narrator step of #63 lands: storage, `bind_object`, object turns, transcript paging, UI for object
   definitions, then `narrator_object` is advertised.
4. FO4 identity probes and in-game acceptance.
