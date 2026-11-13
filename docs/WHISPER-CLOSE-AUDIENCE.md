# Whisper and Close audiences

- **Whisper** has one resolved NPC listener. The client uses a 200-unit range; server prompt and heard-history views also exclude bystanders.
- **Close** addresses one NPC in a small group within 200 units. Eligible nearby NPCs may hear without automatically receiving a reply turn.
- **Standard** retains its normal hearing range. **Shout** retains twice that range. Ordinary input now carries the captured audience in the format its pipeline expects.

Configured Close follow-ups stay within the original captured group. Each fresh capture can remove unavailable or out-of-range members, but cannot invite newcomers. Close and Whisper do not schedule random narrator interjections.

New Whisper history and Close continuation history record their projected identity IDs. Names alone cannot admit an excluded actor, including a newcomer with the same name as a legitimate hearer. Previously recorded memory and save cutoffs remain intact. A missing legacy identity scope retains its old interpretation; a present empty or malformed scope grants no identities.

Native conversation history includes delivered replies witnessed by the responding NPC. Another NPC's words retain their speaker and addressee; they are not presented as the responding NPC's own assistant reply.

## Delivery limit and separate follow-up

Ordinary replies still become history-visible when emitted. Emission is not proof that playback completed. Native /talk replies become heard history only after an accepted delivery receipt. This change preserves those existing delivery rules.

A separate receipt-gating change would need to cover:

- Client terminal receipts in src/client/speech_receipts.hpp, including ordinary replies and follow-ups.
- Ordinary utterance creation in lib/chat_helper_functions.php and proven response routing.
- Receipt ownership and history insertion in core/dbmanager/repositories/UtteranceRepository.php.
- Ordinary history and memory readers in lib/data_functions.php, lib/chat_helper_functions.php, and lib/synth_memory.php.
- Text, transcribed audio, rechat, and action-result replies; duplicate/conflicting receipts; interruption, cancellation, replacement, and older-save cutoffs.

Globally hiding emitted rows without adding their receipt path would remove ordinary reply memory. That is not part of this audience change.

## Evidence boundary

Off-game probes use synthetic actor snapshots, a loopback provider, a disposable database, and simulated delivery receipts. They can verify captured audiences, stored identity ownership, actual provider prompts, cancellation, and save cutoffs. They do not establish physical hearing or playback.

Flat and VR gameplay remain separate acceptance work. VR addressed selection retains its headset check and also requires the body-based 200-unit boundary used by the captured audience. No headset or deployment acceptance is implied.
