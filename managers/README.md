# Managers

One domain per folder: `managers/<Name>/` (PascalCase), namespace `Synth\Managers\<Name>\*`, with its
own `README.md` (contract) and, for architectural work, a `PLAN.md`. Full layout:
[docs/SERVER-FILE-LAYOUT.md](../docs/SERVER-FILE-LAYOUT.md).

## What belongs here

Orchestration, policy and lifecycle for one domain, called from `api/`, `bin/`, `ui/` and `workers/`.
A manager reads and writes data only through `core/entities/system` classes, calls providers only
through `drivers/`, and may call another manager through its public interface. It never calls an
`api/` route, a `ui/` page or a `bin/` script, and contains no SQL.

## Legacy flat files

`DialogueManager.php`, `ReceiptManager.php`, `SessionManager.php`, `SpeechManager.php` and
`WorldKnowledgeManager.php` (namespace `Synth\Managers\*`) stay where they are until their domain moves into a
`managers/<Name>/` folder. Do not add new flat files; add a folder.

`CharacterManager.php`, `ContextManager.php`, `PrivateNarratorManager.php`, `PromptManager.php` and `SaveState.php` are
not implementations any more: each is a two-line hook (`require_once` plus `class_alias`) so the old class names
(`Synth\Managers\SaveState`, ...) keep resolving for `bootstrap.php` and other legacy callers. New code uses the
folder namespaces (`Synth\Managers\SaveState\SaveState`, ...); a hook is deleted once its last caller moves.

## Adding a manager

1. Create `managers/<Name>/` with the interface, the implementation and a `README.md` (what it owns,
   public methods, what it refuses).
2. Use `declare(strict_types=1)` and the PSR-4 namespace; no `require_once` for new classes.
3. Add a row to [docs/CHEVO-PARITY.md](../docs/CHEVO-PARITY.md).
