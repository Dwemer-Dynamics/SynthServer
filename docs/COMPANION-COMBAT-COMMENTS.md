# Post-combat companion comments

The optional flat `dialogue.companion_combat_comments` capability adds a
`companion_combat_comment` trigger tied to an acknowledged combat-end event ID.
Its typed participant evidence is bounded to eight same-playthrough teammates
observed in combat before that end. It introduces no database schema change.

Admission requires the current registered, living, calm teammate in the captured
audience and in the stored encounter evidence. The original event must remain
fresh, at least three seconds old, and this must be its first reaction claim.
The existing RPG combat enable/chance controls apply; companion attempts share a
separate 30-second cooldown. The prompt never infers victory or specific kills.
The internal observation remains `combatend`, excluded from ordinary player dialogue
history. No fabricated player line starts the comment.

Use the paired client's opt-in setting and acknowledgement. Older clients keep
their existing reaction behaviour. VR requests are rejected. Client-owned child
cancellation, current scene checks and a 15-second latest start prevent delayed
output from appearing after input, load, departure or renewed combat.

Automated evidence is simulated. No deployment or gameplay acceptance is implied.
Native searching, teammate and vanilla/modded wait semantics remain separate
in-game checks; see the paired client feature note and acceptance checklist.
