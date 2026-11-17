<?php

namespace Synth\Core;

use Synth\Core\Contracts\ApiKeyRef;
use Synth\Core\DbManager\Dao\ApiBadgeDao;

require_once dirname(__DIR__).'/dao/ApiBadgeDao.php';

// The long-standing array-returning entry to API keys, now a thin call-through to Synth\Core\DbManager\Dao\ApiBadgeDao (which
// presents the global shared keys and the SYNTH-only keys as one list). A key's "id" here is its reference string:
// "shared:<uuid>" or "local:<integer>". A row is ['id', 'store', 'label', 'api_key', 'provider', 'origin', 'revision'].
// Without a usable shared store there are no global keys: reads of them return [] / false, writes refuse, nothing is logged
// and PHP raises no warning; SYNTH-only keys keep working. Keys are credentials: callers decide what may reach a page.
class ApiBadgeRepository
{
    private ApiBadgeDao $dao;

    public function __construct(private $db)
    {
        $this->dao = new ApiBadgeDao($db);
    }

    /** Test and tool seam: the shared store used from now on (null = none). false re-reads the environment. */
    public static function useSharedStore(SharedApiKeyStore|null|false $store): void
    {
        ApiBadgeDao::useSharedStore($store);
    }

    /** Why global keys are unavailable ('not_configured', 'unreachable' or 'not_migrated'), or null when they work. */
    public static function unavailableReason(): ?string
    {
        return ApiBadgeDao::unavailableReason();
    }

    /** One fixed sentence for a Setup or page message, or '' when global keys are available. */
    public static function unavailableMessage(): string
    {
        return ApiBadgeDao::unavailableMessage();
    }

    /** True when the shared store works. */
    public static function available(): bool
    {
        return ApiBadgeDao::unavailableReason() === null;
    }

    /** The key with this exact reference, or [] when there is none. */
    public function byId(string $id): array
    {
        $ref = ApiKeyRef::parse($id);
        return $ref === null ? [] : $this->rowOf($this->dao->find($ref), $ref);
    }

    /** The global key of this label (the oldest, any letter case); a SYNTH-only key is never returned here. */
    public function byLabel(string $label): array
    {
        $badge = $this->dao->defaultForProvider($label);
        return $badge === null ? [] : $this->rowOf($badge, $badge->ref());
    }

    /** The SYNTH-only key of this label (any letter case, lowest id), or []. */
    public function byLabelLocal(string $label): array
    {
        $badge = $this->dao->findByLabel($label, ApiKeyRef::LOCAL);
        return $badge === null ? [] : $this->rowOf($badge, $badge->ref());
    }

    /** Every key, global first, in the order of ApiBadgeDao::listAll(). */
    public function all(): array
    {
        return $this->withSecrets(null);
    }

    /** Id and label of every key, never the key. $order is id, label or lower_label. */
    public function labels(string $order): array
    {
        return array_map(static fn(array $row) => ['id' => $row['id'], 'label' => $row['label']], $this->sorted($this->withSecrets(null), $order));
    }

    /** Id, label and key of every key, for pages that only show whether a key is saved. */
    public function labelsWithKeys(string $order): array
    {
        // A SYNTH-only key is marked in its label, so a connector's key list tells the two stores apart.
        return array_map(static fn(array $row) => ['id' => $row['id'], 'store' => $row['store'], 'label' => $row['store'] === 'local' ? $row['label'] . ' (SYNTH only)' : $row['label'], 'api_key' => $row['api_key']],
            $this->sorted($this->withSecrets(null), $order));
    }

    /** ['api_key' => ...] of the key with this reference, or []. */
    public function keyById(string $id): array
    {
        $row = $this->byId($id);
        return $row === [] ? [] : ['api_key' => $row['api_key']];
    }

    /** ['api_key' => ...] of the provider's global key (oldest, label in any case), or []. */
    public function keyByLowerLabel(string $lowerLabel, bool $lowestId = false): array
    {
        $row = $this->byLabel($lowerLabel);
        return $row === [] ? [] : ['api_key' => $row['api_key']];
    }

