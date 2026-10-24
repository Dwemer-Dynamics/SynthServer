# Observed equipment and save labels

Dialogue receives a compact `current_observed_equipment` projection before the
full actor-state context. It reads the player and target from the request's owned,
immutable snapshot, before inventory preview truncation. Current observed gear
takes precedence over guesses in older dialogue. Equipped weapons are marked held,
stowed, unknown or ambiguous; worn armor, clothing and accessories are separate.
Carried loot is omitted. Missing, partial and unavailable observations do not prove
absence. Identity ambiguity suppresses that actor's equipment view.

Optional catalog descriptions use the captured item origin plugin and local FormID.
Custom descriptions retain precedence. Name, wildcard and bare runtime-ID fallback
are excluded. Labels and descriptions are escaped data, never prompt instructions.
The projection does not refresh the game, issue actions or write memories.

The same bounded observation is reinforced after history and response cues,
immediately before the accepted player utterance. Action/rechat continuations
receive it after their owned tool/history context. Recognition of an observed
item is separate from expertise, approval or a wish to discuss weapons; character
personality remains intact. Unknown equipment remains unknown. The reinforcement
rejects malformed, oversized and entity-bearing projections without truncation.
Native requests also exclude mutable legacy biography equipment blocks; legacy
requests retain their existing behavior.

`CheckInventory` searches the speaking NPC's inventory. Its legacy `target`
parameter is an item filter, not another actor. `Inspect` reports identity and
condition; it does not refresh equipment. Known visible equipment can be described
directly with `Talk`.
Native runtime tool descriptions enforce these limits after database/custom
definition merges. Persisted action definitions and parameter validation remain
unchanged. The dialogue action cue distinguishes describing already observed
equipment from requests to change game state.

The first paired equipment retest showed correct captures and actual provider
payloads, yet the model repeated older weapon claims and Codsworth's inventory
refusal. This establishes a model-output failure rather than a missing capture
or deployment. Current observation reinforcement and tool-scope corrections
need another paired check; local/provider fixtures cannot prove gameplay behavior.

The character list displays **Latest captured save**, **Other save** or a neutral
**Save** label with a short digest. The latest label follows accepted context with
valid session/player ownership, not actor last-seen or background dispatch times.
It identifies the most recently recorded save, not a currently running game.
Separate save-owned copies of one named NPC retain separate profiles and histories.
The digest is only a display aid; full canonical identity remains authoritative.

Return checks: change from a 10mm pistol to a shotgun to a laser musket, asking the
same NPC about the current weapon after each change. Stow the weapon and ask again.
Ask about a worn Vault 111 suit. Compare the exact captured request to any incorrect
answer. Check that separate save entries are labeled and that editing one profile
leaves the other unchanged. Backend tests do not prove correct in-game speech.
