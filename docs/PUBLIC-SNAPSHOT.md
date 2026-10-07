# Public source snapshot

The maintainer chose a clean public snapshot on 2026-10-06. Original development history and
private PRs remain in the private RANGROO/Synthserver repository. The public source starts from
the reviewed server cleanup at c12fb1b4e552a1f8d6c7cab0a6a81a4a46d448d6, with public handoff
and alpha-first contribution instructions added. Historical commit references in feature docs
may refer to the private archive; they are evidence receipts, not public checkout requirements.

Keep LICENSE, MIT-LICENSE and all third-party notices. A clean Git history does not change
authorship or third-party terms. Asset/source review items remain listed in ALPHA-ACCEPTANCE.md;
publishing this experimental source does not mark those or gameplay acceptance complete.

Branches: synth is the default/main lane; every development branch and PR starts from/targets
alpha. dev and unstable are reserved lanes. All four initially contain the same alpha source;
none is a tested stable release. Configuration, databases, voices and runtime files stay local.

Publication scan review: thirteen generic-key detections are cache-key fixtures and literal
silent-voice identifiers, not credentials. The scan does not cover every possible secret.
