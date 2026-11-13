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

- No extractor/LLM fallback and no stored `current_worldknowledge_topic`; retrieval adds no
  provider call. An unmatched turn stays `no_match`.
- No forced location, race or faction articles; those signals affect access tags only.
- The quest reader runs for this request but stays unavailable without a claimed legacy
  request. Participant-only quest tiers are therefore denied; reviewed public quest text
  can still be granted.
- At most `WORLDKNOWLEDGE_RESULT_LIMIT` topics (maximum 5) plus one vocabulary article,
  each cut to 4000 bytes. A disabled turn reads no catalogue, tags or quest state.
- A catalogue or settings read failure gives no articles and an `unavailable` audit row.
  It does not fail the turn. Each turn writes one `synth.worldknowledge_audit` row with
  `request_type=conversation`.
