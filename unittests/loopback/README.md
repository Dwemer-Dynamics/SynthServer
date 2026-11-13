# Loopback dialogue check (opt-in, off-game)

`run-loopback-dialogue.sh` builds the SYNTH client's test-only loopback driver
(`tests/loopback_dialogue/` in the client repository) and runs it against this server's real
`main.php`. It uses a disposable PostgreSQL cluster and a loopback fake provider. The driver runs
the real `SynthClient` and `SaveContextStore`: init, an explicit native /talk, one heard line
whose `audio_completed` receipt is sent twice, a checkpoint, then sessions loaded from the older
and the newer co-save. `LoopbackDialogueTest.php` checks that there is exactly one heard row and
one `spoken` utterance owned by the first receipt. The older load's provider prompt must not
contain the line, and the newer load's prompt must contain it exactly once. Each `/talk` makes
exactly one provider call, and all 13 client requests must complete.

It also checks that each `/talk` prompt uses the dynamic profile revision that its save makes
visible for the exact target identity:

- One revision is accepted before the older anchor. The first `/talk` and the older load use it.
- One is accepted while the first `/talk` is held at its provider, between the anchors. The
  provider reply is a FIFO, so the hold is deterministic. The newer load uses this revision.
- One is accepted after the newer anchor. No prompt uses it.
- A same-named sibling identity in the same save has no revision and never appears in a prompt.
  Base `core_npc_master` rows are never rewritten.
- For each prompt, the native repository's strict read and the ordinary route's projection must
  match the prompt.

Part of this coverage is simulated:

- The driver has no profile refresh, so the test sends its three `profile_refresh` events. Real
  `main.php` admits them into the client's session on a second loopback server instance. The
  run therefore completes 16 requests: 13 from the client and 3 from the test.
- The client does not declare `profiles.dynamic.refresh`. The test adds that capability to the
  session row for each refresh request, then checks that the exact declared set is restored. A
  test-owned `init` can't supply it, because an init must advance the client's generation.
- The HTTP admission must queue exactly one batch keyed `actor:<identity id>`. The test runs
  `service/manager.php dynamicprofile` inline in a fresh process with `pcntl_fork` disabled.
  That worker uses a loopback JSON provider and must drain the batch. The test never inserts a
  revision itself.

Not covered: a client-initiated refresh, a cancelled worker result, a sibling save branch that
has its own acknowledged revision, and a failed saved-profile read. The failed read is covered by
`NativeDialogueTurnTest` and a disposable HTTP probe, not by this check.

## Companion lifecycle

