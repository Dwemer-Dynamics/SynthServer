# Third-party notices

This file lists third-party code, data and assets used by Synthserver and how each one is handled.

**Project license:** Synthserver's own additions are licensed under the GNU GPL v3.0 (root
`LICENSE`). Third-party components retain their original licenses and notices. The root
`MIT-LICENSE` is the inherited HerikaServer/DialecticServer notice and names no SYNTH copyright
holder. The inherited server code stays under `MIT-LICENSE`; the GPLv3 does not narrow those
MIT terms. Do not remove or alter that notice. See [docs/HANDOFF.md](docs/HANDOFF.md#licensing-and-publication).

## Inherited code

| Component | License | Notes |
|---|---|---|
| [HerikaServer](https://github.com/Dwemer-Dynamics/HerikaServer) via [DialecticServer](https://github.com/Dwemer-Dynamics/DialecticServer) (`cc2205e`, see `docs/provenance/DIALECTICSERVER-BASELINE.md`) | MIT, "Copyright (c) 2023-2024 Augusto Beiro and others" | Full text in `MIT-LICENSE`. Keep it with every copy or substantial portion. |

## Bundled files

| Path | Component | License |
|---|---|---|
| `ui/lib/ui/bootstrap/bootstrap.min.css`, `bootstrap.bundle.min.js` | Bootstrap 5.3.1 | MIT. The header notice is kept in each file. |
| `data/fonts/GloriaHallelujah-Regular.ttf` | Gloria Hallelujah by Kimberly Geswein | SIL Open Font License 1.1 (`data/fonts/OFL.txt`) |
| `data/fallout_worldknowledge_*` (catalog, vocabulary, sources) | Summaries derived from [The Fallout Wiki](https://fallout.wiki/) | CC BY-SA 4.0. See `data/fallout_worldknowledge_attribution.md` and the source URLs in `data/fallout_worldknowledge_sources.jsonl`. Derived summaries stay under the same license (share-alike). |
| `data/fallout4_content_*`, `fallout4_named_bios.csv`, `fallout4_common_descriptions.csv`, `fallout4_character_completion_review.json`, `fallout4_catalogue_seed.sql` | Authored character/equipment adaptations and generated catalogue SQL | Adapted content stays under CC BY-SA 4.0; see `data/fallout4_content_attribution.md` and per-row source URLs in `data/fallout4_content_sources.json`. Generated lore retains the preceding lore attribution. The SQL format does not change these content licenses. |
| `data/fallout4_creature_bio_templates.sql` | Original SYNTH prose; FormIDs cross-checked against public references | See `data/fallout4_creature_bio_templates_attribution.md` |
| `data/fallout4_content_*`, `fallout4_named_bios.csv`, `fallout4_common_descriptions.csv` | Named character and object summaries adapted from the credited Fallout Wiki contributors; brief factual corroboration identified per item | Adaptations CC BY-SA 4.0; Fandom originals CC BY-SA 3.0. See `data/fallout4_content_attribution.md` and per-row source/history links. No game assets are included. |

## Loaded from CDNs at runtime (not redistributed)

Some UI pages make browser requests to third-party CDNs. Those requests leave the local machine.

| Resource | License |
|---|---|
| Bootstrap Icons 1.10.5 (jsDelivr) | MIT |
| Chart.js (jsDelivr) | MIT |
| DataTables 2.3.2 (cdn.datatables.net) | MIT |
| vanilla-jsoneditor (jsDelivr) | ISC |
| d3-cloud (jsDelivr) | BSD-3-Clause |
| Google Fonts: Caveat, Fasthand, Germania One, Spline Sans Mono | SIL Open Font License 1.1 |

## Project icon

`ui/images/synth.png` is the SYNTH product icon (1254x1254 RGBA PNG, SHA-256
`15cf55947a5cb9aad71727277910ae208ee95210982a466a1396de4aaf05f1b2`). The project's human
maintainer supplied it on 2026-10-06 as the authoritative icon, and it is copied byte-for-byte.
It is not a third-party component, and no separate license or author is recorded for it here.
`ui/images/favicon.ico` (16 to 256 px) is a mechanical Pillow resize of that PNG, with no
other edits. `ui/images/synth-navbar.png` (128x128 RGBA PNG, SHA-256
`49fac61762e5b4544475e5fb6f5b61477b636b78a63e42b01b2bd42ab5e9784a`) is a mechanical derivative
of the same source PNG: Pillow 12.3.0 `resize((128, 128), LANCZOS)`, saved with `optimize=True`,
alpha kept, no other edits. The navbar shows it at 50x50; the favicon link uses `favicon.ico`.
The full-size `synth.png` stays the unchanged source and is not loaded by the UI.

## Navbar banner

`ui/images/synth-navbar-banner.png` (2185x152 RGBA PNG, SHA-256
`5684be8693cf676f2bd9f998dbe4cf2a3e9f6b747eeb62d9f41489ea9052ba09`) is the unchanged original
panorama supplied by the project's human maintainer on 2026-10-06. No author, source or license
is recorded for the supplied original here.

`ui/images/synth-navbar-banner-seamless.png` (2170x725 PNG, SHA-256
`a5cb72539ce082a0b285d510de023b8d94cc0f0d538380b975b11723f65ebf14`) is an AI-generated derivative
created at the maintainer's request to blend the horizontal repeat boundary. It includes black
padding above and below the panoramic strip. `ui/css/navbar.css` displays it at native width,
centre-cropped inside the navbar so that padding is outside the visible area. The scene repeats
horizontally under a dark overlay, moving right to left by one image width every 120 seconds.
The animation is off when the viewer asks for reduced motion.

## Removed for missing provenance

On 2026-10-06 these files were removed from the tracked tree. They had no recorded source,
consent or redistribution right: `data/voices/TheNarrator.wav`, `debug/data/test.wav`,
`ui/css/font/Gothic821CondensedRegular.otf` and `ui/css/font/MonofontoRg.otf`. Local copies are
Git-ignored, and the release audit rejects their return. The UI theme now maps those font family
names to fonts already installed on the viewer's system.

These inherited images were removed for the same reason: the Fallout-style default portraits
`ui/images/npc/vaultboy.png`, `vaultgirl.png` and `roboicon.webp`, the Skyrim-style book icon
`ui/images/worldknowledge_infinium.png`, the game-scene navbar strip `ui/images/navbarback.png`,
and the unused Dialectic wordmarks `ui/images/serverlogo.png` and `serverlogodev.png`. Original
SVG primitives (`ui/images/npc/portrait-*.svg`, `ui/images/worldknowledge.svg`) replace the
active ones, and the navbar uses the maintainer-supplied banner described above. The release audit rejects the old files and any
UI reference to them.

The unused `ui/images/DwemerDynamics.png` was also removed in the inspector-backend cleanup;
no runtime UI references remained. Its removal does not establish rights to other assets.

## Not yet reviewed

Every other tracked image or media file has no recorded provenance. All of them came in with the
initial import (`4177408`). They are kept for now and listed in
[docs/ALPHA-ACCEPTANCE.md](docs/ALPHA-ACCEPTANCE.md#publication-gates).

Fallout and related names and imagery are trademarks of their respective owners.
Synthserver is not endorsed by Bethesda Softworks.
