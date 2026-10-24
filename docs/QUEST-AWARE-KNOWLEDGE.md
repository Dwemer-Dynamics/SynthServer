# Quest-aware knowledge

Native protocol v2 dialogue now distinguishes the player's journal from information
an NPC has been told. An uninformed NPC cannot read the whole player journal through
the task prompt, structured dialogue context, or `read_quests`. The narrator's
explicit native role can read the exact acknowledged journal, with its observation
quality. A character named "The Narrator" does not acquire that role.

## Learning from conversation

An NPC can recall a player report that explicitly names a quest in a fresh observed
journal. For example, "I finished When Freedom Calls" can be retained as what the
player told the addressed NPC. It does not become proof of a completed quest.

The existing immutable conversation history is the learning receipt. The reader
requires the captured addressee identity, original canonical player input or the
server-frozen transcription, its conversation mode, a completed source request,
matching source history text, and a fresh complete/partial source journal. The
quest name must occur as a whole phrase in the actual input. Duplicate names among
the quests in that source capture do not grant knowledge. Mere proximity, an audience list, a model-generated reply,
diary interpretation, or a journal stage never teaches an NPC a quest fact.

The conversation reader deliberately learns from direct player-to-NPC reports. Public
audience overhearing, first-time natural-language quest aliases, information learned from
another NPC, and exact native event participation require further evidence and
are not inferred. Ordinary conversation memory still retains other spoken details.

After one eligible report names exactly one observed quest, the same NPC can associate
a conservative English follow-up with that report. Supported references are
"that/this/the/my/our plan" and "those/these/the settlers". The referenced nouns
must occur in the original named player report, and all matching earlier named
reports must identify one quest. For example, after "About The First Step, my plan
is to scout first before helping those settlers," "The plan is now to leave at noon"
can be retained as another attributed player statement about that quest. The
follow-up remains the original spoken text; the reader creates no synthetic quote
or confirmed quest outcome. A fresh observed empty journal is sufficient for a
follow-up: the already learned name comes from the original report, not a current
private objective or an inferred journal alias.

This is limited anaphora, not general natural-language quest recognition. Generic
pronouns, synonyms and unsupported references remain ordinary history. Quoted,
negated, disavowed or newly qualified topic references do not introduce or extend
these associations. For example, "my plan for my birthday" cannot inherit a quest
association from an earlier unqualified plan. An observed ambiguous quest label
cannot fall back to an older unique quest through a shared noun.
An unsupported topic before the reference, such as "For The Lost Patrol, my plan...",
also declines inference. "Tell me about that plan" can still refer directly to the
original known plan; retaining that question does not establish a new outcome.
The conservative disavowal check may decline valid negative sentences. An eligible
unassigned statement reusing either noun, or a report associating it with multiple
quests, blocks later inference for that noun within the bounded evidence window.
A later asserted statement can re-anchor only an anonymous plan barrier by tying
the plan directly to exactly one uniquely observed title: "my plan for/about/regarding
The First Step". One optional generic category, "quest" or "mission", may follow
the exact observed title. The title/category must close the qualifier with an allowed
predicate, sentence punctuation or the end of the input; a title prefix followed by an extra
noun, possessive or coordinated topic does not qualify. Only that matched qualifier
is exempted from topic rejection. Questions and explicit non-player plan possessives
do not teach a re-anchor. Original negation, quotation and other unrelated qualifiers
still prevent teaching, and the original player statement is never rewritten.
The weaker "About The First Step, my plan..." does not clear an anonymous barrier.
The reset affects only the plan noun. Settlers barriers, observed duplicate labels,
multi-quest reports and earlier named plans remain subject to the same ambiguity rules.
Two known quests with plans remain ambiguous even when a second noun matches only
one of them; the most recent report does not win.
Inferred follow-ups cannot introduce new noun aliases. Only eligible original player
reports are antecedents, never NPC replies, generated summaries or authored articles.

Explicit cancellation after a player input has completed retires its delivery,
cached reply and pending/running work while preserving the original completion
receipt. A later turn can still read that addressed quest report. Claimed or
streaming inputs never acquire a completion receipt through cancellation; completed
context, trigger and action-result requests remain cancelled. Retired completed
inputs reject replay and late request work even if their old turn becomes current
again. This repair applies to future cancellations. It does not reconstruct
completion or rewrite history that an earlier cancellation already erased.

