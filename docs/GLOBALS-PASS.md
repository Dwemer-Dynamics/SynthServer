# Globals pass

Routes reads of the legacy runtime globals through `Synth\Bootstrap` accessors, one directory group per slice. Behaviour is unchanged: each accessor reads exactly what `$GLOBALS[...] ?? null` did (legacy files and tests still assign those globals), so the accessors are a seam for moving the state later.

| Global | Accessor |
| --- | --- |
| `$GLOBALS['db']`, `global $db;` | `Bootstrap::db()` |
| `$GLOBALS['ENGINE_PATH']` | `Bootstrap::enginePath()` |
| `$GLOBALS['ENGINE_ROOT']` | `Bootstrap::engineRoot()` |
| `$GLOBALS['SYNTH_REQUEST_EVENT']` | `Bootstrap::requestEvent()` |
| `$GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']` | `Bootstrap::claimedRequest()` |
| `$GLOBALS['gameRequest']` | `Bootstrap::gameRequest()` |
| any other configuration global | `Bootstrap::config($name, $default)` (not converted yet) |

The logger needs no accessor: it is the static `Synth\Lib\Logger` behind the `Logger` shim, not a global.

A token-based script (PHP tokenizer, not regex) rewrites only reads in code. It leaves assignments, `isset`/`empty`/`unset`, by-reference calls, references and string interpolation alone, so those uses stay as globals for the owner of that code.

Counts are non-test PHP (`global $x` plus `$GLOBALS` tokens, files that contain one, `require_once` tokens), measured with `git grep` over `*.php` excluding `tests/`, `unittests/` and `vendor/`.

Baseline (`origin/unstable` 71d3eb8): 6378 global uses in 303 files, 1836 `require_once`.

`require_once` is not the target of this pass: the remaining ones load legacy function files (not PSR-4 classes), and removing them needs those files turned into classes first.

## Slices

(One section per slice follows, with before/after counts.)

### G1-drivers

Reads in drivers/ now go through the accessors.

Files under: drivers

| | before | after |
| --- | --- | --- |
| global uses (all non-test PHP) | 6378 | 5649 |
| files with a global | 303 | 301 |
| `require_once` | 1836 | 1836 |

### G2-managers

Reads in managers/ now go through the accessors.

Files under: managers

| | before | after |
| --- | --- | --- |
| global uses (all non-test PHP) | 5649 | 4754 |
| files with a global | 301 | 281 |
| `require_once` | 1836 | 1836 |

### G3-workers-service-processor

Reads in workers/, service/ and processor/ now go through the accessors.

Files under: workers service processor

| | before | after |
| --- | --- | --- |
| global uses (all non-test PHP) | 4754 | 4432 |
| files with a global | 281 | 276 |
| `require_once` | 1836 | 1836 |

### G4-core-lib-and-plugin-dirs

Reads in core/, lib/, functions/, prompts/, connector/, stt/, tts/, itt/, debug/, bin/ and tools/ and the top-level scripts now go through the accessors.

Files under: core lib functions prompts connector stt tts itt debug bin tools bootstrap.php csv_import.php diary_status.php gamedata.php health.php index.php itt.php main.php main_synth_pipeline.php media.php player_rewrite.php prompt.includes.php router.php stt.php tts_status.php vsx.php

| | before | after |
| --- | --- | --- |
| global uses (all non-test PHP) | 4432 | 3697 |
| files with a global | 276 | 263 |
| `require_once` | 1836 | 1836 |

### G5-ui-api

Reads in ui/ and api/ now go through the accessors.

Files under: ui api

| | before | after |
| --- | --- | --- |
| global uses (all non-test PHP) | 3697 | 3419 |
| files with a global | 263 | 249 |
| `require_once` | 1836 | 1836 |

## Writers pass (W-series)

Second pass: writers, `isset`/`empty`/`unset` and string interpolation move behind `Synth\Bootstrap` too. State is still `$GLOBALS` (legacy files and tests assign it), so each call reads or writes exactly what the global did; the setters are the seam for moving the state into a request-context object name by name.

