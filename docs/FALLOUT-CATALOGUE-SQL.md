# Fallout 4 catalogue SQL release

The authored Fallout 4 character, equipment and lore catalogue ships as
`data/fallout4_catalogue_seed.sql`, applied automatically by
`debug/db_updates.php` at `fallout4_catalogue_sql` version `20261009002`.
Fresh installs and existing databases use the same additive release.
The JSON and CSV authoring/upload files remain available; SQL is generated
from those reviewed sources rather than from a user's database.

`data/fallout4_catalogue_sql_manifest.json` records the SQL checksum, five
fixed source-file checksums, catalogue identity and source counts. The
installer validates these files and the character/lore schemas before
starting database writes. The release contains 204 character templates,
207 stable actor mappings, 201 equipment descriptions and 125 lore articles.
The SQL payload also contains the reference-only mapping schema upgrade.

## Existing data

Character templates, actor mappings and equipment descriptions use conflict
preservation. Existing standard edits and custom overlays keep precedence.
Six Vault 118 factory templates have one narrow correction: the old exclusive
`prewar` tag is removed only when all nine authored fields still exactly match
the known earlier factory row. Changes to tags, core text or any other authored
field prevent that repair; custom templates and encountered profiles are untouched.
Eighteen stock appearance refinements use the same all-nine-field match against
known earlier factory rows and update only appearance. Operator changes stop
these repairs. Reference-only bindings and additional physical-form aliases retain their
plugin-local identities. The migration never matches actors by name alone,
fills existing NPC profiles, changes voices or relationships, or merges
save-owned memories and histories.

Lore writes are scoped to the shipped factory catalogue version. Older
catalogue versions and every custom article remain intact. The shipped
version becomes active when no catalogue is active or the currently active
catalogue belongs to the same factory catalogue. An active catalogue with a
different catalogue ID remains selected. Effective custom articles retain
the application's existing precedence and access rules.

The release does not run the older broad factory cleanup or a general NPC
knowledge-tag reprojection. Existing historical migrations still precede this migration;
the new release does not replace their version records. Repeated application
does not overwrite character/item conflicts or churn unchanged lore rows.

## Equipment content

The description catalogue adds 118 fixed WEAP/ARMO identities, bringing its total
to 201. Only authored physical descriptions are seeded into `synth.descriptions`.
Special properties, origin, find locations and quest notes remain review-only.
The separate `fallout4_rare_equipment_review.json` and its 14-column review CSV
contain 43 named variants alongside fixed equipment. LVLI constructors are never
seeded as weapon/armor bases. A name or shared carrier identity cannot establish
the current instance's legendary mods.

Use the four-column common descriptions CSV for the existing descriptions uploader.
This selected catalogue is not a complete equipment census. Unresolved/cut records
are withheld. Proposed possession/familiarity recognition controls remain separate
development work; this release installs catalogue data and its identity mappings.
It does not add prompt, action, inventory or history projections.

## Transactions and verification

The installer requires an idle PostgreSQL connection and owns one transaction
for the SQL payload, source-key/count assertions and version recording.
The installer pins the original PostgreSQL backend and never reconnects after
BEGIN. A dropped connection or reset session aborts the import before it can
write outside that transaction. Statement and lock waits are bounded.
The payload contains no top-level transaction commands. Migration and
catalogue locks serialize application. An installed newer migration version
rejects this older release before its SQL can change catalogue selection.
Version recording supports older databases without a unique key on
`database_versioning`.

Pre-commit failures roll back the release and its version record together.
If the database does not acknowledge COMMIT, the installer reports an
uncertain outcome; it does not claim that rollback restored the database.
Inspect the catalogue and migration version before retrying that case.

Disposable PostgreSQL tests cover empty catalogue tables, populated upgrades,
custom and inactive content, foreign catalogue selection, stable reference
and alias routing, repeated application, malformed/tampered release files,
failed SQL, failed version recording, guarded appearance updates and the runtime
startup migration scheduling check, backend termination and same-handle reset. Automated source/database checks
do not establish Fallout 4/VR gameplay or voice-provider acceptance.

This follows the inherited versioned SQL seed convention while retaining
SYNTH's additive ownership rules. Keep the root MIT notice and the authored
content attribution/license files with the release.
