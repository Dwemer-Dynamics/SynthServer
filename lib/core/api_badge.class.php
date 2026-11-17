<?php

require_once dirname(__DIR__, 2) . '/core/dbmanager/repositories/ApiBadgeRepository.php';

// Long-standing entry point for API keys; every key lives in the shared dwemer database through
// Synth\Core\ApiBadgeRepository. A key's id is its shared key_id (uuid string); '' / null means "no key".
class ApiBadge {
    private $repository;

    public function __construct() {
        $this->repository = new \Synth\Core\ApiBadgeRepository($GLOBALS["db"]);
    }

    /** The canonical key id in $value, or null when it is empty or not a key id. Use for every id read from a row or request. */
    public static function normalizeId($value): ?string {
        return \Synth\Core\SharedApiKeyStore::normalizeId($value);
    }

    // Create: label, api_key, optionally provider and origin. Returns the new key id, or '' on failure.
    public function create($data) {
        return $this->repository->create($data);
    }

    // Read by ID
    public function getById($id) {
        return $this->repository->byId(strval($id));
    }

    // Read by Label (case-insensitive; the oldest matching key)
    public function getByLabel($label) {
        return $this->repository->byLabel(strval($label));
    }

    // Read all
    public function getAll() {
        return $this->repository->all();
    }

    // Update label and/or key by ID
    public function update($id, $data) {
        return $this->repository->update(strval($id), $data);
    }

    // Delete by ID (secret wiped, tombstone kept; refused while a connector uses the key)
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
