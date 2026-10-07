# Fallout 4 creature biography seed provenance

This factory seed is independent Fallout 4 data. It does not copy the Dialectic
Fallout: New Vegas/TTW creature catalog. Profile prose was authored for SYNTH.

Stable identities use the originating plugin plus the eight-digit local FormID.
The initial `Fallout4.esm` records were cross-checked against the Fallout Wiki
variant tables and the Fallout Cheats Fallout 4 NPC ID list on 2026-08-31. The
runtime still sends and persists its actual base plugin and full runtime FormID;
the server converts that pair through the current load-order manifest before a
template can match. Display names are retained only for review and are never the
identity key.

Sources:

- https://fallout.fandom.com/wiki/Form_ID
- https://fallout.fandom.com/wiki/Radroach_(Fallout_4)
- https://fallout.fandom.com/wiki/Mole_rat_(Fallout_4)
- https://fallout.fandom.com/wiki/Feral_ghoul_(Fallout_4)
- https://falloutcheats.com/npcs

Dogmeat, robots, super mutants, non-feral ghouls, and other potentially verbal
actors are deliberately excluded from automatic nonverbal mapping.
