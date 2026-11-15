<?php

namespace Synth\Core;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMapping;
use Synth\Core\Doctrine\EntityManagerFactory;
use Synth\Core\Entities\GeneralSetting;

require_once dirname(__DIR__).'/EntityManagerFactory.php';

// Stored server-wide general settings, read through Doctrine on the shared connection. Reads are array or scalar
// hydrated, never the identity map, so each call sees the row as the shared session sees it now: a direct legacy
// write, even one still uncommitted in the caller's transaction, is visible to the next read. No read locks.
class SettingsRepository
{
    private ?EntityManagerInterface $entities = null;

    public function __construct(private $db) {}

    /** Raw stored value, or null when the setting has no row. */
    public function value(string $id): ?string
    {
        try {
            $rows = $this->entities()->createQuery('SELECT s.id, s.value FROM '.GeneralSetting::class.' s WHERE s.id = :id')
                ->setParameter('id', $id)->setMaxResults(1)->getArrayResult();
        } catch (\Doctrine\DBAL\Exception|\Doctrine\DBAL\Driver\Exception $error) {
            // As before: execQuery() has logged the failure, and an unreadable setting reads as absent.
            return null;
        }
        return $rows === [] ? null : strval($rows[0]['value'] ?? '');
    }

    /** Raw stored values for the named settings that have a row, in one read; throws when unreadable. */
    public function values(array $ids): array
    {
        // One statement with one text[] parameter, as before: DQL IN (:ids) would bind a parameter per key and fail
        // past PostgreSQL's 65535-parameter limit. An empty list still runs, so an unreadable table still throws.
        $keys = '{'.implode(',', array_map(static fn($id) => '"'.addcslashes(strval($id), '"\\').'"', $ids)).'}';
        $entities = $this->entities(); // loads the Composer autoloader before any Doctrine class is named
        $mapping = (new ResultSetMapping())->addScalarResult('id', 'id')->addScalarResult('value', 'value');
        try {
            $rows = $entities->createNativeQuery('SELECT id, value FROM synth.general_settings
                WHERE id = ANY(CAST(? AS text[]))', $mapping)->setParameter(1, $keys)->getScalarResult();
        } catch (\Doctrine\DBAL\Exception|\Doctrine\DBAL\Driver\Exception $error) {
            throw new \RuntimeException('Unable to read general settings', 0, $error);
        }
        $values = [];
        foreach ($rows as $row) $values[strval($row['id'])] = strval($row['value'] ?? '');
        return $values;
    }

    private function entities(): EntityManagerInterface
    {
        return $this->entities ??= EntityManagerFactory::forSharedSession($this->db);
    }
}
