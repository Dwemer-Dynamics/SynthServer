# SynthServer

Synthserver is the Fallout 4 backend for [SYNTH](https://github.com/Dwemer-Dynamics/SYNTH/tree/alpha). It owns
the runtime, management UI, PostgreSQL schema, prompts, memory, and the LLM, TTS and STT
connector configuration used by the native SYNTH (F4SE) and SYNTHVR (F4SEVR) plugins.

## Development branches

**Start all work from `alpha` and target every pull request at `alpha`.** This is the SYNTH
exception to the other Dwemer mods' unstable-first workflow. `synth` is the default branch;
`unstable` and `dev` are reserved testing lanes, and `synth` is the main/release branch.
Promotions require maintainer approval; branch names do not imply gameplay acceptance.

```powershell
git clone --branch alpha https://github.com/Dwemer-Dynamics/SynthServer.git Synthserver
cd Synthserver
git switch -c feature/my-change
```

Read [CONTRIBUTING.md](CONTRIBUTING.md) and [docs/HANDOFF.md](docs/HANDOFF.md).
GitHub may preselect the default `synth` branch for a new PR: change its base to `alpha`.
The repository's PR branch-policy check rejects any other base.

## Status

Open-alpha preparation. The tracked source-tree safety audit and server tests run in CI; CI
builds no release package. **Paired in-game
acceptance has not been performed.** See [docs/HANDOFF.md](docs/HANDOFF.md) and
[docs/ALPHA-ACCEPTANCE.md](docs/ALPHA-ACCEPTANCE.md).

- `main.php` is the strict JSON/NDJSON endpoint for both plugins. It accepts protocol versions
  1 and 2 (`protocol/`, mirrored in the client).
- `main_synth_pipeline.php` runs dialogue, rechat, actions, memories, STT and TTS. The
  background processor in `service/` runs the memory, relationship and speech workers.
- Vision connector support is currently disabled.

## Topology

| Piece | Where | Address |
|---|---|---|
| SYNTH client | Windows, Fallout 4 / Fallout 4 VR | sends to `BaseUrl` |
| Apache and PHP (normal) | WSL, `/var/www/html/Synthserver` | `http://127.0.0.1:8087/Synthserver/` |
| PostgreSQL with `pgvector` and `pg_trgm` | WSL, database `synth` | `127.0.0.1:5432`, loopback only |
| Optional standalone dev launcher | Windows PHP, `start-dev.ps1` | `http://127.0.0.1:8085/Synthserver/` by default |

The client's default `BaseUrl=http://127.0.0.1:8087/Synthserver/` matches the Apache site in
`tools/apache/synthserver.conf`. Apache allows local requests only.

## Install

1. **WSL environment.** You need a WSL 2 distribution with `sudo`, `rsync`, Apache 2, PHP 8
   (CLI plus the Apache module, with the `curl`, `mbstring`, `pgsql` and `xml` extensions) and
   PostgreSQL with `pgvector`. A DwemerDistro installation provides this environment.
2. **Database role.** Create the loopback-only role used by the sample configuration, if it
   does not exist:

   ```bash
   sudo -u postgres psql -c "CREATE ROLE dwemer LOGIN PASSWORD 'dwemer' CREATEDB;"
   ```

   `dwemer`/`dwemer` is a local development default. Keep PostgreSQL bound to loopback.
3. **Checkout.** Clone this repository on Windows into a folder named `Synthserver`.
4. **Database.** This step uses your default WSL distribution and needs passwordless `sudo`:

   ```powershell
   powershell -ExecutionPolicy Bypass -File .\tools\create-synth-db-wsl.ps1
   ```

   The script creates `synth` as a UTF-8 database if it is missing, enables the extensions,
   imports `data/database_default.sql` only when the baseline schema is absent, and runs the
   idempotent migration chain (`tools/bootstrap-database.php` → `debug/db_updates.php`). It
   **never drops data** unless you pass `-Reset`.
5. **Deploy to Apache:**

   ```powershell
   powershell -ExecutionPolicy Bypass -File .\tools\deploy-synthserver-wsl.ps1 -Distro <your-wsl-distro>
   ```

   The script only deploys to `/var/www/html/Synthserver`. It preserves `conf/conf.php`,
   voices, uploads, caches and logs. It creates `conf/conf.php` from `conf/conf.sample.php` only
   when the file is missing. It installs the Apache site only after `apache2ctl configtest`
   passes. When `-Distro` is omitted, it uses the DwemerDistro default distribution name.

   Before creating or syncing anything, it runs the read-only
   `tools/check-php-runtime-security.sh` inside that distribution. Deployment stops unless the
   PHP CLI and every PHP runtime Apache has enabled (`libapache2-mod-phpX.Y` or `phpX.Y-fpm`)
   are at or above the PHP2Shell (CVE-2026-109618/109619) patched floor of a supported branch:
   8.2.35, 8.3.36, 8.4.27 or 8.5.12, each the distro's packaged binary matching dpkg's checksums
   with a patched embedded PHP version. Older releases, pre-releases, other branches, custom or
   unknown PHP paths and running Apache, PHP-FPM or PHP processes using a replaced or unverifiable
   PHP binary are refused. If it fails, update only the distribution's PHP packages, keeping
   existing configuration files: DwemerDistro launcher **Update Distro**, or `sudo apt-get update`
   inside the distribution and then, for the branch the check names,
   `sudo apt-get install --only-upgrade -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold $(dpkg-query -W -f='${binary:Package} ' 'phpX.Y*' libapache2-mod-phpX.Y)`.
   Stop if apt proposes removing packages; a full distribution upgrade is not needed. Restart
   Apache, PHP-FPM and PHP workers, then deploy again. A refused deployment changes no configuration, data or database. The deploy script
   never installs packages.
6. **Check.** Open `http://127.0.0.1:8087/Synthserver/health.php`, then
   `http://127.0.0.1:8087/Synthserver/ui/quickstart.php`.

### Optional development launcher

An existing local WSL/Docker installation can also use the Windows `SYNTH.exe`
candidate to resume services, check readiness and open the dashboard. Fallout
launching remains a separate button. See [docs/WINDOWS-LAUNCHER.md](docs/WINDOWS-LAUNCHER.md)
for its supported topology, offline build and remaining manual acceptance.

```powershell
powershell -ExecutionPolicy Bypass -File .\start-dev.ps1            # 127.0.0.1:8085
powershell -ExecutionPolicy Bypass -File .\start-dev.ps1 -Port 8087 # match the client default
```

The launcher serves the checkout's parent folder with the PHP built-in server. It still needs
the WSL database above. If you keep port 8085, set the client's `BaseUrl` to match.

## Configuration

- `conf/conf.sample.php` is the tracked sample. `conf/conf.php` is local and Git-ignored. It
  holds the database connection and defaults.
- Enter provider API keys in the UI (Quickstart or Configuration Hub). Never commit them or
  put them in the client INI.

Quickstart steps:

1. Enter an OpenRouter API key, or enable the local Player2 connector.
2. Select the initial TTS connector.
3. Select Parakeet or Deepgram for microphone STT. Deepgram needs a key.
4. Review the Standard, Fast, Powerful and Experimental LLM presets.
5. Click **Save and Continue**. Each save step is validated.

The player name is detected from Fallout 4. Advanced settings are in the Configuration Hub.

## Database maintenance

SYNTH requires UTF-8 because NPC `metadata` and `extended_data` are stored as `jsonb`. Older
test builds could inherit `SQL_ASCII`. To migrate without losing data, run this inside WSL:

```bash
sudo bash /var/www/html/Synthserver/tools/migrate-synth-db-utf8-wsl.sh
```

It creates a safety dump and a rollback database, and compares row counts before switching.
`create-synth-db-wsl.ps1 -Reset` drops and rebuilds the database. Use it only on a database you
intend to discard.

## Tests and release audit

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\audit-release-tree.ps1
```

The audit follows the [file layout](docs/SERVER-FILE-LAYOUT.md). It lints tracked PHP,
validates JSON and World Knowledge checksums, and checks the TTS, STT and ITT drivers. It
rejects runtime files, vendored test dependencies and assets without verified redistribution
rights.

Unit-test dependencies are not committed. Run PHPUnit only against a **disposable** PostgreSQL
with pgvector and the `dwemer` role (see `.github/workflows/server.yml`):

```bash
cd unittests
composer install
./vendor/bin/phpunit
```

Set `SYNTH_CLIENT_ROOT` to a SYNTH checkout to enable the cross-repository wiring test.

## Documents

- [Developer handoff](docs/HANDOFF.md) and [alpha acceptance and publication gates](docs/ALPHA-ACCEPTANCE.md)
- [Protocol](docs/PROTOCOL.md), [file layout](docs/SERVER-FILE-LAYOUT.md) and [feature parity matrix](docs/FEATURE-PARITY-MATRIX.md)
- [Migration source audit](docs/MIGRATION-SOURCE-AUDIT.md), [voice cloning](docs/VOICE-CLONING.md), [curated voice catalog](docs/CURATED-VOICE-CATALOG.md)
- [JSON data transfer audit](docs/json-data-transfer-audit.md)

## Contributing and license

See [CONTRIBUTING.md](CONTRIBUTING.md).

Project code is licensed under the [GNU GPL v3.0](LICENSE).
Third-party components retain their original licenses and notices.
Bethesda game content is not covered by this license and is not distributed here.

Code inherited from HerikaServer/DialecticServer keeps its [MIT-LICENSE](MIT-LICENSE)
("Copyright (c) 2023-2024 Augusto Beiro and others"); the GPLv3 does not narrow those MIT
terms. Bundled data and fonts keep their own terms; see
[THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).
