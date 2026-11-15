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
legacy route, a JSON answer naming the offered `Wait_Here` label for `Wait here.`, and plain text
for the native turns. It installs no product hook and no substitute JEV route. It checks:

- The stored snapshot: Piper's identity and audience, and the captured facts as sent.
- The manual `active_agents` row from the client's `activate`.
- The `core_npc_master` row that the server created for Piper's identity with the seeded default
  profile. The native turn resolves that row and reads no saved dynamic profile for Piper.
- Route ownership. The unmarked question and `Wait here.` complete on the legacy route, with the
  openaijson prompt and no native utterance. Each marked conversation makes exactly one native
  provider call and stores one utterance spoken by Piper's identity.
- The Wait prompt observer reads the captured `wait_held=false` in the command prompt. That prompt
  offers the catalog's public label `Wait_Here`, which the scripted answer uses.
- Exactly one pending intent owned by Piper with the canonical `WaitHere` code, no target and empty
  arguments, so the parser mapped the label to the code.
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

## Auto Chat

`testCompiledClientAutoChat` is a separate run of the driver with the second argument `autochat`.
Every root carries `payload.control.mode` `AUTOCHAT` on the ordinary route. Most are typed
`input_text`. Two are voice roots: the driver uploads a tiny WAV to the real `media.php` (the
router fixture sends `/media.php/<id>` there and everything else to `main.php`), then sends
`input_audio` with only the opaque media reference.
The test repoints the fresh-install default player connector (OpenRouter row 2, non-streamed) at
its own loopback rewrite route, which is separate from the NPC connector's SSE route. From the
third root on, the player connector is an official Quickstart-managed `openaijson` row
(non-streamed) on the same route. It sets
`CORE_CONNECTOR_PLAYER`, `RECHAT_H=0` and `RECHAT_P=0`, so every provider call is counted
exactly, and it starts `main.php` with `PHP_CLI_SERVER_WORKERS=4` so that the duplicate and the
cancel can overtake a held root. It checks:

- Accepted roots (a 4096-byte two-byte UTF-8 line, `Hmm!`, and a same-named Settler with another
  reference): exactly one rewrite call and one NPC call each. The raw intent stays in the
  canonical event and `dialogue_origin_policies`, and never appears in the NPC prompt, history or
  wire dialogue. There is one generated `inputtext` row under the deterministic utterance id, one
  `addressed` player route and no native utterance. The wire carries the exact line once.
- Player TTS: none for the first root (text-only), and a refusing loopback TTS connector for
  `Hmm!` (no media, never `ready`).
- Accepted voice root: the server's configured `localwhisper` STT connector
  (`GLOBAL_STT_CONNECTOR_ID`) posts exactly the uploaded bytes once to a fake `/stt` route on the
  loopback provider. That transcript is the rewrite intent (once), and it is
  `dialogue_origin_policies.input_text`. The canonical event keeps only the media reference, and
  the transcript never reaches the NPC prompt, history or wire. The player's configured `mimic3`
  connector and voice override make one fake `/api/tts` call with the exact generated line.
  That call returns a 48-byte WAV, and the wire's player `speech` is `ready` with exactly that
  media, which is registered for the request and the line's utterance id. Nothing fetches or
  plays it. This rewrite prompt carries each earlier generated line of the pair exactly once.
- Refused voice root (empty rewrite): one STT call with its bytes, its own transcript frozen as the
  origin input, one rewrite call, then `provider_failed` and no NPC call, history row or player
  route.
- Empty, incomplete, HTTP 500 and 4097-byte rewrites: `provider_failed` with the closed detail,
  one rewrite call and no NPC call, history row or player route.
- The exact request bytes resent after a complete root and after a failed one replay the stored
  `response_body` and invoke nothing. Resent while the rewrite is held, they are refused with
  `request_pending`.
- With the rewrite held, the client's interrupted-turn cancel is acknowledged and no NPC call
  follows. With the NPC call held, that call stays counted and nothing new starts after the
  acknowledgement. In both cases the held provider is released with a non-blocking FIFO write that
  gives up after 2 s if it already left. The root must then end `cancelled` (`turn_cancelled` or
  `request_cancelled`) or `failed` within 5 s, never `complete` or still live. Its state at the
  acknowledgement and whether the release was read are reported.
- Sessions restored from the co-saves before and after the generated lines: the `/talk` prompt
  carries the 4096-byte line 0 and 1 times.

The voice roots need a writable `soundcache/` (media, STT temporary file and TTS cache) and
`data/`. `run-loopback-dialogue.sh` mounts both, with `conf`, `log` and `ui/tmp`, as private tmpfs
over the read-only export. `soundcache/` is untracked, so the runner creates it empty before the
source hash.

It does not cover a rechat continuation. `RECHAT_P=100` would make the server's roll
deterministic, but a continuation also needs the client's rechat trigger with an owned parent, a
second scene NPC and the `dialogue.rechat.*` capabilities, which this driver does not have. Root
proof and missing, foreign or conflicting proof are left to the focused server tests. The NPC
prompt's count of the generated line is recorded, not asserted, because the ordinary prompt's
handling of the current input has not been pinned. The client's departure decision
(`PlayerDialogue`) is not compiled here.

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
- The locked production and PHPUnit vendor trees: `composer install --no-dev` in `SERVER` and
  `composer install` in `SERVER/unittests`. The runner refuses a vendor tree that doesn't match
  its `composer.lock`.
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