    /** ['id' => ...] of the provider's global key, or []. */
    public function idByLowerLabel(string $lowerLabel): array
    {
        $row = $this->byLabel($lowerLabel);
        return $row === [] ? [] : ['id' => $row['id']];
    }

    /**
     * Creates a key and returns its reference string, or ''. $data: label, api_key, store ('shared' by default, or 'local'),
     * and for a shared key optionally provider and origin (a label no provider owns makes a custom key that needs an origin).
     */
    public function create(array $data): string
    {
        $ref = $this->dao->create(strval($data['store'] ?? ApiKeyRef::SHARED), strval($data['label'] ?? ''), strval($data['api_key'] ?? ''),
            array_intersect_key($data, array_flip(['provider', 'origin'])));
        return $ref === null ? '' : $ref->toString();
    }

    /** Creates a global key from a label and a value; only success is reported. */
    public function add(string $label, string $apiKey): bool
    {
        return $this->create(['label' => $label, 'api_key' => $apiKey]) !== '';
    }

    /** Sets the label and/or the key of a key. A blank global key is refused; a SYNTH-only key may be emptied (its Clear). */
    public function update(string $id, array $data): bool
    {
        $ref = ApiKeyRef::parse($id);
        $data = array_intersect_key($data, array_flip(['label', 'api_key']));
        if ($ref === null || $data === []) return false;
        return $this->dao->update($ref, array_key_exists('label', $data) ? strval($data['label']) : null, array_key_exists('api_key', $data) ? strval($data['api_key']) : null);
    }

    /** True when the key was deleted. A key a SYNTH connector uses is refused. */
    public function delete(string $id): bool
    {
        return $this->deleteOutcome($id) === 'deleted';
    }

    /** 'deleted', 'in_use', 'missing', 'revoked' or 'unavailable'. */
    public function deleteOutcome(string $id): string
    {
        $ref = ApiKeyRef::parse($id);
        return $ref === null ? 'missing' : $this->dao->delete($ref);
    }

    /** Quickstart's write: the key goes to the provider's global key, or a new global key named $label when there is none. */
    public function saveKeyForLowerLabel(string $lowerLabel, string $label, string $apiKey): bool
    {
        if (!self::available()) return false;
        $existing = $this->idByLowerLabel($lowerLabel);
        return isset($existing['id']) ? $this->update($existing['id'], ['api_key' => $apiKey]) : $this->add($label, $apiKey);
    }

    /** True when any SYNTH connector uses this key. */
    public function inUse(string $id): bool
    {
        $ref = ApiKeyRef::parse($id);
        return $ref !== null && (new \Synth\Core\DbManager\Dao\ConnectorReferences($this->db))->isUsed($ref);
    }

    /** The last refusal code of the data access, never a database message. */
    public function lastError(): string
    {
        return $this->dao->lastError();
    }

    // The raw rows (with secrets) of both stores, global first.
    private function withSecrets(?string $store): array
    {
        return array_map(static fn(array $row) => ['id' => $row['id'], 'store' => $row['store'], 'label' => $row['label'], 'api_key' => $row['secret'],
            'provider' => $row['provider'], 'origin' => $row['origin'], 'revision' => $row['revision']], $this->dao->listWithSecrets());
    }

    // One badge as the array form; null is [].
    private function rowOf($badge, ApiKeyRef $ref): array
    {
        if ($badge === null) return [];
        return ['id' => $ref->toString(), 'store' => $badge->store(), 'label' => $badge->label(), 'api_key' => $this->dao->secret($ref),
            'provider' => $badge->provider(), 'origin' => $badge->origin(), 'revision' => strval($badge->revision())];
    }

    private function sorted(array $rows, string $order): array
    {
        if (!in_array($order, ['id', 'label', 'lower_label'], true)) throw new \InvalidArgumentException('Unknown API key listing order');
        if ($order === 'label') usort($rows, static fn($a, $b) => strcmp($a['label'], $b['label']));
        elseif ($order === 'lower_label') usort($rows, static fn($a, $b) => strcmp(strtolower($a['label']), strtolower($b['label'])));
        return $rows;
    }
}