`testCompiledClientCompanionLifecycle` is a separate run. It starts the same driver with the
second argument `companion` (see the client's `tests/loopback_dialogue/README.md`). The fixture
companion is Piper (`Fallout4.esm` `0x00002F1F`) in one flat session. The test seeds only a
loopback LLM connector, a TTS connector and one `default_npc` profile, and it inserts no NPC row
for Piper. Between phases it writes the fake provider's reply file: ordinary JSON answers on the
legacy route, a JSON `WaitHere` answer for `Wait here.`, and plain text for the native turns. It
installs no product hook and no substitute JEV route. It checks:

- The stored snapshot: Piper's identity and audience, and the captured facts as sent.
- The manual `active_agents` row from the client's `activate`.
- The `core_npc_master` row that the server created for Piper's identity with the seeded default
  profile. The native turn resolves that row and reads no saved dynamic profile for Piper.
- Route ownership. The unmarked question and `Wait here.` complete on the legacy route, with the
  openaijson prompt and no native utterance. Each marked conversation makes exactly one native
  provider call and stores one utterance spoken by Piper's identity.
- The Wait prompt observer reads the captured `wait_held=false` in the command prompt.
- Exactly one pending `WaitHere` intent owned by Piper, with no target and empty arguments.
- Two identical `unavailable` results both complete. The conflicting result, the superseded
  result and the conversation bound to the cancelled context are never claimed. There is one
  terminal result, the intent is `complete` and one Wait consequence is recorded unverified.
- The client's server cancellation marks its context anchor `cancelled` (`turn_cancelled`). After
  re-initialization the session runs generation 2.
- The request accounting: init 2, context 6, activate 1, input_text 4, action_result 2 and
  cancel 1. All are `complete` except the cancelled context.
- The driver's local negatives, which are reported, not sent: the absent actor, the other plugin,
  the VR scene with no VR Wait capabilities, the cancelled turn, the owner-cancelled result and
  the stale-generation result.

Simulated in this run:

- The driver's captured actor state and the scene its validator checks are synthetic. The
  executor outcome is the driver's labelled `SIMULATED` `unavailable` result. Nothing runs in a
  game and no physical Wait is verified.
- The test counts provider calls exactly only for the native turns. Legacy calls are checked
  for the scripted request, and continuations after a result are counted but not required. If a
  continuation runs, its prompt must report an unverified receipt.

The test is outside `unittests/tests`, so ordinary PHPUnit discovery and CI never run it. Run
directly, it skips before touching any database.

## Command

From any clean server/client checkout pair, as root on Linux or in WSL (`wsl -u root`):

```sh
sudo bash SERVER/unittests/loopback/run-loopback-dialogue.sh CLIENT
```

`SERVER` is this checkout and `CLIENT` is a SYNTH client checkout. The runner tests the `HEAD`
commit of each checkout. It refuses uncommitted tracked changes, and it refuses a runner script
that differs from the server `HEAD`.

## Prerequisites

- Linux or WSL 2 with root, util-linux (`unshare`, `findmnt`, `runuser`), iproute2, git and tar.
- g++ 12 or later (C++23).
- PHP 8.2 or later CLI with pgsql, mbstring, curl and xml.
- A PostgreSQL server package with its matching pgvector package. Debian and Ubuntu install it
  under `/usr/lib/postgresql/<version>` (for example `postgresql-16` and
  `postgresql-16-pgvector`). The runner uses the newest version that has pgvector. No PostgreSQL
  service has to run.
- The locked PHPUnit vendor tree: `composer install` in `SERVER/unittests`. The runner refuses a
  vendor tree that doesn't match `composer.lock`.
- Checkouts that Linux git can read: a clone, or a worktree created by Linux git. A worktree
  created by Windows git records a Windows path that Linux git can't resolve.

## Isolation

The script enters fresh network, mount, IPC, UTS and PID namespaces before it writes anything.
It refuses to continue unless the namespaces are private and only `lo` exists, and then it
makes every inherited mount read-only. All later writes go to private tmpfs that disappears when
the namespace exits:

- `git archive` exports of both `HEAD` commits.
- The PostgreSQL cluster on `127.0.0.1:5432`, with a disposable `dwemer` superuser. pgvector is
  not a trusted extension.
- The server's `conf/`, `data/`, `log/` and `ui/tmp/`.
- A no-op `service/tts_worker.php`.
- The compiled driver.

`/var/www`, `/home/dwemer` and `/var/lib/postgresql` are hidden, so no deployed tree or host
database is reachable. PHPUnit and the driver build run as `nobody`. Every process is killed
when the run ends.

## Exit status

| Status | Meaning |
| --- | --- |
| 0 | Passed |
| 1, 2 | PHPUnit failure or error, including a skipped test or an empty suite |
| 64 | Usage |
| 78 | Refused: prerequisite, isolation, dirty checkout, vendor/lock mismatch, driver build or PostgreSQL start |
| 79 | The exported server tree changed during the run |
| 124 | PHPUnit exceeded 600 seconds |

A pass proves the client/server HTTP and PostgreSQL contract only. The driver uses a POSIX test
transport instead of WinHTTP, and it plays no audio. It proves no game thread, socket loss or
flat/VR gameplay.
