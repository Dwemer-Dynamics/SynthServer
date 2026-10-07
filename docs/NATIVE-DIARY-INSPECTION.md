# Native diary inspection API

`GET ui/api/native_diary.php` is a local management API for accepted, save-visible native
journals. It does not generate diaries, call providers, alter game-request globals, or edit
legacy `diarylog` rows. Keep the server on its existing loopback deployment.

| Operation | Required query fields | Optional cursor |
| --- | --- | --- |
| `scopes` (default) | None | All of `before_session`, `before_generation`, `before_sequence` |
| `authors` | `session_id`, `generation`, `context_sequence`, `role` | `before_actor_id` |
| `list` | `session_id`, `generation`, `context_sequence`, `actor_identity_id`, `role` | `before_id` |
| `export` | The list fields | `before_id` |
| `detail` | The list fields plus `diary_id` | None |

`role` is exactly `npc`, `narrator`, or `player`. Player and Narrator journals both use the
snapshot's Player identity but remain separate roles. NPCs can be absent from the selected
snapshot; their exact native identity must belong to the same playthrough. Names and caller
supplied database/runtime namespaces are not accepted.

`authors`, `list`, `export`, and `detail` accept optional `visibility=active|hidden|all`. The default
`active` preserves recall-only browsing. `hidden` includes archived journals, descendants
hidden by an earlier journal, and entries with unavailable control integrity; `all` includes
both sets. It never bypasses saved ancestry or original journal integrity. Author counts and
their recorded names follow the selected filter. The `scopes` operation rejects this field.

Scopes return at most 30 rows and a `next_cursor`. Author pages return at most 30 rows and
`next_before_actor_id`, descending by native identity. Only authors with validated accepted
journals visible to the selected snapshot are included, even when absent nearby. Each row
contains `actor_identity_id`, `full_form_id`, `origin_plugin`, `journal_count`,
`latest_diary_id`, and `recorded_name`. Names come from the latest visible validated journal's
frozen input, not newer profile/registry state; the Narrator label is always `The Narrator`.
Names are labels, never identity keys. Player and Narrator author lists remain separate.
Journal lists return at most 20 rows and
`next_before_id`. IDs are decimal strings. Lists expose topic, a 240-character preview, prior
journal ID, reason, publication/game time and a validated embedding-availability flag. Detail
returns content instead of a preview. Both include decimal `control_revision` and boolean
`archived`, `recall_blocked`, `control_unavailable`, `dependency_stale` fields and decimal-string
`content_revision` (`0` for original text). Neither response includes frozen prompts, provider
configuration, vectors, or raw source-event text.

Every journal passes the same saved ancestry, original-reader source visibility, profile and
prior-chain integrity checks as recall. Inactive snapshots are annotated with an availability
reason and return no journals. A missing snapshot, mismatched actor/role, or non-visible detail
returns 404. Invalid keys return 400, non-GET requests 405, and queries over 2048 bytes 413.
Database failures return a redacted 503. Responses are JSON with `ok`, use `no-store`, and have
no cross-origin access enabled. Reads run in a bounded, repeatable-read, read-only transaction.

The `ui/native-diaries.php` management page is linked from Memories & Records and the legacy diary
page. It supports snapshot and journal paging, explicit NPC/Player/Narrator roles, full journal
details, and inactive/empty/error states. The saved-author picker includes absent NPCs and pages
independently from journals. It prefers the snapshot target when present on that page, otherwise
the first saved author. Manual exact-identity lookup is available inside the author help panel.
This is a saved-journal directory, not a complete game actor registry. Changing scope or
author or visibility filter discards old content and invalidates pending reads; journal text is rendered literally.

Historical embedding backfill remains unfinished management work.

## Native export and printable book

The frozen DialecticServer baseline provides a diary text export (`ui/index.php?export=diary`)
and a per-person printable book (`ui/diary_book.php`). Native SYNTH exports adapt those reading
features to canonical actor/role/playthrough and an exact saved snapshot; the legacy global,
name-keyed exporter is not reused for native history.

