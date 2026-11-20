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
