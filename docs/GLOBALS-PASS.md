# Globals pass

Routes reads of the legacy runtime globals through `Synth\Bootstrap` accessors, one directory group per slice. Behaviour is unchanged: each accessor reads the same legacy global first (legacy files and tests still assign it) and falls back to the runtime instance.

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
