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
