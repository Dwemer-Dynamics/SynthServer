<?php

declare(strict_types=1);

namespace Synth\Managers\ExtensionManager;

use Synth\Core\DbManager\Dao\ExtensionDataDao;

// What an extension may use besides its payloads: its id, its settings, its key/value store and, with the storage.tables
// permission, its own tables. The store and the database connect on first use, so register() (which must not touch the
// database) can hold the context safely.
final class ExtensionContext
{
    private ?ExtensionStoreInterface $store = null;
    private ?ExtensionDatabase $database = null;

    /**
     * @param ExtensionStoreInterface|\Closure():ExtensionStoreInterface $storeSource the store, or a factory called on first use
     * @param list<string> $permissions the manifest's permissions
     * @param (\Closure():ExtensionDataDao)|null $daoSource where own-table access gets its DAO; defaults to the booted shared DAO
     */
    public function __construct(private string $id, private array $settingDefinitions, private array $settingValues,
        private ExtensionStoreInterface|\Closure $storeSource, private array $permissions = [], private ?\Closure $daoSource = null) {}

    public function id(): string
    {
        return $this->id;
    }

    // The stored value when it matches the declared type, else the manifest default; null for an undeclared name.
    public function setting(string $name): mixed
    {
        $definition = $this->settingDefinitions[$name] ?? null;
        if (!is_array($definition)) return null;
        $value = $this->settingValues[$name] ?? $definition['default'];
        return ManifestValidator::settingValueValid($definition, $value) ? $value : $definition['default'];
    }

    public function store(): ExtensionStoreInterface
    {
        return $this->store ??= $this->storeSource instanceof \Closure ? ($this->storeSource)() : $this->storeSource;
    }

    // The extension's own ext_<id>_* tables. Needs the storage.tables permission in the manifest.
    public function database(): ExtensionDatabase
    {
        if (!in_array('storage.tables', $this->permissions, true)) throw new \LogicException('permission_missing:storage.tables');
        return $this->database ??= new ExtensionDatabase($this->id, $this->daoSource !== null ? ($this->daoSource)() : ExtensionDataDao::current());
    }
}
