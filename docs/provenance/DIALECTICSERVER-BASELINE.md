# DialecticServer baseline provenance

Synthserver's canonical PHP runtime was converged from the MIT-licensed
`Dwemer-Dynamics/DialecticServer` `unstable` branch at:

- Source repository: `https://github.com/Dwemer-Dynamics/DialecticServer`
- Source branch: `unstable`
- Source commit: `cc2205ecc6ed5d1fcc4f9ea3f7999c888b088cf3`
- Synthserver source commit before convergence: `5d612959e25b91fe2c92626243d093425bfcef67`
- Implementation-start date: `2026-08-11`

The source history is attached directly as the second parent of merge commit
`231589e10f5d443fa2f7c88b3d25e3ecb872389f`. The imported runtime therefore has
reviewable commit-level provenance rather than depending on the stale snapshot
that previously lived under `upstream/`.

The upstream `MIT-LICENSE` remains at the repository root. The prior snapshot's
dead-code audit is retained beside this record. The guarded SYNTH JSON protocol
is retained in `protocol/` and is integrated as the canonical runtime ingress,
not as a second application.

## Convergence mapping

| Previous Synthserver source | Unified runtime destination |
| --- | --- |
| `public/index.php` and route controllers | `main.php` / `main_synth_pipeline.php` plus guarded `synth.*.v1` ingress |
| `app/Protocol`, request validation, limits | `lib/synth_ingress.php`, `lib/synth_protocol_state.php`, and synchronized `protocol/v1` |
| request/idempotency state | `synth_meta.request_executions` and correlated audit rows |
| event/context ingestion | canonical `synth` gameplay, history and context tables |
| action issue/result state | `synth_meta.action_intents` and `synth_meta.action_terminal_results` |
| media object state | `synth_meta.media_objects`; opaque IDs and content hashes only |
| worker handoff guards | `synth_meta.worker_jobs` plus the retained domain-specific worker queues after active-turn commit |
| `migrations/` | `debug/db_updates.php`, `lib/core/database_schema/`, and one `synth.database_versioning` source of truth |
| `ui/` | canonical shared `ui/` bootstrap and navigation |
| stale `upstream/` snapshot | removed after this provenance and parity mapping was retained |

Fallout New Vegas data and product defaults are not authoritative in the
unified application. Each retained dataset or behavior must be adapted to
Fallout 4/Fallout 4 VR with explicit provenance or capability gating.
