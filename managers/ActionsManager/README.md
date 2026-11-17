# ActionsManager

Server-side policy for model actions that need more than the generic action path.

## Confirmed trading

`Synth\Managers\ActionsManager\TradeOffer` validates a model `Barter` line into a one-unit sale offer, rechecks
it when the intent is recorded, verifies the paired receipt against the original turn snapshot, and builds the
outcome text the NPC is given. It is pure: it reads a copied turn context and the receipt and touches no database.
The legacy emission, intent, result and follow-up paths call it through thin hooks. The contract, limits and
what is not proven are in [docs/CONFIRMED-TRADE.md](../../docs/CONFIRMED-TRADE.md).
