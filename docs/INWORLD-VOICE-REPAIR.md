# Inworld voice repair - 2026-09-08

Current runtime logs at 17:54 showed Danse's `npcmpaladindanse` sample was found,
but the generated 70-character clone name exceeded Inworld's 60-character limit.
Each sentence retried the same clone and fell back to `maleadult02`.

The resolver now shortens only the provider label to 60 ASCII characters, retaining
the full sample/language identity in local cache keys. A credential/workspace/sample
scoped lock serializes sentence workers, rechecks the cache after acquisition, polls
cancellation, and bounds waiting to 45 seconds. Failed attempts share a 60-second
cooldown instead of multiplying clone calls. Cartesia behavior is unchanged.

Validation: PHP lint and diff checks passed. A network-disabled, three-process probe
verified one successful clone across three workers, shared failure cooldown, the
60-character label bound, and unchanged full cache identity. Full PHPUnit was not run
(this checkout has no installed PHPUnit dependency bundle).

Deployed only `lib/voice_clone_resolver.php` to `/var/www/html/Synthserver`, preserving
all other runtime files, configuration, media, voices and database state except the
requested player settings and newly created voice cache. No Apache restart, migrations,
client deployment, game control, publication or PR. Source changes are uncommitted in
`codex/equipment-local-20260908` and mirrored into `codex/dialectic-parity-core`.

Runtime file SHA256: `ac3faf08dbe484dae5b259c2d54e4de451085615a0f57433ddf5a2f3e2b6072f`.
Previous resolver retained at `<private-backup>/pre-inworld-clone-resolver.php`.
HTTP health returned 200.

Live checks created and cached Danse's clone from his existing immutable 446542-byte
sample. Inworld returned 145530 PCM bytes for the exact Danse clone ID, and 104076
bytes for the existing `maleargonian` voice ID, without a fallback call. This is provider
proof, not an auditory comparison or in-game playback proof.

Player settings were saved and reread: `tts_connector_id=3` (Inworld),
`tts_voice_override=maleargonian`, and its workspace voice ID in `tts_voice_id_override`.
No HerikaServer credentials or settings were changed. Test a fresh message in-game;
previously generated fallback audio/replayed responses were not deleted.
