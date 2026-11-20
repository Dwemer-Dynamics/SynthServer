# Schema tools

Helpers for continuing the chevo-aligned schema work (see `docs/SCHEMA-REDESIGN.md` and `docs/SCHEMA-MIGRATIONS.md`).
All scripts use the repo root two levels above them, or `--root <dir>` to point at another checkout.

## genent.py: entities and read DAOs from the baseline manifest

```
python tools/schema/genent.py core_x,core_y
```

Reads `core/dbmanager/migrations/Version20261117000000.structure.txt` and writes, per table (primary key required):

- `core/entities/base/Abstract<Name>.php`: read-only ORM mapping.
- `core/entities/system/<Name>.php`: final class using `CopiesMappedEntity`.
- `core/dbmanager/dao/<Name>Dao.php`: `find()` and `findAll()` through `fresh()`.

Types mapped: int, text, varchar, bpchar, bool, jsonb, numeric, float, timestamp(tz), date, uuid. Vector, tsvector, bytea, array and point columns are skipped and named in the file header. Tables without a primary key are skipped (`NO PK`): give them a key in the baseline first.
Generation is idempotent: re-running on an existing table rewrites identical files.

## regdao.py: register the DAOs

```
python tools/schema/regdao.py Name1,Name2
```

Adds the `use` line and the `Database::DAOS` entry (class names, without `Dao`). Then run `EntityMappingTest` (every mapped column must exist in the live schema) and `DaoCoverageTest` (boots every registered DAO).

## rehash.py: refresh baseline hash pins

```
python tools/schema/rehash.py
php tools/schema-check.php --offline
```

After any edit to the baseline (`Version20261117000000.{php,fresh.sql,structure.txt}`), this rewrites `FRESH_INSTALL_SHA256` and the hashes and structure entry count in `schema-manifest.json`. `SchemaMigrationOwnerTest` also pins the baseline statement count (each statement ends with `-- end-statement`); update it when statements are added or removed.

## Notes

- Structure manifest lines are compared as a set, so order does not matter. Take exact lines for new tables from a live bootstrapped database rather than writing them by hand.
- Writes stay as parameterized DBAL single statements; the shared session driver refuses transactions and `lastInsertId`.
- Do not rename the protocol id `synth.speech.v1` or the log file `synth.log` when converting call sites.
- Only schema work through one owner at a time, because of the hash pins.