| Legacy form | Replacement |
| --- | --- |
| `$GLOBALS['N'] = expr;` | `\Synth\Bootstrap::set('N', expr);` |
| `$GLOBALS['N'] .= expr;` (and other compound operators) | `set('N', config('N') . (expr))` |
| `isset($GLOBALS['N'])` | `\Synth\Bootstrap::has('N')` (exists and not null) |
| `isset($GLOBALS['N']['k'])`, `empty($GLOBALS['N'])` | `isset(config('N')['k'])`, `empty(config('N'))` |
| `unset($GLOBALS['N'])` | `\Synth\Bootstrap::forget('N')` |
| `"... {$GLOBALS['N']} ..."` | `("... " . config('N') . " ...")` (a lone value becomes `(string)config('N')`) |

Tools (token based, PHP `PhpToken`, no regex rewriting):

- `php tools/globals-inventory.php <dir|file>...` lists remaining uses per name and kind.
- `php tools/globals-convert.php [--names=A,B] [--dry] <dir|file>...` rewrites the forms above. It skips and lists (set `CONV_MANUAL=<file>` to write the list) anything it cannot prove safe: writes with array dimensions, writes that are not a statement, by-reference uses (`sort`, `preg_match`, `array_push`, `foreach ... as &`, `&$GLOBALS[...]`), multi-argument `isset`/`unset`, and heredocs. Never run it on `bootstrap.php`.
- Dynamic keys (`$GLOBALS[$name]`) and `global $x;` are never converted.

Retiring a name is three steps: all writers use `set`/`forget`, all readers use `config`/`has`, then the name moves into the context object and `config`/`set` stop touching `$GLOBALS` for it. The importers that write dynamic names (`synthRuntimeImportConfigVariables`, the settings loaders) must move first, because they create most names.

### W1-prompts

All uses in `prompts/` (two commented-out dead blocks removed, one dead heredoc comment removed). Counts are repository-wide.

Files under: prompts

| | before | after |
| --- | --- | --- |
| global uses (all non-test PHP) | 3360 | 3144 |
| `require_once` | 1838 | 1838 |

### W2-connector

All uses in `connector/` (the LLM prompt templates and `__jpd.php`). One-level array writes use `Bootstrap::append('N', $v)` for `$GLOBALS['N'][] = $v` and `Bootstrap::setAt('N', $k, $v)` for `$GLOBALS['N'][$k] = $v`. Dead code inside comments keeps its meaning but names `\Synth\Bootstrap::config('N')` (`tools/globals-comments.php`), so the count reaches zero.

Stacked on W1-prompts.

Files under: connector

| | before | after |
| --- | --- | --- |
| global uses (all non-test PHP) | 3144 | 2926 |
| `require_once` | 1838 | 1838 |

### W3-functions

All uses in `functions/` except two string literals. New `Bootstrap` helpers cover the remaining shapes:

| Legacy form | Replacement |
| --- | --- |
| `$GLOBALS['N'][$a][$b] = $v;` | `Bootstrap::setPath('N', [$a, $b], $v)` |
| `$GLOBALS['N'][$a][] = $v;` | `Bootstrap::appendPath('N', [$a], $v)` |
| `unset($GLOBALS['N'][$a])` | `Bootstrap::unsetPath('N', [$a])` |
| `global $x;` | `$x = &Bootstrap::ref('x');` (same reference to the same slot) |
| `shuffle($GLOBALS['N'])`, `&$GLOBALS['N']` | `shuffle(Bootstrap::ref('N'))`, `= &Bootstrap::ref('N')` |

`ref()` returns by reference, so by-reference arguments, `foreach (... as &$v)` sources and `global`-style aliases keep their semantics. The converter can leave nested uses inside a rewritten write expression; run it until it reports no changes.

Exceptions: `functions/functions.php` lines naming the literal template placeholders `'{$GLOBALS["SYNTH_NAME"]}'` and `'{$GLOBALS["PLAYER_NAME"]}'`. They are replacement keys matched against prompt text, not accesses.

Stacked on W2-connector.

Files under: functions

| | before | after |
| --- | --- | --- |
| global uses (all non-test PHP) | 2926 | 2773 |
| `require_once` | 1838 | 1838 |

### W4-service

