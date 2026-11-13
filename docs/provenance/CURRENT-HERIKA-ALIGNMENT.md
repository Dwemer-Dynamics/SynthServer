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

## Engineering guidance references

Checked on 2026-11-13 for the code, comment and documentation conventions in `CONTRIBUTING.md`
and the index in `docs/README.md`. No text or source was copied.

| Repository | Ref | Commit | Files read |
| --- | --- | --- | --- |
| `Dwemer-Dynamics/CHIM_4` | `unstable` | `3098fb103dbe2cbbb06f2a5753f4c69c06e5b6d4` | `AGENTS.md`, `coding_standards.txt`, `README.md`, `docs/build-and-deploy.md`, `docs/design/speech.md`, `docs/design/world-observations.md` |
| `abeiro/chevo` | `unstable` | `fd04bcb08f6aac31dc1ca9438469b37342f26710` | `AGENT.md`, `.github/copilot-instructions.md`, `managers/README.md` and the ContextManager, MemoryManager, SaveState and PromptManager READMEs, `drivers/llm/PLAN.md` |

- **Reused:** a file-purpose comment where the name does not explain the file; intent over
  restating code; descriptive names, readable flow and one owner per responsibility; no
  gratuitous short helpers; follow local conventions; validate at the boundary and fail
  explicitly; keep provider details in drivers; a docs index that maps components to notes and
  states status and evidence limits.
- **Adapted:** the per-method comment quota became "comment where the role or intent is not
  obvious"; index rows point to existing notes and source rather than one README per manager.
- **Excluded:** the reference ORM/autoloading architecture (ORM, migrations and PSR-4
  layout), DAO rules, mandatory
  `declare(strict_types=1)` and PSR-12 reformatting, per-manager folders, the singleton
  preference, Skyrim-specific guidance and any architecture migration. Synthserver keeps its
  existing schema update chain and the layout in `docs/SERVER-FILE-LAYOUT.md`.
