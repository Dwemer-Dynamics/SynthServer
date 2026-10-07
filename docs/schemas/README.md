# SYNTH protocol schemas

The only protocol source of truth is `../../protocol/v1`, documented in
[`../../protocol/README.md`](../../protocol/README.md).
Schemas, fixtures, and the SHA-256 manifest in that directory are mirrored byte-for-byte
to the SYNTH client repository and verified by the paired verifier. From the sibling SYNTH
checkout, run `python scripts/verify_protocol.py --peer ../Synthserver/protocol`.

The former Dialectic-era schema copies were removed because they described a competing
envelope and could drift from the strict `synth.*.v1` JSON and NDJSON contracts.
