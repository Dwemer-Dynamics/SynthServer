<?php

declare(strict_types=1);

namespace Synth\Managers\ExtensionManager;

use Synth\Core\DbManager\Dao\ExtensionDataDao;

// The extension's key/value store in core_extension_store, scoped to its id. The first time a store is read or written
// after upgrading, a JSON file left by the old file store (data/extensions/<id>.json) is imported key by key (database
// values win) and renamed to <id>.json.imported, so an extension keeps what it saved.
final class DatabaseExtensionStore implements ExtensionStoreInterface
{
    private bool $imported = false;

    public function __construct(private string $id, private ExtensionDataDao $dao, private ?string $legacyFile = null) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $this->importLegacy();
        return $this->dao->storeHas($this->id, $key) ? $this->dao->storeGet($this->id, $key) : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->importLegacy();
        $this->dao->storeSet($this->id, $key, $value);
    }

    public function delete(string $key): void
    {
        $this->importLegacy();
        $this->dao->storeDelete($this->id, $key);
    }

    public function keys(string $prefix = ''): array
    {
        $this->importLegacy();
        return $this->dao->storeKeys($this->id, $prefix);
    }

    private function importLegacy(): void
    {
        if ($this->imported) return;
        $this->imported = true;
        $file = $this->legacyFile;
        if ($file === null || !is_file($file)) return;
        $data = json_decode((string) file_get_contents($file), true);
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $key = (string) $key;
                if ($key !== '' && strlen($key) <= 200 && !$this->dao->storeHas($this->id, $key)) $this->dao->storeSet($this->id, $key, $value);
            }
        }
        @rename($file, $file.'.imported');
    }
}
