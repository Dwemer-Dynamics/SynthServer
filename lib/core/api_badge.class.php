<?php

require_once dirname(__DIR__, 2) . '/core/dbmanager/repositories/ApiBadgeRepository.php';

// Long-standing entry point for API keys. Keys are global (the shared dwemer store) or SYNTH-only (synth.core_api_badge);
// Synth\Core\DbManager\Dao\ApiBadgeDao presents both. A key's id here is its reference string, "shared:<uuid>" or
// "local:<integer>"; '' / null means "no key chosen".
class ApiBadge {
    private $repository;

    public function __construct() {
        $this->repository = new \Synth\Core\ApiBadgeRepository($GLOBALS["db"]);
    }

    /** The canonical reference string in $value, or null when it is empty or not a reference. Use for every id read from a row or request. */
    public static function normalizeId($value): ?string {
        return \Synth\Core\Contracts\ApiKeyRef::normalize($value);
    }

    /**
     * The secret a connector row uses. A connector that names a key (api_key_id or local_api_key_id) uses exactly that key: a
     * missing or deleted key is "no key" ('') and never falls back to another store or key. A connector that names none uses the
     * global key of its provider ($providerLabel, e.g. "OpenRouter"): the oldest shared key labelled so. Never a SYNTH-only key.
     */
    public static function keyForConnector(array $row, string $providerLabel = ''): string {
        $ref = \Synth\Core\Contracts\ApiKeyRef::fromAny($row);
        $badge = new self();
        if ($ref !== null) {
            return strval($badge->getById($ref->toString())['api_key'] ?? '');
        }
        return $providerLabel === '' ? '' : strval($badge->getByLabel($providerLabel)['api_key'] ?? '');
    }

    // Create: label, api_key, optionally store ('shared' default, or 'local'), provider and origin. Returns the new key's reference, or ''.
    public function create($data) {
        return $this->repository->create($data);
    }

    // Read by reference
    public function getById($id) {
        return $this->repository->byId(strval($id));
    }

    // Read the global key of a label (case-insensitive; the oldest matching key)
    public function getByLabel($label) {
        return $this->repository->byLabel(strval($label));
    }

    // Read all keys, global first
    public function getAll() {
        return $this->repository->all();
    }

    // Update label and/or key by reference
    public function update($id, $data) {
        return $this->repository->update(strval($id), $data);
    }

    // Delete by reference (a global key keeps a tombstone; refused while a connector uses the key)
    public function delete($id) {
        return $this->repository->delete(strval($id));
    }

    // 'deleted', 'in_use', 'missing', 'revoked' or 'unavailable'
    public function deleteOutcome($id) {
        return $this->repository->deleteOutcome(strval($id));
    }

    public function getLastError() {
        return $this->repository->lastError();
    }
}

?>
