# SYNTH Fallout 4 World Knowledge parity v1

SYNTH uses the same catalog, effective-view, deterministic retrieval, access,
fallback and audit architecture as current DialecticServer. The shipped data is
independent: it contains only reviewed Fallout 4 starter articles and never
imports Dialectic's Fallout 3, New Vegas, DLC or TTW catalog.

The manifest pins the catalog CSV, vocabulary and source snapshot by SHA-256.
Custom entries remain independent of factory entries and override an active
factory topic with the same canonical identifier through the
`synth.worldknowledge_effective` view.

## Native conversation turns

Native `/talk` conversation turns (`intent=conversation`) select World Knowledge in
`managers/WorldKnowledgeManager.php` before prompt assembly. It reuses the effective
catalogue loader, deterministic retriever, access/tier decision, vocabulary article and
article renderer, and adds the same `<fallout_context><knowledge>` framing to the system
prompt. The shared helpers accept an explicit context; ordinary calls without one keep
reading the request globals and behave as before.

Inputs are explicit and request-owned:

- Settings: server configuration, then stored general settings (Global), then the
  admitted profile's `metadata`, then the NPC row's `metadata` and `extended_data`. Each
  layer keeps the ordinary admission and normalization rule, so an explicit `false`
  overrides a lower layer.
- Access tags: the admitted NPC row's `worldknowledge_tags`, stored NPC name, factory
  template tags by codename, race and faction labels, and location/worldspace tags from
  this request's bound snapshot `world`. The captured base identity decides prewar actors.
  Display-name lookup and the latest stored world context are never used.
- Short follow-ups use at most two distinct lines from the turn's already-read native
  history.

Deliberate native limits:

- No extractor/LLM fallback and no stored `current_worldknowledge_topic`, even when the
  fallback and its connector are configured; retrieval adds no provider call. An unmatched
  or unknown input selects no topic article. The vocabulary article does not depend on the
  input, so an eligible non-prewar NPC still receives it and the audit status is
  `grounded`. `no_match` is recorded only when no article at all is selected.
- No forced location, race or faction articles; those signals affect access tags only.
- Participant-only quest tiers reuse the quest view the native turn already read with its
  explicit claimed owner and the admitted NPC, so no second quest read occurs. An unavailable
  view denies those tiers; reviewed public quest text can still be granted.
- At most `WORLDKNOWLEDGE_RESULT_LIMIT` topics (maximum 5) plus one vocabulary article,
  each cut to 4000 bytes. When disabled, World Knowledge reads no catalogue, tags or quest
  state; the turn's separate native quest context still reads its quest view.
- A catalogue or settings read failure gives no articles and an `unavailable` audit row.
  It does not fail the turn. Each turn writes one `synth.worldknowledge_audit` row with
  `request_type=conversation`.

Validation so far (disposable PostgreSQL, real `main.php` over loopback, fake provider; no
compiled client, game or VR):

- Same question on native and ordinary routes: for the same selected topic, an advanced-tier
  NPC gets only its advanced text and a basic-tier NPC only its basic text; the separate
  vocabulary article stays basic. A test-authored `prewar` tag gives a denial marker
  and profile `WORLDKNOWLEDGE_INFINIUM=false` gives no block. Each native turn makes one
  provider call. In these four cases the ordinary prompts were unchanged, apart from
  the per-request shuffled mood list.
- Native boundaries: Global/Profile/NPC precedence, including string and boolean `false`.
  A custom override is served while the factory rows stay unchanged. Unmatched and
  unknown input with the fallback configured makes no extra call. A quest topic gets only
  the reviewed public premise and never raw custom text. Two synthetic NPC refs with the same
  name, using real prewar and postwar base IDs in synthetic captured state, are kept apart
  (the prewar base denies, the postwar base gets basic); not verified in game.
  The service takes its actor, profile, settings and world inputs explicitly and binds no
  ordinary per-turn identity or settings globals (shared helpers may still read process
  globals such as the legacy claimed-request flag).
- The existing World Knowledge, forced-context, prewar, vocabulary, DLC, everyday-lore,
  quest-access and native dialogue suites pass unchanged.
