# NPC biography layout and repair

The 204 named presets keep their existing CSV and content-pack format. Their authored `skills`
column is expertise prose, preserved once in Background under `Expertise:`. New actor imports
store that combined background and leave the profile's `skills` column empty. Existing profiles
render the same layout before an operator repairs their stored formatting. Short description,
appearance, personality, occupation, speech style and authored goals remain separate sections.
Explicit goal bullets remain separate; ambiguous prose remains one goal. This does not create
goal progress, priorities or completion state. Knowledge tags, relationships and saved history
retain their existing owners. The 14 nonverbal creature templates are unchanged.

## Repair an existing installation

After installing this server revision, back up the existing PostgreSQL database, then run from
the server directory using its normal configured database account:

```sh
php tools/repair-npc-biography.php
php tools/repair-npc-biography.php --apply
php tools/repair-npc-biography.php
```

The first command is a dry run. Review the JSON row reports and summary before applying.
`--apply` uses a pinned management transaction with two-second statement/lock waits. It locks only matching profile updates, while holding identity mappings and custom template overlays stable. Only an actor-bound profile
with `metadata.bio_template`, one consistent stable identity mapping, and an exact factory
background/expertise pair is changed. The comparison source is the shipped content pack, not
editable database templates. Only Background and the legacy expertise column change. Other
custom fields are preserved. Locked profiles, custom template overlays, missing provenance,
partial matches and conflicting identity mappings are reported and left alone.

The second run reports zero further repairs. Cleared or otherwise customized text is not
refilled by this repair. No persistent schema change, profile-revision rewrite, save conversion
or automatic bulk repair runs when the server starts. Older factory revisions that cannot be
proven are exceptions for manual review; the tool does not guess.

## Runtime skills

`context.actor_stats` is an optional protocol-v2 capability. Updated flat and VR clients require
the matching init acknowledgement before publishing it. Each actor-state observation has the
seven SPECIAL entries: Strength, Perception, Endurance, Charisma, Intelligence, Agility and Luck.
Each is a base/current numeric pair, or null when unreadable. Zero and fractional values are
preserved. Engine base means `GetBaseActorValue`; current means `GetActorValue`, including active
modifiers. The existing HP/AP percentages still use current/permanent and are unchanged.

The game-thread deep capture reads at most 14 additional actor values per detailed actor. The
shallow discovery scan adds no reads. Replacement, base, plugin and playthrough checks apply
before detail enrichment. Values travel with the copied actor snapshot and its existing session,
generation and context sequence. They never enter persistent NPC profile fields or generic
history. The next dialogue prompt uses only the exact responder's bound snapshot. Missing
capture remains unknown; saved biography text cannot supply numeric stats. Older clients keep
their existing contract and supply no new stat section.

Off-game tests and builds cannot establish actual engine values or gameplay behavior. Flat and
VR game-dependent acceptance remains separate; neither deployment nor gameplay is implied.

Legacy expertise is retained for compatibility but excluded from new model-generated NPC profile updates. Captured numeric values are not rounded or ranked into proficiency labels.
