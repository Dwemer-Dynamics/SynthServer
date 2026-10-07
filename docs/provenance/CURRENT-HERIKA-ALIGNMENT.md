# Current Herika and LORKHAN alignment baseline

Baseline capture date: 2026-08-30.

| Repository | Ref | Commit | Use |
| --- | --- | --- | --- |
| `Dwemer-Dynamics/HerikaServer` | `unstable` | `3a5b79e262c2a9256fa8af1c67fa0205dd23e7d9` | Authoritative current server behavior and management feature baseline. |
| `Dwemer-Dynamics/CHIM` | `unstable` | `165b21c11f5005ca270f4711bc1c3b8770571902` | Client-visible dialogue, audience, lifecycle and action behavior reference. |
| `RANGROO/LORKHANserver` | `main` | `bf7b946a2be7dca2a268f9ea680b0d74f8ef9d0f` | Protocol, CI, UI-validation and evidence-workflow reference. |
| `RANGROO/LORKHAN` | `main` | `e45f621218a4249999913d8dd8cef116e0bc9b96` | Shared-core and client-validation workflow reference. |

The implementation continues from Synthserver commit
`bc25a113f84b64f284ed727630860d9f105afe00`. HerikaServer is MIT licensed; reused
first-party source must retain the repository license and material notices. CHIM and LORKHAN are
behavior and workflow references unless a copied file has an explicit compatible license and a
path-level provenance entry.

Open draft pull requests are not part of the authoritative baseline. They must be reviewed and
recorded separately before any behavior is adopted.

Product boundaries remain authoritative: `synth` and `synth_meta` schemas, `synth.*.v1` JSON and
NDJSON, Fallout 4 identity and terminology, native binary media transport, and no Skyrim/FNV
runtime content or arbitrary execution surface.
