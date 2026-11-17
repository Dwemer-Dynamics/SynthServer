<?php

require_once dirname(__DIR__, 2) . '/core/dbmanager/repositories/ApiBadgeRepository.php';

// Long-standing entry point for API key rows; every statement lives in Synth\Core\ApiBadgeRepository.
class ApiBadge {
    private $repository;

    public function __construct() {
        $this->repository = new \Synth\Core\ApiBadgeRepository($GLOBALS["db"]);
    }

    // Create (Insert)
    public function create($data) {
        return $this->repository->create($data);
    }

    // Read by ID
    public function getById($id) {
        return $this->repository->byId((int)$id);
    }

    // Read by Label (case-insensitive)
    public function getByLabel($label) {
        return $this->repository->byLabel(strval($label));
    }

    // Count of rows with a non-empty key, without reading any key (read-only health check)
    public function countWithKeys() {
        return $this->repository->countWithKeys();
    }

    // Read all
    public function getAll() {
        return $this->repository->all();
    }

    // Update by ID
    public function update($id, $data) {
        return $this->repository->update((int)$id, $data);
    }

    // Delete by ID
    public function delete($id) {
        return $this->repository->delete((int)$id);
    }

    public function getLastError() {
        return $this->repository->lastError();
    }
}

?>