All uses in `service/`, including `new $GLOBALS['CURRENT_CONNECTOR']` (now `new (Bootstrap::config('CURRENT_CONNECTOR'))`), the dynamic-key read in `service/lib/core_utils.php` (`config($v)`) and the rolemaster task closure (`setPath('TASKS', ['rolemaster', 'fn'], function ...)`). Core (`core/`, except `core/dbmanager`) has no global uses.

Stacked on W3-functions.

Files under: service

| | before | after |
| --- | --- | --- |
| global uses (all non-test PHP) | 2773 | 2660 |
| `require_once` | 1838 | 1838 |

### W5-root-scripts

All uses in the root scripts (`main_synth_pipeline.php`, `player_rewrite.php`, `prompt.includes.php` and the small entry scripts). New helper `Bootstrap::bind($name, &$source)` replaces `$GLOBALS['gameRequest'] = &$gameRequest;`. Multi-argument `unset` splits into one `forget()` per name. The converter now reports `unset`/`isset` arguments it cannot rewrite instead of turning them into reads.

Stacked on W4-service.

Files under: root scripts

| | before | after |
| --- | --- | --- |
| global uses (all non-test PHP) | 2660 | 2349 |
| `require_once` | 1838 | 1838 |

### W6-drivers

All uses in `drivers/` (LLM and TTS drivers). New helper `Bootstrap::coalescePath($name, $path, $default)` replaces `$GLOBALS['N'][...][...] ??= $default` used as an expression (the Zonos Gradio defaults). Multi-argument `unset($GLOBALS['A'], $GLOBALS['B'])` was split by hand into `forget()` calls; the converter now reports it instead of guessing.

