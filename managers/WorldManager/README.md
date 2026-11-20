# WorldManager

The "where and when" of the game as the client last reported it in a `world_context` payload: location, worldspace, weather,
and the in-game date and time of the Fallout calendar (Gregorian month names, a 24-hour clock sent as a decimal hour, and a day
part such as "late afternoon" from `hour2part_of_day`). The Fallout 4 equivalent of the location and weather parts of chevo's
`WorldManager`.

What the world means (lore, quests, factions, places) stays with `WorldKnowledgeManager`. Place data (hierarchy, markers,
regions) stays with `LocationManager`.

## Class

`WorldManager` is immutable and pure. `fromPayload()` reads one payload; `location()` (falls back to the worldspace),
`worldspace()`, `weather()`, `date()`, `time()` and `promptBlock()` read it; `isUnknown()` is the one rule for "unknown",
"unknown location", "none" and "null". Reading the latest payload from the database stays with `ContextManager`.

`managers/ContextManager/Native/data_location.php` keeps its old function names (`synthWorldContext*`, `buildWorldPrompt`) as
thin delegations, with the same output. `buildWorldPrompt()` still falls back to its older location and weather lookups, and only
when the payload has none.

## Not covered

- There is no hold or region concept in Fallout 4; the canonical region lookup (`DataLastKnownCanonicalRegionHuman`) is still the
  legacy code over the location tables.
- The calendar is Gregorian (Fallout 4 starts on 23 October 2287). No weekday or season is derived: the client does not send them.
