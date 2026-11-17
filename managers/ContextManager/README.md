# ContextManager: actor condition

Turn context helpers for `Synth\Managers\ContextManager`.

- `ActorConditionValidator` validates the optional v2 `actor_states[].actor_condition` object
  (capability `context.actor_condition`, acknowledged by init status `actor_condition: true`). It is called
  from the protocol ingress hook in `lib/synth_ingress.php`.
- `ConditionProjector` turns the player's and the exact responder's snapshot rows into one bounded condition
  line: radiation tier, addictions, active chems and effects, crippled limbs, low health and power armor.
  Low health and power armor come from the existing `health_percent` and `in_power_armor` fields, so a client
  without the capability still gets them.

Rules: rows match by exact form, origin plugin and playthrough, never a name; names are untrusted, normalised to
64 characters, deduplicated case-insensitively and capped; the line is at most 700 characters. Nothing is stored
and there are no tables. The general setting `CONDITION_CONTEXT_ENABLED` (default on) removes the line.
See `protocol/v2/README.md` for the wire shape.
