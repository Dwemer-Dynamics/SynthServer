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

## Settings changes while running

The settings-recovery candidate distinguishes user configuration/policy edits
from executable, loaded bridge-code and external proof changes. A settings-only
change marks health as `revalidation_required`. The next authenticated inference
request closes the old transport and runs the bounded local isolation fixture
against the current settings before sending the caller's dialogue to ChatGPT.
Two fresh synthetic scopes must contain zero model-facing tools, their own exact
input and no unrelated personal context. A settings change during that check
refuses publication of the recovered state.

After success the bridge retains the new proof and signature in memory and
continues the same primary request. It does not rewrite private configuration,
credentials, global Codex settings or the on-disk proof. Later requests reuse the
verified signature; idle retirement does not erase this in-process verification.
The local recheck has a ten-second shared provider-event budget; hashing and
process cleanup add their bounded normal overhead. It makes no managed-provider
generation call and never receives the caller's game text.

Health polling stays read-only. A failed check reports `isolation_recheck_failed`
without private diagnostics and is not repeated for the same unchanged settings.
Another settings edit permits another check. Executable, loaded-code or external
proof changes still require a verified restart. A fresh process continues to use
the existing full startup and managed ChatGPT readiness procedure.

SYNTH profiles can independently retry a failed bridge request through their
configured fallback connectors. This is separate from the bridge's primary-only
policy. Settings recovery prevents the avoidable stale-settings error; it does
not disable configured fallback for genuine provider or isolation failures.

## Request ownership and execution boundaries

Every request creates a fresh ephemeral thread. The latency candidate reuses the
app-server transport for up to eight completed requests, then recycles it. It also
retires the process after 60 idle seconds. Conversations are never resumed or
shared between NPCs; each input contains only the caller's current supplied context.
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
The latency candidate checks two distinct synthetic threads through the same
transport. Both must have zero model-facing tools and exactly their own input.
Full proof hashing is cached only while the executable, source, proof and discovered
configuration/policy file identities, sizes and timestamps remain unchanged. A
changed signature closes the transport and requires a matching verified proof;
the settings-only case can recover through the local check described above.
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

The latency candidate checks caller disconnection while waiting for provider
events. Disconnection discards partial output and retires the transport; it does
not retry generation. Provider EOF fails immediately rather than consuming the
remaining generation deadline. A busy bridge still fails promptly instead of
queueing another conversation. Successful HTTP responses expose bounded phase
durations in `Server-Timing` and cold/warm transport status in
`X-SYNTH-Transport`, without prompts, actor names or provider bodies.

This remains a validated, buffered reply path. It does not deliver model deltas
as unvalidated speech or change the selected model, reasoning effort or service
tier. Warm transport is an overhead reduction, not a claim that model generation
or memory retrieval is instantaneous.

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

The latency candidate has focused Windows and WSL tests covering fresh-thread
isolation on reused transports, late output rejection, cancellation, invalid or
oversized output, idle retirement, process death and HTTP timing headers. A
current-executable local stub separately proves both synthetic contexts contain
zero tools. Managed ChatGPT synthetic cold/warm timings are separate workstation
evidence; installed gameplay latency and cancellation still require a return check.
Settings-recovery tests additionally cover actual temporary settings/policy edits,
single-flight recovery, failed checks, concurrent edits, source/executable/proof
replacement, private-file preservation and HTTP health-to-primary recovery. An
actual-executable fixture with simulated stale startup metadata separately checks
local isolation and one managed synthetic primary reply. It is not a live settings
edit, an installed repair or gameplay acceptance.

Selecting this bridge keeps the dialogue model on ChatGPT. Other independently
configured connectors are not rewritten by startup. Stopping the local bridge
makes its requests unavailable; it does not silently select another model. See official
[app-server documentation](https://learn.chatgpt.com/docs/app-server) and
[authentication guidance](https://learn.chatgpt.com/docs/auth).
