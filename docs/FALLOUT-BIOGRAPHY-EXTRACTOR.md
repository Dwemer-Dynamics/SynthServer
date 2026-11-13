# Fallout biography audit and draft workflow

These maintainer tools run from a clean checkout with Python 3.11 or newer and
the standard library. They never connect to the database, install biographies,
edit the shipped catalog, or change game files. Keep all outputs outside the
checkout. Review drafts before importing anything.

## Inventory and coverage

Use a legally installed game's unmodified official masters and English string
archives. This exports metadata, not game assets for redistribution:

```powershell
python tools/export-fallout-actors.py --data "GAME/Data" --output "WORK/inventory.json"
python tools/extract-fallout-biographies.py --inventory "WORK/inventory.json" --pilot --output "WORK/pilot"
```

Replace `GAME` and `WORK` with your explicit paths. Without `--inventory`, the
second command still reports catalog counts and missing export inputs. Missing
masters are recorded as incomplete, never as zero missing actors.

The exporter handles official ESM record/group boundaries, compressed records,
extended subrecords, localized FULL labels, loose English STRINGS, and GNRL BA2
versions 1/8. String decoding tries UTF-8 then the legacy Windows code page 1252.
It records master and string-source checksums and the matched archive entry path.
Record structure and name resolution are reported separately: a missing archive,
missing entry, unsupported archive version or unresolved label sets the plugin's
`name_resolution_complete` to false with a `name_resolution_reason`. Unsupported
or malformed records (including bad HEDR, zlib or field sizes) abort the export
with a short refusal. Master indices resolve to the owning plugin plus the local
24-bit form, rather than the workstation's load-order prefix. Overrides follow
the official installed load order: Fallout4, DLCRobot, DLCworkshop01, DLCCoast,
DLCworkshop02, DLCworkshop03, DLCNukaWorld. Only NPC_ and ACHR metadata are exported.
Quest-created aliases, template inheritance and cut-content eligibility are not
inferred. No executable from a third party is needed.

`coverage.json` distinguishes structural record completeness from reviewed
named-cast completeness. Exact base identities, physical-form aliases and
reference-only catalog bindings are reconciled independently. Unreviewed ACHR
instances are counted separately and do not create additional biographies.
An explicitly reviewed reference-only candidate retains that binding kind.
An eligible reference whose exact base link points to a base that is also
reviewed eligible is withheld as ambiguous together with that base, even under
different character keys, until the reviewer excludes the redundant form. Several
eligible references may still share a generic base that is not itself eligible.
A base placed by a catalog-bound reference (for example a shared robot base) and
a reference placing a catalog-bound base stay ambiguous with that reason; neither
can become a second biography, and a shared base gains no name-specific binding.
Deleted records are excluded; unknown
classification remains ambiguous. A unique flag or display name never proves
that a form is an eligible, distinct character.

An external inventory adapter may emit the same `synth.actor-inventory.v1`
shape: `plugins` in the official load order above, with `plugin`, boolean
`complete`, `sha256` and boolean `name_resolution_complete` for complete exports,
and provenance; plus `actors` grouped by `export_plugin` in that order. The last
declared plugin wins; interleaved rows, duplicates and overrides declared before
their owning plugin are refused.
Each actor has `plugin`, `kind` (`base`/`reference`), eight-digit uppercase
`local_id` beginning `00`, `export_plugin`, `editor_id`, `display_name`, boolean
`deleted`, and `unique` (boolean/null for bases). References may include `base`
with its owning `plugin` and `local_id`. Preserve unresolved names as empty with
`name_unresolved: true`. Do not replace identifiers with a display-name match.

## Review missing identities

Review ambiguous rows using the game records and source identity evidence.
Create an external ledger pinned to the exact inventory bytes:

```json
{
  "schema": "synth.actor-review.v1",
  "inventory_sha256": "SHA256_OF_INVENTORY",
  "actors": [{
    "plugin": "Fallout4.esm", "kind": "base", "local_id": "00ABCDEF",
    "editor_id": "EXACT_EDITOR_ID", "status": "eligible",
    "character_key": "fo4_reviewed_character", "aliases_checked": true,
    "reason": "Record and source identify this character; checked catalog forms and quest aliases."
  }]
}
```

The example identifiers are placeholders. `excluded` and `ambiguous` are also
valid statuses with a reason. Every eligible decision is grouped by its proposed
character key first: if more than one form shares a key, all of them are withheld
(even when one is otherwise unresolved) until a canonical binding is settled. Existing
catalog keys cannot become new candidates.

Real ACHR records carry no FULL, so their exported `display_name` is empty. A
reference entry may add a reviewed `display_name` (non-empty single line, at most
128 UTF-8 bytes, no ASCII control characters); base entries may not. It is the draft label only: the exported
record stays unchanged in `coverage.json`, the missing entry reports it as
`draft_display_name`, and the candidate's `display_name` uses it. It is not
evidence that the game record carries that name and is never used for binding,
which still requires an exact `refid=`/`editorid=` proof. Base candidates and
references without a reviewed label use the record's own name.

All seven official base/DLC plugins are reported; structural exports alone do
not certify a complete named census.

## Fetch permitted sources once

