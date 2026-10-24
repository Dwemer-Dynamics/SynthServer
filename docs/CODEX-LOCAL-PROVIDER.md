# Optional managed Codex dialogue provider

`tools/codex_bridge.py` adapts a private Chat Completions endpoint to Codex
app-server's managed ChatGPT sign-in. It works with the existing `openaijson`
connector. Its supported model is `gpt-6.1-sol`; it uses low reasoning effort.
No OpenAI bearer token is extracted, copied to PHP, or inserted into an API badge.
The badge contains a separate local bridge credential. This bridge uses ChatGPT
only. Existing credentials for other connectors are preserved but are not used
as fallback by this bridge.

## Verified startup after desktop updates

At startup, `codex_startup.py` resolves the installed executable from the same
OpenAI/Codex desktop installation. Hash-named update folders are checked without
searching the shell PATH. An explicitly configured non-desktop executable remains
supported. Missing or symlinked executables are refused.

Before listening, the bridge runs the local isolation fixture against that exact
executable and requires zero model-facing tools. A separate fresh managed ChatGPT
turn must return the synthetic startup response using `gpt-6.1-sol`. Only then
does startup atomically refresh the private executable setting and isolation
proof, retaining private backups. Failed isolation, sign-in or generation leaves
the previous configuration and proof unchanged. No game request or memory record
is created by these probes.

The Windows entry points under `tools/codex-launcher/` are installed into the
existing local runtime layout. The launcher entry starts the existing backend and
speech services and waits for verified ChatGPT readiness before opening the GUI.
Restarting the bridge requires Fallout to be closed. Readiness requires the exact
model, `provider_mode=chatgpt_only`, and verified startup; an older bridge's generic
healthy response is insufficient. A changed executable invalidates live health
and requests until a verified restart. Configuration and managed sign-in remain
outside the release archives.

## Request ownership and execution boundaries

Every request creates a separate app-server process and fresh ephemeral thread.
The bridge passes only the current caller-supplied text messages, JSON response
format and response budget. SYNTH retains save/NPC scoping, memory retrieval,
diaries, output interpretation, action validation and game execution.

Process-local settings disable shell, execution, apps, plugins, hooks, personal
memories, browser/computer tools, skill discovery, agents and model tools.
Unexpected server requests, tool items, thread IDs and incomplete turns abort.
The managed account must identify as ChatGPT and the effective model must match.
Nothing runs an NPC's command text as a computer command.

Before live requests, a local Responses stub must verify zero model-facing tools,
expected instructions, synthetic input and absence of personal-context markers.
The proof is tied to executable bytes, bridge/RPC source, process overrides and
discovered user configuration/policy files. Changes invalidate the proof.
The caller's global user policy can remain present; unrelated project documents
and personal agent histories are excluded. Recheck isolation after upgrades.

## Formats and bounds

SYNTH's optional JSON fields are adapted to Codex's strict output schema: all
properties are required during generation, with optional properties accepting
null. A null for an originally optional nonnullable primitive field is removed;
required fields and legitimate nullable values are retained. The result is then
validated against the original schema. Non-null action quantities are preserved.
No action allowlist is broadened. External schema references are rejected.

Input is bounded to 256 KiB / 256 text messages, with a requested response budget
of 1–4096 tokens and an output byte limit of 32 KiB. Codex's token budget here is
prompt guidance; the deadline and byte limit are enforced. Text/JSON replies are
supported. Images, audio and model-executed function tools are not supported.
Keep vision and speech on their own existing connectors.

One request runs at a time; contention returns 429. Requests failing ChatGPT
completion or validation return an actionable 502 with no alternate provider
call. Malformed requests fail before the provider is called. Replies
are buffered until validated, then emitted as complete Chat Completions JSON or
SSE. Partial or invalid output cannot become speech. Operational route
metadata is separate from character dialogue.

The adapter binds only to an explicitly configured private interface. Restrict
ingress to the dedicated container subnet and exact host loopback aliases, and
require the independent local credential. Do not bind to all interfaces or
publish a LAN port. Configuration and credentials stay outside Git; redact
errors and do not log prompts, account details, bearer tokens or responses.

## Evidence and remaining gates

Focused Python tests cover request validation, schema conversion, optional
amounts, output validation, primary-only failure, contention, scope ownership and
unexpected tool rejection. Startup tests cover removed desktop executables,
isolation/sign-in failure, configuration rollback, and readiness gating.
Workstation receipts separately record current-binary isolation,
live synthetic ChatGPT checks and actual SYNTH streaming/fast-request
parsing with database writes disabled. These do not establish game performance,
audio playback, sustained concurrency, or overall character quality.

Selecting this bridge keeps the dialogue model on ChatGPT. Other independently
configured connectors are not rewritten by startup. Stopping the local bridge
makes its requests unavailable; it does not silently select another model. See official
[app-server documentation](https://learn.chatgpt.com/docs/app-server) and
[authentication guidance](https://learn.chatgpt.com/docs/auth).
