<?php declare(strict_types=1);

require_once 'DatabaseTestCase.php';

use Synth\Core\Doctrine\EntityManagerFactory;

// Every DAO registered in Database::DAOS boots on the shared session and reads its table; a stored row hydrates through the
// mapping with its real column types.
final class DaoCoverageTest extends DatabaseTestCase
{
    private function boot(sql $db): void
    {
        $entities = EntityManagerFactory::forSharedSession($db);
        require_once dirname(__DIR__, 2).'/core/dbmanager/Database.php';
        \Synth\Core\DbManager\Database::bootWith($entities);
    }

    public function testEveryRegisteredDaoBootsAndReadsItsTable(): void
    {
        $db = new sql();
        try {
            $this->boot($db);
            $read = 0;
            foreach (\Synth\Core\DbManager\Database::DAOS as $class) {
                $dao = $class::shared();
                $this->assertInstanceOf($class, $dao);
                if (method_exists($dao, 'findAll')) {
                    $this->assertIsArray($dao->findAll(5), $class);
                    $read++;
                }
            }
            $this->assertGreaterThan(10, $read, 'most DAOs expose findAll()');
        } finally {
            $db->close();
        }
    }

    public function testNpcRowsHydrateWithTheirColumnTypes(): void
    {
        $db = new sql();
        try {
            $this->boot($db);
            $row = $db->fetchOne("INSERT INTO public.core_npc (npc_name, extended_data, gamets_last_updated, lock_profile, voiceid)
                VALUES ('Dao Test', '{\"a\":1}', 12.5, 1, 'voice') RETURNING id");
            $db->execQuery("INSERT INTO public.core_npc_history (npc_id, npc_name, created) VALUES ({$row['id']}, 'Dao Test', now())");
            $npc = \Synth\Core\Entities\System\Npc::fromMapped(\Synth\Core\DbManager\Dao\NpcDao::shared()->find((int) $row['id']))->toArray();
            $this->assertSame(['Dao Test', 1, 'voice', ['a' => 1]], [$npc['npc_name'], $npc['lock_profile'], $npc['voiceid'], $npc['extended_data']]);
            $this->assertEquals(12.5, $npc['gamets_last_updated']);
            $this->assertNull(\Synth\Core\DbManager\Dao\NpcDao::shared()->find(999999));
            $history = \Synth\Core\DbManager\Dao\NpcHistoryDao::shared()->findAll(10);
            $this->assertNotEmpty($history);
            $this->assertSame('Dao Test', $history[0]->toArray()['npc_name']);
        } finally {
            $db->close();
        }
    }
}