Stacked on W5-root-scripts (#173).

Files under: drivers

| | before | after |
| --- | --- | --- |
| global uses (all non-test PHP) | 2349 | 1740 |
| `require_once` | 1838 | 1838 |

### W7-workers

All uses in `workers/` except one literal placeholder: `workers/Profiles/Native/dynamic_update_util.php` has `'{$GLOBALS["SYNTH_NAME"]}'` replacement keys, which are text, not accesses. Dynamic keys (`$GLOBALS[$key]`) were converted by hand to `Bootstrap::set($key, ...)`, `forget($key)` and `config($key)`; the middle-term task closure uses `setPath('TASKS', ['middleterm', 'fn'], function ...)`. Whole-array uses of `$GLOBALS` (passed to resolvers, `array_intersect_key($GLOBALS, ...)`) are not counted by the `$GLOBALS[` method and still need an `all()` accessor.

Stacked on W6-drivers (#176).

Files under: workers

| | before | after |
| --- | --- | --- |
| global uses (all non-test PHP) | 1740 | 1560 |
| `require_once` | 1838 | 1838 |

### W8-processor

All uses in `processor/` except one literal placeholder (`processor/request.php`, the `'{$GLOBALS["SYNTH_NAME"]}'` replacement keys). The three `$GLOBALS['gameRequest'] = &$x` bindings use `Bootstrap::bind()`.

Stacked on W7-workers (#179).

Files under: processor

| | before | after |
| --- | --- | --- |
| global uses (all non-test PHP) | 1560 | 1380 |
| `require_once` | 1838 | 1838 |

### W9-ui-api

All uses in `ui/` and `api/` except two literal placeholders in `ui/function_editor.php` (`'{$GLOBALS["PLAYER_NAME"]}'`, `'{$GLOBALS["SYNTH_NAME"]}'` as text to replace). New helper `Bootstrap::exists($name)` replaces `array_key_exists($name, $GLOBALS)` (the API v2 dispatch save/restore). 12 `global $x;` statements became `ref()` bindings; the converter now also processes files that only contain `global` statements (it used to skip files without `$GLOBALS`).

Stacked on W8-processor (#180).

Files under: ui api

| | before | after |
| --- | --- | --- |
| global uses (all non-test PHP) | 1380 | 1071 |
| `require_once` | 1838 | 1838 |

### W10-lib-and-small-dirs

All uses in `lib/`, `stt/`, `tts/`, `debug/`, `tools/` and `conf/`, including the importers that create most runtime names: `lib/runtime_bootstrap.php` (`synthRuntimeImportConfigVariables` and its option importer), `lib/settings.php` (flat and nested settings writers, `$cursor =& Bootstrap::ref($rootKey)`), `lib/db_connection_settings.php`, `conf/conf_loader.php` and `tools/schema-check.php`. They now write through `Bootstrap::set($name, $value)`, so once the other writers have moved, `Bootstrap` can switch its storage for every name in one place.

Exceptions: `lib/classes/Logger.php` keeps its two `$GLOBALS['SYNTH_REQUEST_ID']` uses, because the logger is required standalone (before any autoloader) and must not depend on `Bootstrap`. `tools/globals-comments.php` and `tools/globals-convert.php` mention the tokens in their own comments (they are the converter).

Stacked on W9-ui-api (#181).

Files under: lib stt tts debug tools conf

| | before | after |
| --- | --- | --- |
| global uses (all non-test PHP) | about 1071 | 938 |
| `require_once` | 1838 | 1838 |

### W11-managers

All uses in `managers/` except two literal placeholder lines in `managers/MemoryManager/Native/synth_diary_state.php` (`'{$GLOBALS["SYNTH_NAME"]}'` / `PLAYER_NAME` as replacement keys). Dynamic simple keys were converted with a scripted pass (`$GLOBALS[$k]` to `config/has/exists/set/forget($k)`), five `$GLOBALS['gameRequest'] = &$gameRequest` bindings use `bind()`, and the few non-statement shapes (`++`, assignment used as a return value, multi-argument `isset`/`unset`) were rewritten by hand.

Stacked on W10-lib (#184).

Files under: managers

| | before | after |
| --- | --- | --- |
| global uses (all non-test PHP) | 938 | 269 |
| `require_once` | 1838 | 1838 |

### W12-dbmanager

All uses in `core/dbmanager/` (the legacy connector, narrator, player and profile classes). `unset` of two nested keys uses `unsetPath`; the two "database connection not initialized" exception messages now name `Bootstrap::db()` instead of `$GLOBALS['db']`.

Exception list for the whole pass (what the count still contains, measured with the CHEVO-PARITY method):

- `bootstrap.php`: `Synth\Bootstrap` is the store; its own `$GLOBALS` reads and writes are the implementation (47).
- `lib/classes/Logger.php`: two `$GLOBALS['SYNTH_REQUEST_ID']` uses; the logger is required standalone before any autoloader.
- `tools/globals-convert.php`, `tools/globals-comments.php`: mention the tokens in comments.
- Literal replacement keys `'{$GLOBALS["SYNTH_NAME"]}'` / `PLAYER_NAME` in `functions/functions.php`, `ui/function_editor.php`, `workers/Profiles/Native/dynamic_update_util.php`, `processor/request.php` and `managers/MemoryManager/Native/synth_diary_state.php`.

Not counted by the method: whole-array uses of `$GLOBALS` (no subscript), such as `array_intersect_key($GLOBALS, ...)`.

Files under: core/dbmanager

| | before | after |
| --- | --- | --- |
| global uses (all non-test PHP) | 269 | 64 |
| `require_once` | 1864 | 1864 |

## Globals exception list (final)

The justified remainder of the globals pass (64 uses with the CHEVO-PARITY count method):

- `bootstrap.php` (47): `Synth\Bootstrap` is the store; its own `$GLOBALS` access is the implementation.
- `lib/classes/Logger.php` (2): the logger is required standalone before any autoloader and must not depend on `Bootstrap`.
- `tools/globals-convert.php`, `tools/globals-comments.php`: the converter mentions the tokens in its comments.
- Literal replacement keys `'{$GLOBALS["SYNTH_NAME"]}'` / `PLAYER_NAME` (text to substitute, not accesses) in `functions/functions.php`, `ui/function_editor.php`, `workers/Profiles/Native/dynamic_update_util.php`, `processor/request.php` and `managers/MemoryManager/Native/synth_diary_state.php`.
- Not counted by the method: whole-array uses of `$GLOBALS` (no subscript), for example `array_intersect_key($GLOBALS, ...)`.

## require_once pass (R-series)

Method: files that declare only classes or guarded functions and have no load-time side effects move to the Composer autoloader, and the `require_once` lines that loaded them are deleted by `tools/require-prune.php` (token based; it only touches whole statements and lists the rest). The deploy already runs `composer install --no-dev` (optimized autoloader), and the test sandbox runs `composer dump-autoload`, so `composer.json` autoload changes take effect without extra steps.

| Autoloaded by | Files |
| --- | --- |
| `classmap` `core/dbmanager/Legacy/` | `ApiBadge`, `CoreProfile`, `ImportRules`, `ITTConnector`, `LLMConnector`, `Narrator`, `NpcMaster`, `Player`, `STTConnector`, `TTSConnector` (global-namespace legacy classes). The `lib/core/*.class.php` hooks stay as one-line shims; requiring them still works, it is just no longer needed. |
| PSR-4 `Synth\Lib\` => `lib/classes/` | `Synth\Lib\Logger` |

`lib/logger.php` (the global `Logger` shim) is not autoloaded: it installs the error handlers when it is included, so it stays an explicit `require_once`. `lib/runtime_bootstrap.php` and `lib/autoload.php` are entry-point loaders and stay.

In entry scripts that loaded `lib/classes/Logger.php` first, that line becomes `lib/autoload.php` so the autoloader is available from the first line; where the file already loads the autoloader the line is deleted.

### R1-ui

Directory: `ui/`. Deleted 74 legacy-class `require_once` lines, replaced 13 `Logger.php` lines by `autoload.php`.

| | before | after |
| --- | --- | --- |
| `require_once` (all non-test PHP) | 1864 | 1790 |
| global uses | 64 | 64 |

### R2-root-scripts

Root scripts (`main_synth_pipeline.php`, `gamedata.php`, `itt.php`, `player_rewrite.php`, `prompt.includes.php`, `vsx.php`): deleted 27 class-file `require_once` lines, replaced 4 `Logger.php` lines by `autoload.php`. Stacked on R1-ui (#188).

| | before | after |
| --- | --- | --- |
| `require_once` (all non-test PHP) | 1790 | 1768 |
| global uses | 64 | 64 |

### R3-drivers

Directory: drivers. `tools/require-prune.php` deleted 13 class-file `require_once` lines and replaced 6 `Logger.php` lines by `autoload.php`. Stacked on R2 (#190).

| | before | after |
| --- | --- | --- |
| `require_once` (all non-test PHP) | 1768 | 1755 |
| global uses | 64 | 64 |

### R4-workers

Directory: workers. `tools/require-prune.php` deleted 25 class-file `require_once` lines and replaced 2 `Logger.php` lines by `autoload.php`. Stacked on R3 (#191).

| | before | after |
| --- | --- | --- |
| `require_once` (all non-test PHP) | 1755 | 1730 |
| global uses | 64 | 64 |

### R5-processor-service-prompts-functions-core

Directory: processor, service, prompts, functions, core (small, grouped in one PR: 12+7+0+0+6 deleted). `tools/require-prune.php` deleted 25 class-file `require_once` lines and replaced 10 `Logger.php` lines by `autoload.php`. Stacked on R4 (#192).

| | before | after |
| --- | --- | --- |
| `require_once` (all non-test PHP) | 1730 | 1705 |
| global uses | 64 | 64 |

### R6-managers-lib-and-rest

Directory: managers, lib, api, stt, debug, tools, bin (the `lib/core/*.class.php` shims are left untouched; `llm_connector`, `narrator` and `npc_master` requires are kept in this PR because those files also define functions callers use without the class). `tools/require-prune.php` deleted 32 class-file `require_once` lines and replaced 20 `Logger.php` lines by `autoload.php`. Stacked on R5 (#194).

| | before | after |
| --- | --- | --- |
| `require_once` (all non-test PHP) | 1705 | 1678 |
| global uses | 64 | 64 |

### R7-legacy-core-functions

Directory: lib/legacy_core_functions.php (new, Composer files entry). `tools/require-prune.php` deleted 0 class-file `require_once` lines and replaced 0 `Logger.php` lines by `autoload.php`. Moves 17 guarded functions out of the llm_connector, narrator and npc_master class files; stacked on R6 (#195).

| | before | after |
| --- | --- | --- |
| `require_once` (all non-test PHP) | 1678 | 1678 |
| global uses | 64 | 64 |
