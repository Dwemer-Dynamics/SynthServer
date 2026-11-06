# Fallout 4 character and object content

The current catalog contains **204 templates**, 125 world articles and 83 object
descriptions. The listed character backlog has been researched and authored:
19 base-game, 24 Far Harbor, 20 Nuka-World, and 13 Automatron/Vault 88 actor rows
(the two unnamed candidates are separate actors). Shades, Turing, Ahab and Grun
were added as four further DLC gaps. Of these 80 researched actors, 76 have safe
live bindings; four remain explicit research drafts. Four additional templates
preserve Deacon's pre-introduction public covers. Curie and Virgil each retain
one shared biography with physical-form aliases. Curie, Virgil and Deacon's
original factory prose was revised; the other previous rows and all item/lore
content remain unchanged.

This completes research of the selected backlog, not a full cast census. The
review file `data/fallout4_character_completion_review.json` contains sources,
conservative interpretations, sparse-history flags and withheld bindings.
Julianna's apparent live actor is an impersonator; Bradberton's head is an
activator; RedEye is a radio voice; Jezebel's rebuilt robot has dynamic identity.
Their drafts must not be imported as ordinary nearby speaking actors.

Twenty sourced everyday lore articles and one farming correction cover tatos,
other familiar crops, caps, chems, public food brands and American consumer culture.
One basic vocabulary article is available proactively to eligible postwar dialogue,
with custom precedence and knowledge/chronology gates. See [LORE-ACCURACY.md](LORE-ACCURACY.md).

## Identity and ownership

`data/fallout4_content_pack.json` is the validated source of NPC and item rows.
NPCs bind through `bio_template_actor_map` by exact owning plugin and canonical local
base identity, or an exact placed reference where the base is shared. The allowlist covers Fallout4.esm and the six official DLC masters.
Load-order-prefixed IDs are rejected. Equal local IDs in different plugins remain
distinct; modded identities are outside this catalog.
The lookup preserves existing creature mappings and supports named characters.
Mapped templates cannot fall back to display names, renamed actors, impersonators
or another plugin with the same local ID. Dogmeat remains a nonverbal creature.

Curie's robot, shared wrapper and synth bases map to one template. Her owned
current race/voice establishes body awareness and optional robot/synth voice
selection. Virgil's physical human replacement shares his canon biography with
his mutant form; the human holotape is excluded. Deacon's companion retains its
history across recurring costumes; four separate undercover references keep
their public covers. Distinct actor-reference histories are not merged. Virgil's
replacement and Deacon's pre-introduction appearances still need a verified
save-scoped transition before sharing learned player events across references.

Native Takahashi output is constrained before TTS and at the delivery boundary
to the single repeated Japanese order phrase. Tony, Sparks, Ahab, Grun, the
named Vault 88 ferals and Curie's discarded chassis cannot receive generated
fluent speech. Original game grunts, beeps, roars and canned barks stay with the
game. These limits use official plugin/form identities, never display names.
Known placed references retain speech limits when optional actor-state capture
is unavailable. Legacy name-only dialogue cannot safely establish these identities.

Biographies are self-owned background. They do not populate other NPCs' memories.
Hidden identities, private faction routes, future outcomes and invented player
friendships are withheld. Static appearance is a conservative baseline; current
clothes, weapons, location and quest state come from the active game.
`#PLAYER_NAME#` is resolved by the existing prompt substitution from the active
player profile, rather than stored in a shared biography.

Knowledge tags grant public Commonwealth basics and role-specific deeper articles.
Far Harbor and Nuka-World basics require regional familiarity; deeper articles use
regional expertise classes. Workshop and Automatron familiarity uses explicit
observed or role classes. Access classes are any-of, so a generic profession cannot
be combined with a regional class to express an AND condition. Static profiles do
not invent observed equipment, completed quests or newly discovered locations.
They do not grant `knowall`. Pre-war actor identities have a dedicated access guard;
location tags alone cannot give them post-war lore. Factory article classes are
explicit, including the corrected starter articles. Authored custom knowledge
retains its existing ownership and precedence.

## Installation and editing

