# Optional Jev action selection

Jev can classify the current player's requested action before dialogue generation.
It selects an action **type** from the already enabled, negotiated NPC catalogue.
The normal dialogue model supplies targets, items and quantities, or declines with
Talk. No Jev answer executes a command by itself or proves an action succeeded.

This server-only option is off by default. There is no new client action or
protocol capability. Pickup and any other disabled/unadvertised action remain
unavailable. Flat-only capabilities, including Wait Here, stay unavailable in VR.

## Setup

1. In **LLM Connectors**, create a dedicated connector labelled, for example,
   `Jev Action Decisions`. Select driver `openrouterjson`, model
   `typesafe/jev-1.13`, and an existing OpenRouter API badge. This connector is
   separate from the NPC's dialogue connector. Do not select it for conversation.
2. In **Global Settings > Global Connectors**, select it under **Jev Decision
   Connector** and turn on **Jev Action Selection**. The request always uses
   `https://openrouter.ai/api/alpha/decisions`; the connector's chat URL, optional
   chat parameters and fallback models do not apply.
3. Leave **Jev Time Budget (ms)** at 350 initially. It is clamped to 50–750 ms.
   **Jev Minimum Confidence** defaults to 0.8 and is clamped to 0.5–1. Confidence
   must be evaluated on gameplay examples; a high score can still be wrong.

The corresponding sample settings are `JEV_ACTIONS_ENABLED=false`,
`CORE_CONNECTOR_DECISION=0`, `JEV_ACTION_TIMEOUT_MS=350`, and
`JEV_ACTION_MIN_CONFIDENCE=0.8`. Existing settings storage adds these managed
defaults without changing saved profiles or replacing an existing value. Keys
stay in API badges, never in sample configuration or decision logs.

## Behaviour and delay

The classifier runs once for an owned native player text/audio turn, after STT
and catalogue filtering and before constructing the dialogue schema. It receives
the accepted current utterance, the current responder's display name, eligible
action descriptions and a compact projection of that exact selected NPC's captured
state: teammate, alive/disabled, combat/hostility, line of sight, life state and
owned Wait state. Missing or ambiguous observations remain unknown; an absent
Wait observation is not false. Unknown line of sight is unavailable to the classifier.
It does not read global speech history, retarget a
different NPC, broaden an audience or change saved knowledge.

An accepted choice narrows the turn's available actions to that choice plus Talk.
Talk means speech only; `abstain`, low confidence, an invalid choice, missing
configuration, a provider failure or a late answer preserves ordinary action
selection. Multiple requested actions and context-dependent instructions are
deliberately eligible for abstention. Argument generation and NPC willingness
remain with the dialogue model outside the companion shortcut below. Missing
arguments never become guessed defaults.

### Clear companion Wait/Release shortcut

In flat STANDARD mode, an accepted high-confidence Jev choice can hand a narrow,
explicit Wait Here or Release Wait request to the existing typed action path
without waiting for the dialogue model to emit the same command. The selected
actor must be a known calm, live teammate, its owned Wait state must be known,
and the request must describe the corresponding state transition. Ordinary NPCs,
refusals, quoted/negated/hypothetical instructions, multiple actions, missing
facts and other modes retain ordinary dialogue routing.

The immutable capture must be the latest in its runtime generation and at most
five seconds old. This check runs again inside the existing native intent lock.
The canonical selected actor, accepted typed text or STT transcript, action
capabilities, cancellation, duplicate guards and physical result verification
remain authoritative. No menu/native executor or protocol is added.

The shortcut completes its response after issuing the typed action. The client
can then execute it, and the existing action-result continuation supplies natural
dialogue from the verified outcome. Issuance itself never becomes a successful
memory or a spoken claim that waiting succeeded. This avoids one dialogue-model
generation on eligible command turns; earlier context/history work still occurs.

The bounded selection is sequential, so a successful call adds its actual elapsed
time before the dialogue call. It is **not** a zero-latency parallel integration.
There is one provider attempt with no retry, no redirect and a 16 KiB response
cap. The time budget includes routing preparation and provider waiting; existing
database ownership checks still run and can add their normal database overhead.
Jev abstains when cURL lacks asynchronous DNS support, which is needed to bound
name resolution at sub-second budgets.
The worker checks cancellation while waiting. Stop or save/load cancellation
propagates instead of becoming fallback work.

With the switch off, no extra context/connector query or provider request runs.
Rechat, background/narrator work and action-result followups skip Jev. Dialogue
provider retries do not rerun it. A decision is bound to the exact immutable
event. The command boundary permits at most one accepted action from that choice
and retains canonical actor, capability, argument and durable ownership checks.

`[JEV ACTION]` diagnostics record the selected action type, provider-reported
confidence, reason, elapsed milliseconds, budget, generation/context sequence,
hashed decision tag and explicit ordinary-LLM fallback behavior. Confidence is
a routing signal, not a calibrated probability of correctness. `[JEV TRACE]`
metadata separates selection, handoff, dispatch and result; a dispatched intent
is not execution proof. Physical-proof fields are permitted only for result traces.
The new metadata omits keys, utterances, utterance hashes, provider bodies, names
and raw actor/action identifiers. Existing logger request prefixes remain unchanged.
The ordinary existing request/audit logging remains subject to its own policy.

## Acceptance

Automated loopback transport and disposable-database tests exercise disabled
mode, transcript separation, catalogue narrowing, fallback, malformed/oversized
responses, timeouts, cancellation, exact actor ownership and duplicate prevention.
They establish neither live Jev accuracy nor actual flat/VR gameplay.

Before enabling for routine play, compare typed and spoken Wait Here, Release
Wait and Follow with the switch off/on; include normal conversation, refusal,
ambiguous/multiple requests, missing arguments, provider failure, Stop and save
load. Check measured decision time, time to first speech, executed action/results
and accidental actions. Test only the capabilities advertised by each lane.
Record live provider, flat and VR evidence separately.

## Design reference

The CHIM starting point is its bounded optional responder selector in
[`lib/stt_target_jev.php`](https://github.com/Dwemer-Dynamics/HerikaServer/blob/57f292415b008cfaf1a4531f0654d2563362ce70/lib/stt_target_jev.php).
SYNTH applies the same choice/abstention pattern to action types, with a shorter
budget and its own ownership/argument validation. This is an original SYNTH
implementation; no third-party source was copied. See the
[OpenRouter Decisions API example](https://openrouter.ai/blog/tutorials/how-to-use-jev/)
and [TypeSafe introduction](https://docs.typesafe.ai/introduction) for the provider
contract. Existing inherited MIT attribution remains in `MIT-LICENSE`.
