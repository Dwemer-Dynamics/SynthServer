# LocationManager

The location catalog: Commonwealth locations the SYNTH client exports from the game (hierarchy, keywords, markers, worldspace
and map position, cleared/discovered state per playthrough). Design: [`docs/LOCATION-CATALOG.md`](../../docs/LOCATION-CATALOG.md).

## Today

`LocationCatalog` validates the `location_catalog` v2 event (batch and first-entry upsert), reviews each record
(`duplicate_ref`, `self_parent`, `duplicate_marker`) and logs counts. **Nothing is stored.** The handler is the thin hook in
`api/v2/dispatch.php`; ingress calls `validatePayload()` and `isSupported()`.

## Later (after the schema redesign, PR #63, step 3)

Storage through system entities (`core_location`, `core_location_marker`, `core_worldspace`, `core_location_state`) with computed
parent, region and map position, and the place-aware prompt line. Both are specified in the design doc, not implemented.
