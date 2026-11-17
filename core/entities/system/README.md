# System entities (`core/entities/system`)

The only entity classes top-level code (API, `lib`, `ui`, drivers, workers, tools) uses. They carry no ORM attributes,
extend the mapped `Abstract*` class and use `CopiesMappedEntity`. They never expose a DAO or an `Abstract*` class.
Namespace `Synth\Core\Entities\System`. See [docs/SCHEMA-REDESIGN.md](../../docs/SCHEMA-REDESIGN.md#4-entities-and-daos).
