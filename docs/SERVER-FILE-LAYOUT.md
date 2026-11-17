# Synthserver target file layout

Synthserver started as a flat, Herika-style tree (`main.php`, `lib/`, `processor/`, `connector/`,
`service/`). It now follows the layout of **chevo**, the CHIM 4.0 server (`PLAN.md` §6, `AGENT.md`),
so features land in one predictable place. This document **supersedes** the earlier rule that
forbade `src/`, `config/`, `workers/` and `database/` roots: `config/` and `workers/` are now
target roots. `src/`, `public/`, `resources/` and `database/` stay unused (see
[Reserved and forbidden roots](#reserved-and-forbidden-roots)).

Existing code is **not moved by the structure PR**. Domains move one at a time in later PRs. Until a
domain moves, its code stays in the legacy location below and new behaviour is added in the target
location, called from the legacy code through a thin hook.

## Layering

Calls go downward only. A layer may call the layers beneath it and never the layers above it.

```text
api/  bin/  ui/  main.php   entry points: validate, dispatch, render
  └─ managers/<Name>/   workers/<Name>/    orchestration (workers are started from bin/)
       ├─ drivers/<kind>/                  providers: LLM, TTS, STT, ITT
       └─ core/entities/system             the only data types top-level code touches
            └─ core/dbmanager              the only code that touches PostgreSQL
                 └─ core/entities/base     mapped Abstract* classes
```

Rules:

1. **Only `core/dbmanager` talks to the database.** New code uses the system entity classes in
   `core/entities/system` (for example `Synth\Core\Entities\System\Profile`). It never calls
   `core/dbmanager/dao` and never refers to the mapped `Abstract*` classes in `core/entities/base`.
   When a system class lacks a method, add the method to the system class.
2. **Entry points orchestrate.** `api/`, `bin/` and `ui/` validate input, call a manager, and shape the
   output. They contain no business rules and no SQL.
3. **Managers own a domain.** A manager may call entities, other managers (through their public
   contract) and drivers. It never calls an `api/` route, a `ui/` page or a `bin/` script.
4. **Drivers know providers, not the game.** A driver turns a provider-neutral request into one
   provider's HTTP call and back. It never reads game state, settings or entities; the caller passes
   everything in.
5. **Workers do bounded work.** One run does a bounded amount of work and exits; progress comes from
   the data; the supervisor in `bin/` repeats it. Workers call managers and entities, never `api/`.
6. **`core/contracts`** holds interfaces and value types shared across layers. It calls nothing.
7. **Legacy code is frozen for new features.** See [Legacy locations](#legacy-locations).

## Target map

| Directory | Role | May call | Namespace | Legacy code lives in |
| --- | --- | --- | --- | --- |
| `api/` | HTTP router and versioned routes (`api/v2/*` today; new areas are `api/v<N>/<area>/*`). Validate, dispatch, shape the response. | `managers/`, `core/entities/system`, `core/contracts` | `Synth\Api\*` | Root entry points (`main.php`, `gamedata.php`, `stt.php`, `vsx.php`, `health.php`) and `api/v2/` |
| `managers/<Name>/` | One domain per PascalCase folder, each with a `README.md` (and `PLAN.md` for architectural work). Orchestration, policy, lifecycle. | `core/entities/system`, `core/contracts`, `drivers/`, other managers | `Synth\Managers\<Name>\*` | Flat files `managers/*.php` (`Synth\Managers\*`), `processor/`, `lib/synth_*.php` |
| `drivers/<kind>/` | `llm`, `tts`, `stt`, `itt`. Each has `<Kind>DriverInterface`, `<Kind>Wrapper` and `Drivers/` (one class per provider). | `core/contracts` only | `Synth\Drivers\<Kind>\*` (`Llm`, `Tts`, `Stt`, `Itt`) | `connector/` (LLM), `tts/`, `stt/`, `itt/`; the flat classes in `drivers/{llm,tts}/` |
| `core/entities/` | `base/`: mapped `Abstract*` classes. `system/`: the classes the rest of the code uses. | `core/dbmanager` (from `system` only) | `Synth\Core\Entities\Base\*`, `Synth\Core\Entities\System\*` | `core/entities/dialogue.php`, `lib/core/*.class.php`, raw `$db->` calls in `lib/`, `processor/`, `ui/` |
| `core/dbmanager/` | Doctrine mapping, DAOs (`dao/`), migrations, schema checks. The sole database boundary. | PostgreSQL, `core/entities/base` | `Synth\Core\DbManager\*`, `Synth\Core\DbManager\Dao\*`, `Synth\Migrations\*` | `repositories/` and `entities/` (current Doctrine pilot), `debug/db_updates.php` (frozen schema chain) |
| `core/contracts/` | Interfaces and value types shared across layers. | nothing | `Synth\Core\Contracts\*` | `core/contracts/dialogue.php`, `narrator.php` |
| `workers/<Name>/` | Background workers, one folder each, plus the worker interface and registry. | managers, `core/entities/system`, drivers | `Synth\Workers\<Name>\*` | `service/`, `processor/*_worker.php`, `service/processors/` |
| `bin/` | CLI entry points: `worker.php`, `worker-supervisor.php`, notify and maintenance scripts. Thin: parse arguments, call a worker or manager. | `workers/`, `managers/` | none (scripts) | `service/manager.php`, `service/start.sh` (deployment helpers stay in `tools/`) |
| `config/` | Configuration samples and loader. Never real credentials. | nothing | none | `conf/` (moves last; `conf/conf.php` stays ignored) |
| `extensions/` | **Reserved.** One folder per extension with an `extension.json`, once an ExtensionManager exists. No code yet. | extension hooks only | `Synth\Extensions\<Id>\*` | `ext/` |
| `ui/` | Management pages. They move onto `api/` and the entity layer as domains move. | `api/` contracts, managers, `core/entities/system` | none (pages) | `ui/` itself, with raw SQL until each page moves |
| `tests/` | **Target** for new PHPUnit tests (chevo layout). | anything | `Synth\Tests\*` | see [Tests](#tests) |
| `unittests/` | **Stays for now**: existing suites, fixtures, loopback harness and its own `vendor/`. | anything | none (legacy) | unchanged |
| `docs/`, `protocol/`, `tools/`, `data/`, `prompts/`, `functions/` | Unchanged owners: documentation, versioned wire contract, deployment helpers, factory data, prompt assembly, model action definitions. | n/a | n/a | n/a |

### Namespaces and PSR-4

`composer.json` maps the `Synth\` namespaces to the directories above. A class loaded through the
autoloader follows PSR-4: its file path below the mapped root equals its namespace below the prefix
(`Synth\Managers\Context\ContextManager` is `managers/Context/ContextManager.php`).

| Prefix | Directory |
| --- | --- |
| `Synth\Api\` | `api/` |
| `Synth\Core\Contracts\` | `core/contracts/` |
| `Synth\Core\Entities\` | `core/entities/` |
| `Synth\Core\DbManager\` | `core/dbmanager/` |
| `Synth\Migrations\` | `core/dbmanager/migrations/` |
| `Synth\Managers\` | `managers/` |
| `Synth\Drivers\Llm\`, `Tts\`, `Stt\`, `Itt\` | `drivers/llm/`, `drivers/tts/`, `drivers/stt/`, `drivers/itt/` |
| `Synth\Workers\` | `workers/` |
| `Synth\Tests\` (dev) | `tests/` |

Existing `require_once` lists keep working. A few existing classes (`Synth\Core\Doctrine\*`,
`Synth\Api\V2\*`, the `core/*/dialogue.php` files) do not match PSR-4 paths; they keep loading
through `require_once` and become autoloadable when their domain moves.

## Legacy locations

These are **frozen for new features: thin call-through hooks only.** A change in a legacy directory is
allowed when it is (a) a bug fix, (b) a small hook that calls new code in a target directory, or
(c) part of a domain-move PR. Do not add new classes, database access, providers or workers there.

| Legacy path | Target | Notes |
| --- | --- | --- |
| `main.php`, `main_synth_pipeline.php` and the other root PHP entry points | `api/` routes | Stay as the HTTP surface; add a hook that calls a manager. |
| `lib/` (`snake_case` helpers, `lib/core/*.class.php`, `lib/synth_*.php`) | `managers/<Name>/`, `core/entities/system` | About 1,200 `$db->` calls live here, in `processor/` and in `ui/`; they move with their domain. |
| `processor/` | `managers/<Name>/` | Request pipeline and TTS play/worker scripts. |
| `connector/` | `drivers/llm/` | LLM connectors and templates. |
| `tts/`, `stt/`, `itt/` | `drivers/tts/`, `drivers/stt/`, `drivers/itt/` | Provider adapters. |
| `service/` (`manager.php`, `*_worker.php`, `*_dispatch.php`, `processors/`) | `workers/<Name>/` and `bin/` | Background services. |
| `conf/` | `config/` | Moves last; deployment preserves `conf/conf.php`. |
| `ext/` | `extensions/` | Reserved target. |
| `debug/db_updates.php`, `lib/core/database_schema/` | `core/dbmanager/migrations/` | Already frozen: schema changes are Doctrine migrations only. |

A *thin hook* is a few lines: build the manager, call one method, return its result. The logic, the
tests and the documentation live in the target directory.

## Where new code goes

See the table in [AGENTS.md](../AGENTS.md#layering-and-placement) (mirrored in
[CONTRIBUTING.md](../CONTRIBUTING.md#layering-and-placement)). In short: a new feature gets a
`managers/<Name>/` folder with a `README.md`, reads and writes data only through
`core/entities/system`, calls providers only through `drivers/`, exposes HTTP through `api/`, and runs
background work as a `workers/<Name>/` class started from `bin/`. Code style:
[CODING-STANDARDS.md](coding-standards.md). Chevo status per area: [CHEVO-PARITY.md](CHEVO-PARITY.md).

## Tests

New PHPUnit tests go in `tests/` (namespace `Synth\Tests\`) once that suite exists. Until then, and
for everything that already has coverage, `unittests/` stays the working suite (own `composer.json`,
`phpunit.xml`, loopback harness and fixtures). Do not move existing tests in an ordinary PR. Run
PHPUnit only against a disposable PostgreSQL database.

## Reserved and forbidden roots

- `src/`, `public/`, `resources/` and `database/` remain forbidden: the matching responsibilities are
  `managers/`, the web root itself, `ui/` and `core/dbmanager/`.
- `config/` and `workers/` are allowed target roots; `extensions/` is reserved.
- `tools/audit-release-tree.ps1` enforces these roots.
- Do not name a target directory `cache`, `tmp`, `db`, `media`, `uploads`, `log` or `logs`: the WSL
  deploy excludes those names anywhere in the tree.

## Deliberate product differences

- `main_synth_pipeline.php`, `lib/synth_*.php`, Fallout catalogs and the SYNTH theme remain
  product-specific. Matching chevo's layout does not mean copying Skyrim behaviour.
- Versioned native contracts stay in `protocol/`, mirrored byte-for-byte in the SYNTH client repository.
- chevo is a layout and layering reference, not a code source: SYNTH keeps its own entities, tables
  and defaults. API keys stay on the shared Dwemer `api_keys` store rather than in server-private tables.

## Local deployment and persistent state

`tools/deploy-synthserver-wsl.ps1` copies the source layout directly to
`/var/www/html/Synthserver` in the WSL distribution selected by `-Distro`; it does not introduce a nested
`public/` web root. The installed health endpoint is
`http://127.0.0.1:8087/Synthserver/health.php`. The standalone development server
in `start-dev.ps1` separately uses port 8085.

The deploy runs `composer install --no-dev` in a staging area and syncs the untracked `vendor/`
(including the generated PSR-4 autoloader) into the target; `vendor/` is never committed. The source
rsync excludes and preserves `conf/conf.php`, `log/`, `logs/`, `soundcache/`, `cache/`, `media/`, `db/`,
`uploads/`, `data/pictures/gallery/`, `data/plugin_packages/` and `data/voices/`. Everything else,
including the new target roots, ships with the same rsync. Runtime-created folders are not evidence
of source-layout drift; do not move or delete persistent state for layout reasons.
