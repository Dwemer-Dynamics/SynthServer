# Location ancestry

Status: ingress validation, init acknowledgement and native prompt integration are implemented.
Nothing here is verified in game.

## What arrives

A flat v2 context that negotiated `context.location_identity` and `context.location_ancestry`
may carry `payload.world.location_ancestry` beside `world.location_ref`: the player's current
`BGSLocation` and its parent chain, nearest first, at most eight plugin-local entries, with an
explicit `end` (`root`, `unresolved`, `cycle` or `depth_limit`). The client decodes every
`local_form_id` through the loaded plugin that defines it. The contract is in
[protocol/v2/README.md](../protocol/v2/README.md#plugin-local-location-ancestry) and the capture in
the SYNTH client's `docs/LOCATION-ANCESTRY.md`.

## Validation

`lib/synth_location.php` owns the check; `lib/synth_ingress.php` calls it for every v2 world.

- Exact keys, one to eight entries; `depth_limit` requires eight entries. A root or unresolved
  parent reached at the bound keeps its actual end.
- Each entry: a plugin file name, `0x` plus six uppercase hex digits (nonzero), and a display
  name of at most 255 UTF-8 bytes (may be empty).
- No `(plugin, local FormID)` pair twice, compared case-insensitively by plugin.
- The first entry is the same form as `location_ref`: its 24 low bits for a full slot, 12 for an
  `FE` light slot. Runtime-created `FF` forms are refused.
- The field needs `location_ref` and both capabilities on a v2 flat event. Init acknowledges it
  with `location_ancestry: true` only when the init asked for both.

Every failure is `invalid_world_state` or `invalid_payload` with a specific issue code. There is
no partial acceptance.

## Storage and replay

No new table or column. The validated world, ancestry included, is stored as part of the immutable
`synth_meta.context_snapshots` row under the existing sequence, checkpoint and loaded-context rules.
A replayed context is handled by the existing idempotent publication, so a repeated export adds
nothing. The `world_context` history row still compares only the display fields.

## Integration API

For the turn-context owner (`managers/ContextManager.php` or the prompt builder). Both functions
re-validate the given world and return nothing for an invalid or absent ancestry.

- `synth_location_ancestry_facts(?array $world): ?array` returns `end`, `complete` (only for
  `root`), `interior`, `cell`, `worldspace` and `entries`, each with `key`
  (`lowercase-plugin|0xLOCAL`), `origin_plugin`, `local_form_id` and `display_name`.
- `synth_location_prompt_line(?array $world): string` returns one sentence, for example
  `Current location: Basement, within Diamond City (interior: CellarA).` Unnamed entries are
  skipped, equal names from different plugins get their plugin in brackets, and a chain that does
  not end at `root` adds `Where X sits beyond this is not known.` It returns `''` when there is
  nothing valid to say.

`ContextManager::situation()` calls the helper after copying the existing world keys:

```php
$line = synth_location_prompt_line($payload['world'] ?? null);
if ($line !== '') $situation['location'] = $line;
```

The line describes where the captured scene is for this turn only. Do not write it to NPC memory,
profiles or world knowledge, and do not use it to add audience or witnesses.

## Scope choices

Only the current location's ancestry inside the existing context: no location catalog, map
positions, markers, keyword or settlement typing, export batches or database schema. Names never resolve
identity, and parentage beyond the last entry is never inferred.

## Tests

`SocialTaskContractTest` runs the valid fixture and four invalid ones through ingress and covers
the helper: equal names across plugins, an unresolved end, replayed input, repeated and
case-repeated entries, a load-order ID passed as local, a runtime form, a false depth limit, a
missing `location_ref` and an extra field.

`NativeDialogueTurnTest` uses the installed-record Dugout Inn chain with simulated capture and
an injected provider. CELL `00001E5D` points to location `0000395C`, whose parents are Diamond
City `00002CEF` and Commonwealth `00002CF0`, all in `Fallout4.esm`. The interior has no worldspace.
The next native provider prompt contains that chain once; replay does not duplicate it, and an
unresolved end adds no guessed parent. This is off-game evidence, not runtime capture acceptance.
