# DAO bridges (`core/dbmanager/dao`)

One `<Name>DAO` per entity family. Namespace `Synth\Core\DbManager\Dao`. A DAO receives the shared EntityManager once
through `configure()` and is listed in `Database::DAOS`; only the system classes call it. Locks, leases, claims and
append-only checkpoint writes stay parameterized DBAL inside the DAO. See
[docs/SCHEMA-REDESIGN.md](../../docs/SCHEMA-REDESIGN.md#4-entities-and-daos).

## Reference vertical: `core_config_setting`

`ConfigSettingDao` (reads by scope and key, `using()` for a caller that owns its session, `shared()` after `Database::boot()`),
`core/entities/base/AbstractConfigSetting.php` (read-only mapping) and `core/entities/system/ConfigSetting.php` (the untracked
copy callers hold) show the shape every later entity follows. `core/dbmanager/repositories/SettingsRepository.php` is a thin
session binder over the DAO and goes away when its callers use `Database::boot()`.