`GET ui/api/native_diary.php?operation=export` accepts exactly the list scope/filter/cursor.
It returns the same metadata and pagination contract, but full `content` instead of `preview`.
Each page contains at most 20 journals, descending by decimal-string diary ID. Continue with
`next_before_id` while retaining every other scope key. A null cursor is the only completion
signal. Unknown fields (including caller-selected limits, formats or database namespaces)
are rejected. Inactive scopes return their availability reason with no entries. No game
request, provider or durable job is created. Source/control integrity and corrected-content
visibility are identical to ordinary inspection. No prompts, source events, credentials,
provider settings or vectors are exported.

On the management page, **Download journals (.txt)** and **Download printable book (.html)**
collect every page for the currently selected author, role, snapshot and visibility, regardless
of the currently displayed journal page. They do not include other authors or earlier original
versions replaced in this snapshot. Hidden/all exports are explicit and label recall state.
Plain text preserves full multiline content, newest first. The book reads oldest first in
accepted journal order. Its author name is a saved directory label, never an identity key.
The standalone HTML book escapes all content,
contains no scripts or external resources, and includes print styling. Open it locally and use
the browser's Print command to save a PDF. It is a reading export, not a database/save backup.

The browser checks exact scope, strict descending IDs and cursor progress before downloading.
Cancellation, navigation, errors and a 60-second deadline discard the partial collection.
An empty result produces no file. Browser exports stop at 10,000 entries or 64 MiB of collected
JSON/formatted output; larger exports require the same paginated API, not a silent truncation.
The downloadable file contains private journal text. Filenames use only fixed labels and
validated role/identity/generation/snapshot keys. Reading/exporting never archives or edits data.

## Save-scoped journal controls

`ui/api/native_diary_control.php` provides administrative control state and confirmed writes.
GET requires the exact detail scope above (without `operation`): `session_id`, `generation`,
`context_sequence`, `actor_identity_id`, `role`, `diary_id`. It returns `control_revision`,
`content_revision`, effective `topic`/`content`, `dependency_stale`,
`archived`, `recall_blocked`, `control_unavailable`, `unsaved_change`, and a session-bound
`csrf_token`. It can resolve an archived journal by its exact ID without an inspection visibility filter.

POST requires JSON with the same keys plus `action` (`archive`, `restore`, or `edit`),
`expected_revision` (a decimal string, initially `0`), and that `csrf_token` with its session
cookie. Unknown fields/actions, stale revisions and pending unsaved controls are rejected.
Writes are limited to active saved scopes and the same canonical actor/playthrough/role.
The response's `availability=awaiting_new_snapshot` means the receipt is recorded but **does
not yet alter the selected immutable snapshot**. A later acknowledged context incorporates
it; reloading an earlier save does not inherit a later archive or restoration. `unchanged`
means the requested state was already present and no receipt was added.

An archive suppresses the journal and reflections that depend on it from latest-journal prompts,
lexical/semantic recall, and the inspector's default active view. It does not erase text, evidence,
embeddings or prior links. Restore recovers the chain unless another ancestor is still archived
or a prior-content dependency is stale.
New diaries can start independently without the hidden prior reflection. Automatic cooldowns
still use accepted publication history; archiving is not a cooldown reset. Corrupt control
receipts fail closed for the affected journal/descendants and are reported as unavailable.
The archive is save-scoped suppression, **not permanent erasure from all saves or backups**.

In the UI, choose **Hidden from recall** or **All saved journals** to find archived authors
and entries. **Manage journal** fetches fresh control state and identifies the exact journal,
actor, role and snapshot before offering **Confirm archive** or **Confirm restore**. Hidden
dependency and corrupt-control states are labeled separately. Pending or unavailable controls
cannot be confirmed. Cancel returns focus to the initiating button; reading never writes.
During a confirmed POST, navigation and repeat confirmation are disabled. Writes are never
automatically retried. A timeout/error requires refreshing and checking state; it is not proof
that the server cancelled the write. Success reports the pending new snapshot without removing
or changing rows from the selected immutable snapshot. No provider or game request is dispatched.

