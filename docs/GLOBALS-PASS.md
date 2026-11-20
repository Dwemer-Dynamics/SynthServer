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
