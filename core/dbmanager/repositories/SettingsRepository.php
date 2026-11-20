<?php

namespace Synth\Core;

use Synth\Core\DbManager\Dao\ConfigSettingDao;
use Synth\Core\Doctrine\EntityManagerFactory;

require_once dirname(__DIR__).'/EntityManagerFactory.php';

// Stored server-wide general settings on one legacy sql session. The reads live in ConfigSettingDao; this class only binds
// that DAO to the caller's session, so each instance reads on the session it was built with.
class SettingsRepository
{
    private ?ConfigSettingDao $dao = null;

    public function __construct(private $db) {}

    /** Raw stored value, or null when the setting has no row. */
    public function value(string $id): ?string
    {
        return $this->dao()->value('general', $id);
    }

    /** Raw stored values for the named settings that have a row, in one read; throws when unreadable. */
    public function values(array $ids): array
    {
        return $this->dao()->values('general', $ids);
    }

    private function dao(): ConfigSettingDao
    {
        // Building the EntityManager loads the Composer autoloader before any Doctrine class is named.
        return $this->dao ??= ConfigSettingDao::using(EntityManagerFactory::forSharedSession($this->db));
    }
}