Migration `20260905028` adds append-only `synth_meta.diary_control_revisions` and admits only
the new derived `diary_control` history type without a game request. It is repeatable with
retained receipts. Do not drop the table or downgrade to a reader that ignores controls once
receipts exist: that would expose archived text again. Reversal uses a confirmed restore,
preserving the audit/evidence chain. No existing journal rows are rewritten by this migration.

### Content corrections

`action=edit` additionally requires `result` containing exactly string `topic` and `content`.
Both must be nonblank valid UTF-8 without null characters; their limits are 512 and 65,536
bytes respectively. The JSON body limit is 409,600 bytes to permit escaped valid text. Other
actions reject `result`. Only recall-available journals can be edited. Hidden/dependency-stale,
corrupt, pending, wrong-role and stale-revision edits are refused. Identical effective text is a no-op.

Corrections append a `synth.diary.control.v2` receipt. Original result text, hashes and vectors
are not overwritten. Earlier snapshots and forks retain their own effective version; a correction
appears only after a new acknowledged snapshot. Archive/restore preserves the latest saved
correction. Reverting text requires another confirmed correction, not deletion of receipts.

Latest-journal prompts, inspection and lexical recall use corrected text. Lexical recall reports
the edit's revision; an edited journal cannot use its original semantic vector. No replacement
embedding is generated by this control. New reflections freeze their prior journal's content
revision and text. Older descendants that no longer match are hidden as `dependency_stale`,
including their descendants. Archive/restore does not repair stale dependencies; newly generated
reflections may use the corrected prior. Automatic cooldown history is unchanged.

**Edit journal** reads fresh control text, then offers an explicit **Confirm correction**.
Client byte validation supplements server validation; no autosave or automatic retry is used.
Navigation discards an unconfirmed draft. The original selected snapshot is not optimistically
rewritten after confirmation. Pending/integrity/dependency guards are checked again at the server.

Migration `20260905029` admits v2 correction receipts alongside v1 archive/restore receipts and
can be replayed with either retained. Do not downgrade to a reader that ignores corrections or
remove the v2 constraint allowance once edits exist. That could fail migration or expose old text.
No game protocol or provider configuration changes are required.

## Embedding source binding

Migration `20260905030` adds nullable `embedding_text_sha256` to diary worker checkpoints
and accepted results. New non-null vectors record SHA-256 of the accepted UTF-8 `content`
before service-specific preprocessing. This is a source-text binding, not a model identifier
or proof that two embedding models are compatible. The existing embedding helper still trims
text before sending it to the configured service; no service/configuration change is made.

Semantic recall and `has_embedding` require both the vector-byte checksum and this source-text
checksum to match. Null or mismatched source provenance disables only the published vector;
valid journal text remains available to inspection, export and lexical recall. Content
corrections continue to exclude the original vector even if its original binding is valid.

Existing records are not given fabricated hashes, rewritten or deleted by migration. An old
worker checkpoint with valid text/vector checksums but unknown text provenance may recover its
accepted text without another provider call. The original checkpoint vector stays retained;
it is not attached to the new published journal. A correctly bound checkpoint recovers its
vector without provider repetition. A contradictory non-null checkpoint binding is refused
and retained for diagnosis rather than silently republished or overwritten.

Historical/failed vector backfill remains unimplemented. It must generate a new vector for the
exact selected content revision, retain original records, recheck actor/role/save visibility
and content revision at publication, and not rerun the journal LLM. Embedding model/version
identity and compatible query-vector selection remain separate open requirements. Do not
downgrade to an older reader that ignores source binding: it could reuse unproven vectors.
