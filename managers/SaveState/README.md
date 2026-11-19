# SaveState

`Synth\Managers\SaveState\SaveState` is the save policy. A turn reads only its claimed snapshot of the active
save, and history is that snapshot's saved ancestry: an older save sees earlier ancestry without deleting newer
history. Only lines receipted as delivered (`spoken`, `presented`) count as heard; `interrupted` lines may appear in
a prompt but are never heard.

- `boundContext()` and `conversationRows()` read through `SaveRepository`; no SQL here.
- `created()` validates the `save_created` signal and logs it; nothing is stored until the save schema lands.

`managers/SaveState.php` is a legacy alias hook.
