# Local curated Fallout 4 voice catalog

This workflow runs outside Fallout 4. It does not launch the game, scan during save loading,
upload all voices, change player overrides, or replace game dialogue assets.

## Build

Run `python3 tools/build-voice-catalog.py --archive /absolute/voices.ba2 --output /private/new-directory`.
Repeat `--archive` in winning order; the last matching file wins. Add installed patch archives
after official archives. Use `--loose-root` for explicit winning Data roots, in order.
The tool does not infer MO2 load order or active plugins. It supports Fallout 4 GNRL BA2
versions 1, 7 and 8, bounded zlib/FUZ records and XWMA; unsupported layouts fail explicitly.

Export English Fallout 4 dialogue from LazyVoiceFinder and add `--lazy-csv /absolute/export.csv`.
Expected columns: `Plugin`, `File Name`, `Voice Type`, and `Dialogue 1 - English`
(also accepts `Dialogue 1 - en` or `Dialogue`). A New Vegas export is not interchangeable.
No transcript is invented when the export is absent. Rows are marked `missing_transcript`.
To attach the export later without decoding again, run `python3 tools/build-voice-catalog.py
--output /private/existing-directory --lazy-csv /absolute/export.csv --join-existing`.
The prior CSV is backed up; changed transcripts clear selection/approval for renewed review.

The CSV retains five candidates per voice type by default (`--top 1..20`). The initial
shortlist uses XWMA cumulative decoded packet sizes to measure duration, not compressed bytes.
It prioritizes filenames without combat/radio/delivery hints, then duration. Each shortlisted
recording is fully decoded to mono 22050-Hz PCM16, with no loudness, pitch or EQ transformation.
Final ordering uses quality flags, estimated non-silent speech duration, total duration and
source path as deterministic tie breakers. The energy estimate is not a speech recognizer;
it cannot detect every processed voice, background speaker or unusual delivery. Listen first.

Outputs: `fallout4_voice_catalog.csv`, original-length candidate WAV files and `report.json`.
The report records coverage, failures and transcript matches. Files outside the shortlist
are measured but not exported as WAV. A fresh output directory is required on reruns, so a
new scan cannot erase review decisions. Keep all generated audio/transcripts outside Git.

## Review and import

Compare the candidates with the character's original dialogue. Set exactly one row per voice
to `selected=1`, `review_status=approved`, with the verified transcript. Leave every other row
unselected. Do not label an automated quality score as listening approval.

The importer currently accepts complete 5-60 second canonical WAV recordings. Longer original
candidates remain available for review but require a deliberately prepared excerpt and matching
transcript/hash/duration before import. It never silently cuts a sentence or alters a transcript.

Run on the deployed server, as its Apache owner, with Fallout 4 closed:

```
php tools/import-voice-catalog.php --catalog=/private/catalog/fallout4_voice_catalog.csv --voice=npcmprestongarvey
php tools/import-voice-catalog.php --catalog=/private/catalog/fallout4_voice_catalog.csv --voice=npcmprestongarvey --apply
```

The first command is a dry run. The importer validates selection, language, source voice ID,
path containment, SHA-256, WAV format and actual duration before mutation. Apply takes the
same per-voice lock as VSX, saves the original WAV and JSON under `data/voices/catalog-backups`,
stages both files and publishes new metadata before the new WAV. If WAV replacement fails,
the previous metadata is restored. Repeating an unchanged import is a no-op.

Approved catalog samples cannot be overwritten by v1 or v2 VSX uploads. Queued speech plans
capturing an older sample fail closed. New plans include the new sample hash in their existing
audio/clone cache identities. Existing unrelated caches, Danse and player maleargonian stay intact.
Already emitted game audio cannot be recalled: keep the game closed and drain jobs before import.

## Inworld retirement

Use `php tools/rotate-curated-inworld-voice.php --voice=npcmprestongarvey --connector=3
--old-sample=/absolute/data/voices/catalog-backups/npcmprestongarvey-TIMESTAMP/original.wav`
to inspect the exact old ID after import. It may create immutable local upload snapshots,
but does not mutate cloud state. Repeat with `--expected-old-id=EXACT_ID --apply` only
after inspection and with the game closed. Shared local samples/cache IDs are rejected.

Importing a catalog never deletes remote voices. First verify a replacement using its exact
sample hash and Inworld voice ID. Then resolve the old captured sample's workspace/credential-
scoped cache entry and managed metadata. Delete only that verified managed remote ID and its
matching cache/metadata entries. Never bulk-delete a workspace or delete a remote-only player
voice. The deleted remote ID is not recoverable; the retained WAV permits recreation, not
restoration of that ID. The 60-character provider-label fix must remain installed.

Current provider guidance describes a 5-60 second reference limit:
https://inworld.ai/resources/cross-lingual-voice-cloning
Older marketing still advertises 5-15 seconds; validate the selected complete recording against
the active API before retiring the old clone. Backend success is not likeness or gameplay proof.
