# SYNTH Fallout 4 World Knowledge parity v1

SYNTH uses the same catalog, effective-view, deterministic retrieval, access,
fallback and audit architecture as current DialecticServer. The shipped data is
independent: it contains only reviewed Fallout 4 starter articles and never
imports Dialectic's Fallout 3, New Vegas, DLC or TTW catalog.

The manifest pins the catalog CSV, vocabulary and source snapshot by SHA-256.
Custom entries remain independent of factory entries and override an active
factory topic with the same canonical identifier through the
`synth.worldknowledge_effective` view.
