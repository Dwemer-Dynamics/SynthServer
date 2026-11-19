# CharacterManager

`Synth\Managers\CharacterManager\CharacterManager::resolve(TurnRequest)` resolves the addressed target to a
registered placed NPC (`PlacedCharacter`): the stable placed key, the current runtime binding, the profile that owns
its model and voice, and the save-visible dynamic profile.

- Reads go through `CharacterRepository`; the manager holds no SQL.
- A target without a placed reference, an unregistered NPC or an ambiguous load order throws `TurnRejected` with a
  fixed code (`target_missing`, `target_not_placed_reference`, `npc_not_registered`,
  `load_order_identity_ambiguous`); it never guesses by name.
- The admitted save's latest revision replaces only the five dynamic profile fields of this turn's copy.

`managers/CharacterManager.php` is a legacy alias hook. See the CharacterManager row in
[docs/CHEVO-PARITY.md](../../docs/CHEVO-PARITY.md).
