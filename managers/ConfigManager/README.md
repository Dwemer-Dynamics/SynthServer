# ConfigManager

The one door to stored settings (`public.core_config_setting`), built on `ConfigSettingDao` (`core/dbmanager/dao`). Design:
[docs/CONFIG-SCOPES.md](../../docs/CONFIG-SCOPES.md).

| Class | Role |
|---|---|
| `ConfigManager` | Reads and writes the five storage scopes (`general`, `conf`, `player`, `narrator`, `meta`); validates writes to declared general settings; explains where an effective value comes from. |
| `ConfigFile` | Reads the optional `config/config.inc.php` (template `config/config.inc.tmpl.php`) and names its values as the legacy `SYNTH_DB_*` variables. |

```php
$config = ConfigManager::shared();                 // booted DAO, else the legacy `Bootstrap::db()` session
$config->get('conf', 'synth_mode', 'STANDARD');    // raw text of any scope
$config->set('player', 'tts_connector_id', 3);     // any scope; booleans and arrays are stored as text
$config->setEditable('CONTEXT_HISTORY', '40');     // declared general setting only; throws InvalidArgumentException
$config->explain('CONTEXT_HISTORY', $profile, $npc); // value + source: npc | profile | global | default
```

- `definitions()` come from `conf/conf_schema.json` (secrets, `SYNTH_DB_*` and `DBDRIVER` excluded). A setting is global-only
  when it is in `synthGetGlobalOnlySettingIds()` or its schema says `scope: global`.
- An override counts by presence (`false`, `0` and `''` are real values); `null` means inherit.
- `lib/settings.php` (`synthGetGeneralSetting*`, `synthSetGeneralSetting`) are thin hooks over this class; new code calls the
  manager.
- `/api/v1/edit/config` is the HTTP face: see `api/v1/editing/index.php`.

Not built yet (from the design): the dotted-key registry, `getForCharacter()`, `applyToGlobals()`, `$expected` stale-write tokens.
