# Narrator

Private narrator domain (`Synth\Managers\Narrator\*`).

| Class | Role |
| --- | --- |
| `PrivateNarratorManager` | One private narrator request: command, typed, push-to-talk or scene turn, or the receipt of a reply. Storage goes through `PrivateNarratorRepository`; provider and speech calls run outside every transaction. |
| `PrivateNarratorProtocol` | The capability gate, the trust-boundary shape of the three narrator events and the lane-state capture on a context claim. `lib/synth_private_narrator.php` keeps the legacy function names as thin hooks over it. |
| `NarratorObjectIdentity` | Validates talking-object identities and the `narrator.object.v1` grant; see [docs/TALKING-OBJECTS.md](../../docs/TALKING-OBJECTS.md). |

The contract (`NarratorOutput`, `NarratorRejected`) stays in `core/contracts/narrator.php`; contracts do not move into a
manager. `managers/PrivateNarratorManager.php` is a legacy alias hook.
