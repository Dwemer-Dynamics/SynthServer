# Native memory recovery

Native memory jobs retain validated summary text before optional embedding and
publication. After a successful checkpoint, an interrupted attempt can reuse that
text on its next allowed attempt instead of asking the summary connector to write
another version of the same recorded events.

The checkpoint is an internal, versioned value in the existing worker job payload.
It binds the summary and its checksum to the exact job, frozen batch and current
stored input. The input checksum uses one consistent representation of PostgreSQL's
stored JSON; it does not reinterpret the existing batch `source_hash`. No database
migration, catalogue import, history rewrite or client protocol change is required.

Checkpoint reads and writes retain the native request, actor and source-visibility
locks. Only the current, unexpired attempt may accept text. Recovery rechecks the
current batch and its saved ancestry, source history and prior-summary dependencies.
A changed generation, lost lease, hidden input or corrupt checkpoint cannot publish
or cause the accepted text to be replaced by a new model result. Publication still
inserts one immutable summary and marks the job ready in the same transaction.

New and older jobs without a checkpoint continue through normal generation. Text
must be nonempty, valid UTF-8 and no more than 65,536 bytes after trimming before
embedding starts. A supplied completion must match any existing checkpoint. An
optional embedding failure keeps text recall available, as before. NPC ownership,
save visibility, configured providers and the three-attempt, ten-minute job budget
remain in force.

A process loss before the checkpoint commits can still repeat generation. Optional
embedding may be attempted again after an interrupted attempt. Retained text is not
visible as a memory until publication succeeds, and a checkpoint does not extend the
attempt or deadline budget. Diary jobs retain their existing separate checkpoint
implementation.

## Captured game clock

Native history projects each event's `gamets` from the immutable source or scene
context's `world.game_time_ticks`. The exact session, generation, sequence and
playthrough binding remains the authority. Dialogue adapters can freeze UTC Unix
seconds in the original history payload; those values cannot be compared with the
captured game clock to decide whether a conversation has settled. Original event
payloads, `ts` and `localts` remain unchanged.

The projected clock must be a positive integer within the context contract's safe
range, at most 9,007,199,254,740,991. Zero, missing or malformed clocks become SQL
NULL, with no sequence or wall-clock fallback. They cannot pass game-age filters.
Native memory retains the strict one-game-hour settlement cutoff of 416,666 ticks
and the existing five-event minimum, contiguous scene grouping and time buckets.
The repair does not change which actors or save frontiers can see an event.

A frozen batch is visible only when each source's saved clock agrees with the
corrected projection and its first/last clocks match those sources. New captures
hash the corrected clocks into the existing source payload. Clock-inconsistent
old batches and their checkpoints or prior summaries cannot be reused or suppress
new corrected capture. Existing history, batches and summaries are not rewritten.

## Completed dialogue selection

Automatic dispatch selects a completed request belonging to the active dialogue
turn. A rechat continuation is a candidate only when its persisted, prepared
selection names an NPC responder. A terminal marker such as `no_candidate`, or
a player/narrator continuation, therefore cannot hide an earlier completed NPC
request in that same turn. The earlier request retains its own immutable context,
audience and save frontier; dispatch does not transfer another responder's identity.

Prepared NPC continuations still use their actual selected responder and scene.
The usual origin, ownership, cancellation, capacity, source and lease checks run
before capture and publication. A turn with no eligible completed request creates
no memory work. Summary settings, settlement, cooldown and providers are unchanged.

## Validation and game acceptance

Disposable PostgreSQL checks exercise the actual memory capture, admission, claim,
checkpoint, recovery and publication functions with synthetic source history.
Coverage includes interruption recovery, unchanged deadlines, ready replay, invalid
text, corrupt or mismatched checkpoints, lease fencing, save generation changes and
source/dependency invalidation. The original implementation is retained separately
for the failing recovery regression. No paid provider or real application database
is used by these tests.

Clock fixtures reproduce UTC-stamped dialogue against native game ticks, strict
one-hour and two-hour settlement, a five-event owned batch, immutable raw payloads,
scene clock selection, malformed/unknown/future clocks and actor/save isolation.
Frozen clock mismatches fence checkpoint recovery and prior-summary reuse, while
the completed-input cancellation retention checks remain in the full suite.

Dispatch fixtures cover a newest terminal continuation after settled NPC dialogue,
publication through the background entry point, duplicate prevention, and turns
without an eligible completed NPC request. Selected NPC continuations remain
subject to their prepared responder and scene proofs.

One flat game return check confirmed normal automatic capture, a ready accepted
checkpoint and immutable summary visible to the same NPC after game-clock
settlement. The player then reported correct natural recall of the earlier plan.
After a new manual save/reload, the same stored summary remained visible to the
same NPC in the new runtime generation. Published-summary content appeared in the
recorded memory-marked provider prompt, and the player again reported correct
recall. Recent dialogue may also contribute; exclusive causation is not claimed.
Two uninvolved NPCs also passed an observed isolation check: their exact saved
reader scopes exclude the plan summary, their recorded prompts contain none of
its distinctive content, and the player reports that neither knew the plan.
These were ordinary STANDARD conversations, not formal whisper-mode checks.
Earlier-save isolation, formal whisper mode, long-session stability, and VR
acceptance remain open. Those checks remain in
[ALPHA-ACCEPTANCE.md](ALPHA-ACCEPTANCE.md).
