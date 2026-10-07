# Synthserver open-alpha acceptance

Status: **OPEN**. Unchecked items remain open. The historical feature baseline was `bcb0c06`
with client `f34da88`; see [HANDOFF.md](HANDOFF.md#current-cleanup-source) for current cleanup
branches. HTTP tests, PHPUnit and the release audit are prerequisites. They are not game
evidence. In-game flat and VR checks are listed in the SYNTH client's `docs/ALPHA-ACCEPTANCE.md`.

## Install and upgrade

- [ ] Clean machine: following the README on a fresh WSL install gives `health.php` with
      a healthy database, schema and background processor on `127.0.0.1:8087`.
- [ ] Re-running the database and deploy scripts is idempotent. Configuration, voices,
      uploads and the database stay intact without `-Reset`.
- [ ] Upgrading from an earlier SYNTH install applies only the pending migrations. Existing
      profiles and memories remain.
- [ ] The optional `start-dev.ps1` launcher serves the UI on its port, and a client with a
      matching `BaseUrl` connects.

## Configuration and UI

- [ ] Quickstart saves the LLM connector, TTS and STT. Missing optional keys fail cleanly,
      without PHP warnings.
- [ ] The STT diagnostic shows a clear message when no local sample is present and
      transcribes a locally supplied WAV.
- [ ] Theme: headings and monospace text render with the system fallback fonts.
      Desktop and narrow layouts, keyboard focus and contrast are unchanged.

## Paired runtime (with the client)

- [ ] Dialogue, rechat ordering, actions, STT and the five in-scope TTS providers work with
      real providers. Record provider names, never keys.
- [ ] Relationship, memory and diary workers process the queue without log loops.
- [ ] Stale-generation and cancelled requests leave no late speech or actions.
- [ ] Sustained session: stable queue depth and no runaway worker processes.

## Publication gates

The human already authorized mechanical content hygiene (workstation paths, scratch locations and
private backup destinations in tracked docs), and that pass is done. The project license is
chosen. The remaining history and publication decisions below need human direction.

- [x] **License:** the human chose the GNU GPL v3.0 (2026-10-06). The root `LICENSE` is the
      unmodified GPLv3 text. `MIT-LICENSE` and the CC BY-SA 4.0 and OFL notices are kept.
- [ ] **History:** history inherits HerikaServer/DialecticServer commits and the removed
      binaries, and it exposes author email addresses. Choose between publishing the reviewed
      history and a fresh snapshot. That choice needs human direction.
- [ ] **Assets without recorded provenance:** the provider logos in `ui/images/core/icons/`;
      `ui/images/paper.jpg`; the GIFs in `ui/css/`; and the screenshots in
      `ui/addons/pmstt/`. The unused `ui/images/DwemerDynamics.png` has been removed.
      The favicon and navbar
      now use resized copies of the human-supplied SYNTH icon `ui/images/synth.png`
      (`synth-navbar.png`, `favicon.ico`), recorded in
      `THIRD_PARTY_NOTICES.md`. The inherited portraits, book icon, navbar strip and unused
      wordmarks were removed (see `THIRD_PARTY_NOTICES.md`).
- [ ] **UI review:** desktop and narrow-width keyboard review of the plain navbar, the SVG
      default portraits, the World Knowledge icon and the STT "sample unavailable" state.
- [ ] **Seed data:** confirm the sources of the item-description and biography SQL seeds
      in `data/`.
- [x] **Docs:** workstation paths, scratch probe paths and private backup destinations in
      `FEATURE-PARITY-MATRIX`, `INWORLD-VOICE-REPAIR`, `SERVER-FILE-LAYOUT` and the `NATIVE-*`
      docs are replaced with placeholders. Dated receipts are kept as historical evidence
      (see [HANDOFF.md](HANDOFF.md#historical-receipts-and-placeholders)). Git history still
      contains the original paths.
- [ ] **CDN requests:** the UI loads fonts and scripts from third-party CDNs. Disclose this or
      vendor them with their notices.
- [ ] **Secrets:** a baseline Gitleaks 8.30.1 all-ref scan found only reviewed
      non-credential matches. A scanner only covers its own rules. Re-run on the final head.