## Ownership and save/load

`lib/quest_knowledge.php` reads the existing `native_history` relation. Its ancestry
and insertion frontiers restrict information to the current playthrough, runtime
lane, canonical responder, and explicit saved-context anchors. Loading a capture
before the report excludes the later report. An unrelated save or session does
not inherit it. Action and rechat continuations use their actual responder.
Quest references use plugin-local form IDs. NPC identity continues to follow the
existing actor registry; changing an actor's runtime form ID does not migrate its
old reports to a newly registered identity.

Evidence reads validate the live native request under its existing database lock,
use the captured PostgreSQL connection, and have bounded work. The reader creates no
tables, tags, catalogue edits, memory rewrites, or additional persisted facts.
History without sufficient provenance remains ordinary history and cannot acquire
new authoritative quest semantics.

The quest evidence query materializes its actor-owned native history projection
once. This avoids repeated ownership joins when PostgreSQL plans the report joins
and cumulative byte bound. Other history readers retain their existing default.
The same canonical sources, saved ancestry, exclusions and descending order still
apply, with at most 64 candidate rows and one mebibyte of evidence. Request-lock
and statement timeouts remain unchanged.
Follow-up resolution uses those same bounded rows in insertion order, then keeps
the existing latest-eight report projection. A seed outside the window cannot be
recovered through a vague reference. Both the seed and follow-up must satisfy the
completed-input, exact addressee, canonical text/transcription and save/lane checks;
cached, unavailable, malformed or oversized source journals do not bypass them.

## Prompts, tools and reactions

The task prompt and quest tool share one attributed projection. Reports are quoted
as data, never instructions. They contain no unshared objective, stage, editor ID,
success flag, or future quest outcome. Missing observations mean unknown; an empty
complete journal is different from a cached or unavailable read. A stopped quest
or a missing journal entry does not prove completion or abandonment.

Quest-update reactions remain grounded in their existing two-capture comparison,
but now require a prior report about the same quest to the reacting NPC. They may
ask about that earlier conversation; they cannot disclose the newly sampled private
objective. An uninformed actor's reaction is suppressed. World-knowledge rechat
topic selection also uses the responder's saved history instead of global latest chat.

## Authored participant background and quest articles

`data/fallout_quest_knowledge_rules.json` supplies eight reviewed quest rules,
16 participant bindings and 25 personal background facts. It covers When Freedom
Calls, Unlikely Valentine, Getting a Clue, Fire Support, Far From Home, Walk in the
Park, Taken for a Ride and Mechanical Menace. It adds runtime World Knowledge
articles without modifying the database or the factory catalogue checksums.

An NPC receives only their authored background for an exactly matching native
actor reference and originating plugin. A fresh complete/partial current quest
observation or that NPC's own save-visible player report must associate the quest.
Names, profile tags, base templates, foreign plugins and dynamic `0xFF` references
cannot grant a participant role. Cached/unavailable observations cannot introduce
one. An earlier eligible report can retain the association after the journal no
longer displays the quest; loading a save before that report excludes it.

These are personal background facts, not evidence that an event occurred or that
the character witnessed it. The native quest-event contract currently has no
actor-witness provenance. Stage numbers, stopped events and absent objectives
never unlock solutions, rewards, endings or completion claims. Genuine witnessed
outcomes need a coordinated client/server evidence contract and additional rules.

The central World Knowledge access decision protects the eight quest names and
their `quest_` / `fo4_quest_` topics. Advanced text comes only from the verified
responder's reviewed participant facts; public basic text comes only from the
reviewed safe premise. Custom or factory article text cannot override either
replacement. Existing class rules are an additional condition. `knowall` retains
its older advanced-tier class override but cannot bypass quest access; basic-tier
class and region conditions still apply to nonparticipants. Conflicting topic
identities and unknown reserved quest topics deny both tiers before rendering or
audit output. A missing or malformed rule catalogue fails closed.

`lib/quest_access.php` derives this access view from the locked reader. Its
`observation: complete` means the role projection was established, never that a
quest was completed. Caller-supplied facts are ignored. Narrator journal access
does not grant the narrator a participant role.

This protection covers these quest articles. Ordinary character, faction and
equipment articles retain their existing class rules; a full spoiler review of
those articles and authored stage/outcome rules remains open. Administrative
catalogue/vector inspection may show stored raw articles and is not NPC dialogue.

