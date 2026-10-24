# Everyday Fallout lore and pronunciation

The catalog contains 125 articles. Twenty new topics and one farming correction
cover tatos, mutfruit, razorgrain, tarberries, familiar foods, bottle caps, chems,
American consumer culture and common vocabulary. The other 104 articles retain
their authored text. Sources, interpretation and uncertainty remain in the JSONL
attribution records, outside dialogue prose. This is selected coverage.
Content migration `20261008003` installs this catalog on existing source upgrades;
its factory-only test import preserves complete existing NPC sheets.

## Knowledge in ordinary dialogue

Eligible requests with World Knowledge enabled receive the effective
`wasteland_vocabulary` article's basic text as one bounded reference, in addition
to topic-specific retrieval. It explains that tatos combine tomato/potato traits,
caps are money, common chems have distinct effects, Rad-X differs from RadAway,
and familiar consumer imagery does not give every character the same slang.
The reference is capped at 2,000 UTF-8 bytes and never grants its advanced tier.
Custom overrides, disabled articles and access restrictions are honored. A known
prewar identity or prewar-only tags deny the postwar baseline. The global/profile
World Knowledge switch also controls this reference, including boolean false.

Definition questions such as “What is a tato?” and “What does Jet do?” now count
as explicit knowledge requests. Unique short terms can resolve to actual catalog
entities; ambiguous ordinary references still retain their existing protection.
Tomato/tomatoes are not aliases for tato and are never globally replaced. Basic
recognition is public postwar knowledge; farming, medicine, trade and historical
expertise remain scoped. Neutral prewar products have explicit prewar basic access
only where sourced. Jet origins and Addictol's uncertain prewar dating are withheld.
No inventory, current treatment, player experience or quest result is manufactured.

## Spoken pronunciation

Two built-in dictionary entries split `radroach` to `rad roach` and `radroaches`
to `rad roaches` for speech synthesis. Whole-term matching preserves canonical
subtitle/history spelling. The adjusted text enters the immutable TTS plan before
queueing, changing its audio cache identity. This uses the shared plain-text path
for the selected provider, without cloud dictionary creation or phoneme markup.
Migration `core_tts_pronunciation` version `20261008003` seeds missing entries after
the old cleanup, preserving existing rows and built-in toggles. Enabled custom
rules keep their precedence. A new built-in starts disabled if a prior global
custom opt-out exists. Existing per-rule disabled scoped fall-through semantics
remain unchanged; disabling the built-in controls the default explicitly.

This respelling is a synthesis hint, not audible proof. Tato and mutfruit phonetic
overrides remain withheld pending an observed problem and listened-to canon audio.
Direct fixed acknowledgements do not need a radroach rewrite; ordinary generated
NPC responses already pass through the dictionary. Optional emotional delivery
remains a separate opt-in roadmap item.

## Return checks

Ask a postwar NPC about tatos versus tomatoes, paying in caps, Jet/Psycho/Buffout/
Mentats, and Rad-X versus RadAway. A nonexpert may recognize basics without claiming
medical practice or current stock. Check a robot and a DLC NPC as well. Known 2077
actors must not receive mutated crop/cap-currency facts. American imagery should
preserve each speaker's own speech style.

Have the selected voice say radroach/radroaches in an ordinary response. Confirm
short-a “rad”, canonical subtitles and a newly generated audio key. Test a custom
rule and the built-in disable toggle. Provider/voice-specific audible behavior
requires a paired game capture; backend tests do not establish it.
