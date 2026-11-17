# Relationships manager

Namespace `Synth\Managers\Relationships`.

- `AuthoredRelationships`: authored, directed NPC-to-NPC relationship config. Validates rows, picks present or
  mentioned targets, bounds and escapes the authored text, renders the `[AUTHORED RELATIONSHIPS]` prompt block, and
  defines precedence with the evaluator. Phase A is behind `AUTHORED_RELATIONSHIPS_ENABLED` and reads a fixture
  (`AUTHORED_RELATIONSHIPS_FIXTURE`); storage arrives with the schema redesign.

Design, bounds and the storage addendum: [docs/AUTHORED-RELATIONSHIPS.md](../../docs/AUTHORED-RELATIONSHIPS.md).
A relationship worker, when needed, belongs in `workers/Relationships/`.