Quest and actor IDs were checked against installed official master metadata.
In particular, actual When Freedom Calls is `Fallout4.esm|001A001C` (`Min00`);
the older synthetic wire fixture's `000229E5` is not an authored access key.
Mirrored fixtures remain unchanged. Concise lore paraphrases, attribution,
CC BY-SA 4.0 notice and per-quest source links are recorded in the data file.
Stable source-page revisions were unavailable; the lore review is explicitly
flagged as a secondary-source review rather than independently verified dialogue.

## Validation and manual acceptance

The current integration retains `DataGetCurrentTask('speaker')` compatibility,
the existing narrator-only native journal action permission and redaction of old
NPC journal action results. It also preserves captured equipment ownership,
prewar article restrictions and basic vocabulary injection. These remain additional
conditions; quest access cannot override them. Backend awareness needs no native
client update. Strict acknowledgement of quest-event batches and genuine NPC witness
provenance are separate client/event requirements and are not claimed by this reader.

Focused tests exercise real disposable PostgreSQL history ownership and malformed
evidence, plus prompt/tool boundaries and bounded rendering. Run the existing
server source audit and PHPUnit suites in an isolated disposable database. Tests
do not call providers or launch the game.

The cancellation regression covers actual completed text/audio inputs, context and
reply cancellation anchors, request-only fallback, repeated cancellation, rejected
replay and database callbacks, interrupted inputs, context tombstones, nonplayer
descendants and actor/save isolation. All queries use a disposable PostgreSQL.
Quest query fixtures also compare every result field between ordinary and
materialized history, including actor/save/generation/provenance exclusions,
latest-row ordering, the 64-row bound and cumulative one-mebibyte cutoff.
Natural follow-up fixtures add original-quote retention, The First Step-style plan
and settlers references, ambiguous and unassigned nouns, disavowal and quotation,
uninformed actors, frozen audio transcription, completed versus interrupted
cancellation, actual action/rechat responders, earlier-save and runtime-lane
exclusion, derived-memory non-authority, malformed source journals and a seed
outside the 64-row window. Direct named-plan fixtures use invented quest names and
test qualifier closure, original-quote retention, anonymous-only re-anchoring,
hard ambiguity barriers, noun-specific reset and actor/lane/save exclusions.
Category fixtures accept one quest/mission label before the existing closure and
decline extra suffixes, coordinated topics, quotes, negation, questions and non-player
plan references; anonymous and named noun barriers remain distinct.
They use synthetic dialogue in disposable PostgreSQL;
they do not establish natural-reference gameplay acceptance.

Manual flat acceptance remains open:

- Establish a fresh journal and discuss its displayed quest name with one NPC.
- In a later conversation, confirm that NPC treats the details as the player's
  report; a different NPC must not know them merely from being nearby.
- Once that NPC has a named report containing a plan or settlers, give a supported
  natural follow-up without repeating the title. Confirm the new details remain
  attributed to the player, while an uninformed NPC, an ambiguous reference and
  a save from before the report do not acquire the association.
- Repeat privately, with a same-name actor, and with an NPC named The Narrator.
- Load a save from before the conversation: the report must disappear. A later
  properly anchored save should retain it.
- Update an objective: a prior informed NPC may ask about it, while an uninformed
  NPC must not receive the new objective or a completion claim.
- Check input audio, cancellation, action continuations, and rechat responder
  changes with the paired client. VR journal/objective limits and headset acceptance
  remain independent.
- Finish teaching a displayed quest to one NPC, then start another conversation
  that cancels the old context. That NPC must retain the attributed report, while
  interrupted input and an uninformed NPC must not gain it. Retest with a new
  report after installing the repair; earlier erased receipts are not restored.
- With When Freedom Calls freshly observed, Preston and Sturges may discuss their
  own reviewed background. A same-name actor must not acquire those facts. Repeat
  with an authored DLC participant and a capture before that DLC quest was observed.
- Request a protected quest article through ordinary, forced and rechat topic
  paths: custom hidden-outcome text and `knowall` must not unlock its advanced text.
  Public basic premises remain subject to their existing region/class rules.

This is a backend-only change with unchanged wire schemas and protocol manifests.
Publishing, deployment, live providers and gameplay are separate steps.
