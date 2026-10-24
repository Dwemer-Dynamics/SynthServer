# Character backlog and form completion — 8 October 2026

The listed backlog now has sourced biographies, all nine required character
fields, checked identities and explicit uncertainty notes. The catalog has 204
templates. Four unsuitable live bindings have completed drafts and remain withheld.
This source change does not deploy content, import the live database, generate
provider voices or complete paired gameplay acceptance.

## Flags requiring special identity support

| Character | Current result |
|---|---|
| Julianna Riggs | Historical background authored. Her apparent living actor is Ezra impersonating her; no false real-Julianna binding. |
| John-Caleb Bradberton | Biography authored. Preserved head uses a talking activator, while the named NPC record is a holotape voice. |
| RedEye | Radio persona authored. No ordinary physical NPC encounter or nearby-raider mapping. |
| Jezebel | Persona authored. Her detached head/voice and rebuilt dynamic body need an observed identity transition. |

Solomon and Cedric Hopton have thin published personal histories. The deceased
Automatron caravan members also have limited characterization. Their sheets use
the established occupation and relationships, and flag interpretations rather
than inventing family history, player encounters, present survival or rich dialogue.
All source/quest uncertainty notes remain in
`data/fallout4_character_completion_review.json`, outside prompt-loaded prose.

## Continuing identity and body awareness

Curie's bases `001846CB`, `00027686` and `001647C6` share `fo4_curie`. The companion
reference remains `00102249`, so changing her body does not create a different
profile owner. The accepted live body observation overrides static appearance.
In synth form she explicitly recognizes her earlier robotic life, new sensations,
emotions and vulnerability. She retains her knowledge and her own accepted
experiences. The discarded robot reference `000865D1` is not an active Curie.

Virgil's mutant base `0006B503` and physical human base `00168B89` share his
biography. Mutant Virgil knows his human Institute career and FEV past. Human
Virgil remembers his time as a mutant. His optional cure can lead to an in-person
human encounter. The recording-only base is excluded. The two physical references
are different: this change does not silently merge private runtime histories.

Deacon's companion is one continuing actor while costumes change and recur.
Current equipment establishes the disguise; clothing cannot reset memory.
Four separately placed pre-introduction appearances have cautious public-cover
templates. They do not reveal his concealed identity or merge private histories
across distinct references. Specific cross-reference player experiences need a
verified save-scoped identity transition.

## Voices

Character identity mapping and provider voice assignment are separate. The native
client captures each actor's voice type. Custom saved voices are preserved, with
Curie's explicit per-form selection described below. Missing assignments use
existing connector fallback behavior. The upload CSV's optional
voice column remains blank, so this batch does not assign new hosted voices.

Curie accepts optional form choices in the saved sheet's extended data:

```json
{"form_voiceids":{"curie_robot":"robot_voice_key","curie_synth":"synth_voice_key"}}
```

These values are operator-selected provider IDs or local voice-catalog keys, not
game RefIDs. Her accepted live robot/synth form selects the corresponding choice
for one TTS request. Without slots, native `RobotCurie` and `NPCFCurie` are distinct
defaults; a custom generic voice or locked sheet is preserved. Contradictory or
missing form evidence retains the configured voice. No profile writes occur during
selection. Existing queued speech freezes its selected voice/settings.

XTTS needs a resolvable local WAV sample. Cartesia can reuse a cached voice by
name or ID, or its existing managed clone route from a local sample. ElevenLabs
currently expects a configured provider voice ID; a captured native voice type
does not automatically supply that ID. An identifier alone does not prove a
sample, clone or provider voice exists. The existing sample resolver can fall
back to a generic actor-name WAV when a selected local key has no sample. Verify
that each Curie form resolves its own intended sample rather than a shared Curie
fallback. Provider coverage and audible verification remain open.

Native Takahashi speech is constrained to `Nan-ni shimasho-ka?`, his repeated
Japanese order phrase, before TTS and at the final delivery boundary. This also
covers direct acknowledgements. He does not automatically learn English, produce
translations or become fluent in other Japanese dialogue. Original nonverbal
sounds remain with the game for Tony, Sparks, Ahab, Grun and the named Vault 88
ferals; synthesized fluent dialogue is suppressed.

## Verification boundary

Disposable tests cover valid/invalid reference-only bindings, alias collisions,
populated schema rollback, custom/locked voice and profile preservation, immutable
form evidence, duplicate/foreign actors, CSV upload alignment and canon speech.
Live Curie template resolution and both audible forms need a game capture. The
listed content/voice return checks remain unchecked in `ALPHA-ACCEPTANCE.md`.
