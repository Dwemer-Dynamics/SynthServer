# Coding standards

Adapted from chevo's `coding-standards.txt`. They apply to **new and changed code in the target
directories** (`api/`, `managers/`, `drivers/`, `core/`, `workers/`, `bin/`, `tests/`). The layout and
layering rules are in [SERVER-FILE-LAYOUT.md](SERVER-FILE-LAYOUT.md); contribution rules are in
[CONTRIBUTING.md](../CONTRIBUTING.md).

## Principles

- **Say what a file is for.** Start every new source file with a short comment on its purpose.
- **Comment intent, not code.** Every method has at least one comment line saying why or what it
  guarantees; complex methods have at least two. Comments restate nothing the code already says.
- **One level of abstraction per method.** Keep high-level code readable for humans and free of
  implementation detail.
- **Readability first.** Descriptive names, straightforward flow, natural English.
- **Do not over-fragment.** Extract a method when it removes real duplication or clarifies the flow;
  a few repeated readable lines beat a chain of one-line helpers.
- **Respect existing conventions.** Follow the surrounding architecture, preserve behaviour, and avoid
  unrelated edits.

## PHP

- PascalCase classes, camelCase methods and variables, UPPER_SNAKE_CASE constants.
- Four spaces, no tabs. PSR-12 formatting; opening braces for classes and methods on the same line.
- Declare visibility explicitly; prefer `private`.
- Type parameters and returns wherever practical. `declare(strict_types=1);` in every new file.
- Namespaces and PSR-4 autoloading; avoid procedural code in new files.
- No `function_exists()` / `class_exists()` guards as a substitute for proper structure.
- Entry points orchestrate and delegate.

## Deviations for SYNTH

| Topic | chevo | SYNTH |
| --- | --- | --- |
| Existing `snake_case` code in `lib/`, `processor/`, `connector/`, `service/`, `ui/` | n/a | Exempt. Do not rename or reformat it. |
| Comment quota | Every method needs a comment | Required for **new** code in target directories. Untouched legacy code is not re-commented, and [CONTRIBUTING.md](../CONTRIBUTING.md#code-and-comments) still applies to legacy edits. |
| Wording | Avoid the words "contracts", "consumers", "producers" | `core/contracts/` is a directory name here; keep it, but prefer plain words in comments. |
| Database | Only `core/dbmanager` | Same for new code. Legacy `$db->` calls stay until their domain moves. |
| Tests | `tests/` | `unittests/` stays for existing suites; new tests go in `tests/` once it exists. |
| Formatter | none | none. Match the file you edit. |

## Golden rule

Code must not only work; it must clearly communicate its purpose and flow. Prefer readability,
simplicity and maintainability over fewer lines or more abstraction.
