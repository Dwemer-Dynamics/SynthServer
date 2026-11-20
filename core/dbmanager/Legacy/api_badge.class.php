<?php

require_once dirname(__DIR__, 3) . '/core/dbmanager/repositories/ApiBadgeRepository.php';

// Long-standing entry point for API keys. Keys are global (the shared dwemer store) or SYNTH-only (public.core_api_badge);
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
     * The ONE resolver for the secret a connector sends. A connector that names a key (api_key_id or local_api_key_id) uses exactly
     * that key: a missing or deleted key is "no key" ('') and never falls back to another store or key. A connector that names none
     * uses the global key of its provider ($providerLabel, e.g. "OpenRouter"): the oldest shared key of that provider, never a
     * SYNTH-only key. A global key is pinned to one origin: when $endpoint (the URL the request goes to; '' = the provider's own
     * API) is another origin the key is refused ('' and one fixed log line), as ChimServer's resolveSecret does. A SYNTH-only key
     * has no pinned origin.
     */
    public static function keyForConnector(array $row, string $providerLabel = '', string $endpoint = ''): string {
        $ref = \Synth\Core\Contracts\ApiKeyRef::fromAny($row);
        $dao = new \Synth\Core\DbManager\Dao\ApiBadgeDao($GLOBALS["db"]);
        if ($ref === null) {
            $default = $providerLabel === '' ? null : $dao->defaultForProvider($providerLabel);
            if ($default === null) {
                return '';
            }
            $ref = $default->ref();
        }
        return $dao->secretForEndpoint($ref, $endpoint, $providerLabel);
    }

    /**
     * Runs one connector write that stores a reference to the shared key $sharedUuid (null: none, or a SYNTH-only key) while that key
     * is share-locked, so a concurrent delete cannot strand the reference. A missing, revoked or unreachable key refuses: returns
     * $refused and writes nothing. The write must be one autocommitted statement.
     */
    public static function lockedWrite($sharedUuid, callable $write, $refused) {
        $ref = is_string($sharedUuid) ? \Synth\Core\Contracts\ApiKeyRef::shared($sharedUuid) : null;
        if ($ref === null) {
            return $write();
        }
        $bound = (new \Synth\Core\DbManager\Dao\ApiBadgeDao($GLOBALS["db"]))->bindUnderLock($ref, $write);
        return $bound['outcome'] === 'locked' ? $bound['result'] : $refused;
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

    // Count of keys (global and SYNTH-only) with a saved secret, without returning any key (read-only health check)
    public function countWithKeys() {
        return $this->repository->countWithKeys();
    }

    // Read all keys, global first
    public function getAll() {
        return $this->repository->all();
    }

    // Update label and/or key by reference
    // $expectedRevision: a global key that changed since then is refused and getLastError() is 'conflict'
    public function update($id, $data, $expectedRevision = null) {
        return $this->repository->update(strval($id), $data, $expectedRevision);
    }

    // Delete by reference (a global key keeps a tombstone; refused while a connector uses the key)
    public function delete($id) {
        return $this->repository->delete(strval($id));
    }

    // 'deleted', 'in_use', 'missing', 'revoked' or 'unavailable'
    public function deleteOutcome($id, $expectedRevision = null) {
        return $this->repository->deleteOutcome(strval($id), $expectedRevision);
    }

    public function getLastError() {
        return $this->repository->lastError();
    }
}

?>
