# Mapped entities (`core/entities/base`)

One `Abstract<Name>` class per `core_*` table, with `#[ORM\Entity]` and `#[ORM\Table(name: 'core_x')]`, protected typed
properties and no EntityManager dependency. Namespace `Synth\Core\Entities\Base`. Atomic or append-only tables use
`#[ORM\Entity(readOnly: true)]`. `vector`, `tsvector` and generated columns are not mapped; their DAO reads and writes them
with parameterized DBAL. The application-facing classes are in `core/entities/system`, the DAOs in `core/dbmanager/dao`.
See [docs/SCHEMA-REDESIGN.md](../../docs/SCHEMA-REDESIGN.md#4-entities-and-daos).