Create an external queue. Sources are explicit page selections, not name-based
actor bindings:

```json
{"schema":"synth.biography-sources.v1","sources":[
  {"id":"reviewed_page","host":"fallout.wiki","title":"EXACT_PAGE_TITLE"}
]}
```

Only the two existing wiki hosts (`fallout.wiki`, `fallout.fandom.com`) are
allowlisted. Redirects and other schemes/hosts are refused. Before retrieving
articles, online mode checks the host's robots policy and API reuse notice.
Blocked or unavailable policy checks stop that host. It does not evade access
controls or substitute search snippets. Requests have a timeout and size bound,
wait at least two seconds (or the longer robots crawl delay), and stop a host
after an article HTTP error, timeout, dropped connection or truncated response,
recording the exact reason; other hosts continue. Raw robots/rights responses
that caused a denial are kept as `policy_evidence`. No automatic retry is made. A fresh external cache
directory is required for an intentional later refresh.

```powershell
python tools/extract-fallout-biographies.py --inventory "WORK/inventory.json" --review "WORK/review.json" --sources "WORK/sources.json" --cache "WORK/cache" --online --pilot --output "WORK/pilot"
```

Caches retain the response, revision, timestamp, source URL, attribution, history
link and policy evidence. Unavailable sources are cached as unavailable. Offline
mode is the default and never contacts the network. Every existing cache file for a
queued source is checked before the first request or cache write; a mismatched, corrupt or
unknown-status entry anywhere in the queue refuses the run. Cached successful records
are checked: response checksum, revision, timestamp, title,
canonical URL, attribution and history link, rights metadata, and robots
permission for the exact article and rights request URLs. Local cache
files and review ledgers remain maintainer-controlled evidence, not signatures
from the publisher. Review applicable terms again before distributing content.

## Write original drafts with field evidence

Author an external `synth.biography-evidence.v1` object with an `actors` list.
Each entry repeats the plugin/kind/local_id/editor_id and sets
`starting_state_reviewed: true`. Include `source_binding` with `source_id`,
`revision_id` and a short exact `quote` from the cached article corroborating
the form ID or editor ID in an explicitly labeled `baseid=`, `formid=`, or
`editorid=` field (`refid=` for reference-only bindings; spaces are allowed in
those labels). IDs must be complete tokens, never substrings or name
resemblance. Bare or `00` form IDs prove only `Fallout4.esm` forms. Every DLC
form ID, base or reference, needs an explicit owning `Plugin.esm|00XXXXXX` (or
`Plugin.esm|xxXXXXXX`) qualification: an unqualified `xx` ID cannot show which DLC
owns a local ID that several DLCs may reuse. DLC editor IDs need the same `Plugin.esm|EditorID` qualification,
and an editor ID shared by several exported forms cannot bind any of them. A page
title alone cannot bind an actor.

`fields` maps each supported field to `text` (original roleplay prose) and a
`support` list of `{source_id, revision_id, quote}`. Every support must use the
identity-bound source, and the candidate's `source_url` and `source_ids` come from
that binding. Quotes must occur in the cached revision and be at least 12
characters and two words; a matching quote shows support, not that the prose is
accurate, so original prose still needs human review. Supported fields are `core` (short description),
`npc_static_bio` (background, including former expertise), `appearance`,
`personality`, `occupation`, `speechstyle`, and `goals`. Leave unsupported fields
empty. Introductory core and background are required to emit a candidate.
Keep contingent quest outcomes, player choices and invented mannerisms out.
Keep distinct authored goals as distinct bullets when supported.

The extractor rejects authored `skills`: that field belongs exclusively to
actual in-game actor stats. Knowledge, relationships, history, voice and race
remain empty and retain their existing owners.

```powershell
python tools/extract-fallout-biographies.py --inventory "WORK/inventory.json" --review "WORK/review.json" --sources "WORK/sources.json" --cache "WORK/cache" --evidence "WORK/evidence.json" --pilot --output "WORK/pilot-reviewed"
python tools/extract-fallout-biographies.py --inventory "WORK/inventory.json" --review "WORK/review.json" --sources "WORK/sources.json" --cache "WORK/cache" --evidence "WORK/evidence.json" --output "WORK/backlog"
```

Review the pilot (up to ten genuinely missing identities) before the full
available backlog. Repeat either command into a second output directory for
byte-identical cached results with the same inputs. `drafts.json` retains field
and identity evidence, source records and every pending reason. Each candidate
uses the existing catalog NPC identity/fields shape, inside a draft-only
envelope. `drafts.csv` preserves the existing 15-column importer header and
contains no runtime skills. Malformed ledgers, queues or inventories are refused
with a short message before any network access, output or cache write. Every
evidence entry is checked first: a valid, unique, currently missing identity,
only supported authored fields, and string text within limits. Missing or
unmatched support is not malformed; it leaves the actor pending. CSV alone cannot carry plugin bindings or source
attribution: keep the JSON alongside it and do not import automatically.

Zero emitted drafts is a valid incomplete result when identity review, policy
access, or field evidence is absent. Report that limit rather than claiming the
cast is covered. Before distribution, retain per-source attribution and the
applicable share-alike terms; code licensing does not replace content licensing.
