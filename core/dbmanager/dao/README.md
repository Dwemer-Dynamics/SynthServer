# DAO bridges (`core/dbmanager/dao`)

One `<Name>DAO` per entity family. Namespace `Synth\Core\DbManager\Dao`. A DAO receives the shared EntityManager once
through `configure()` and is listed in `Database::DAOS`; only the system classes call it. Locks, leases, claims and
append-only checkpoint writes stay parameterized DBAL inside the DAO. See
[docs/SCHEMA-REDESIGN.md](../../docs/SCHEMA-REDESIGN.md#4-entities-and-daos).