The home dashboard distinguishes encountered NPC sheets from installed character
templates and world lore. Its library counters link to **NPC Biographies** and
**World Knowledge**. Existing sheets keep their authored fields; installing new
templates expands the library without creating unseen playthrough characters.
Management pages use the canonical `synth` schema. World Knowledge displays the
effective article after custom overrides, and searches topics, aliases and tags.

The versioned `fallout4_content_pack` migration inserts standard rows additively.
Existing standard content and custom overlays win. Existing unlocked native NPC
profiles receive only empty fields or the two exact generated personality/speech
placeholders. Edited fields, locks, voices, relationships, diaries, memories,
provider assignments and saves are preserved.

`php tools/install-fallout-content.php` validates both catalogs without database
writes. `--apply --fill-empty-profiles` installs the named/item rows and synchronizes
the existing factory world catalog and records content version 20261008003 in one
PostgreSQL transaction. A content or version-recording failure rolls back the whole
CLI import. Standalone import helpers retain their own transaction by default;
caller-owned mode requires an active transaction and never commits its owner.
Take a verified backup before a live installation. Re-running is safe. Dashboard
uploads write custom overrides and are distinct from the factory installer.
The shipped lore CSV matches its header-based uploader. The named biography CSV,
`data/fallout4_named_bios.csv`, matches the biography uploader's fifteen positional
columns: `npc_name, core, worldknowledge_tags, npc_static_bio, personality,
appearance, relationships, occupation, skills, speechstyle, goals, voiceid,
gender, race, refid`. All 204 CSV rows match their nine authored source fields. Relationships,
voice ID, gender, race and RefID are blank because the factory pack does not supply
those overrides. Uploading creates custom templates and replaces matching custom
entries, including their optional fields; it does not preserve existing custom
values in blank columns. The CSV does not transfer plugin/base identity mappings.
Use the validated JSON/CLI installer for a factory installation on another system.
Use the biography page's example/export format for other custom imports. Reusable
character definitions and lore should remain separate files with stable identity
metadata, attribution and a shared version manifest. A combined dashboard content
pack importer with validation, conflict preview and atomic installation is deferred.
Single-NPC JSON export can contain learned state and is not a clean factory pack.

Source history links, access dates, identity receipts and licensing are in
`data/fallout4_content_sources.json`, `data/fallout4_content_attribution.md` and the
world-knowledge attribution files. Source snapshots disclose unversioned indexed
evidence instead of inventing page revisions. Concise appearance and character-style
interpretations should be expanded only with reliable evidence.

## Verification and gameplay acceptance

Disposable PostgreSQL tests cover stable actor and plugin identity, pre-war access,
custom precedence, repeat installation and preservation of edited/locked profiles,
voices and relationship text. Bundle tests inject mid-import and late version
failures, exercise legacy populated upgrades, and check transaction ownership.
Regional lore tests cover actual NPC templates, cross-region isolation, novice
versus expert tiers and pre-war access. Factory validation covers source checksums, article
lengths, aliases, categories and access classes. Backend tests do not prove game
dialogue, observed equipment or audio.

Return checks:

- Ask Codsworth about household service and his years in Sanctuary.
- Ask Lucy about her farm and family; her biography belongs to the Abernathys.
- Ask Preston and Sturges about their own backgrounds and compare their expertise.
- Switch between an observed pistol and shotgun, then ask about the currently
  equipped weapon. Stored descriptions supply appearance, not equipment detection.
- Revisit an edited character sheet and confirm the edit and assigned voice persist.
- In an existing pre-war save, ask Codsworth about Sanctuary and robots; verify
  retrieval does not inject the Great War's aftermath or Commonwealth factions.

Backend source and disposable checks cover the selected additions, aliases,
reference-only bindings, rollback and Curie form voices. Paired gameplay remains
open: capture Curie before/after transfer, verify both audible voices and her
body awareness, revisit Deacon's disguises, meet mutant/human Virgil, and prompt
Takahashi in English. Memory/diary dispatch is a separate runtime acceptance item.
See [CHARACTER-COMPLETION.md](CHARACTER-COMPLETION.md).
