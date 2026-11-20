<?php declare(strict_types=1);

require_once 'DatabaseTestCase.php';
require_once dirname(__DIR__, 2).'/managers/NpcRegistration/RegistrationResult.php';
require_once dirname(__DIR__, 2).'/managers/NpcRegistration/NpcRegistration.php';

use Synth\Core\DbManager\Dao\NpcActorDao;
use Synth\Core\DbManager\Dao\NpcDao;
use Synth\Core\DbManager\Dao\NpcRuntimeActorDao;
use Synth\Core\Doctrine\EntityManagerFactory;
use Synth\Managers\NpcRegistration\NpcRegistration;
use Synth\Managers\NpcRegistration\RegistrationResult;

// Placed and runtime registration over the real DAOs: keys, refusals, rename, re-adoption, retire and post-load sync.
final class NpcRegistrationTest extends DatabaseTestCase
{
    private const ID1 = '0b9c1d2e-3f40-4a51-8b62-73c84d95e6f7';
    private const ID2 = '1c0d2e3f-4051-4b62-9c73-84d95e6f7081';
    private const ID3 = '2d1e3f40-5162-4c73-8d84-95e6f7081920';

    private function manager(sql $db, int $cap = NpcRegistration::MAX_PER_PLAYTHROUGH): NpcRegistration
    {
        $entities = EntityManagerFactory::forSharedSession($db);
        require_once dirname(__DIR__, 2).'/core/dbmanager/Database.php';
        \Synth\Core\DbManager\Database::bootWith($entities);

        return new NpcRegistration(NpcActorDao::shared(), NpcRuntimeActorDao::shared(), NpcDao::shared(), $cap);
    }

    private function runtime(string $id, string $form, string $name, array $extra = []): array
    {
        return ['playthrough_id' => 'pt-reg', 'runtime_actor_id' => $id, 'form_id' => $form, 'name' => $name] + $extra;
    }

    public function testPlacedActorsAreKeyedByPluginAndLocalIdAndRefuseRuntimePrefix(): void
    {
        $db = new sql();
        try {
            $manager = $this->manager($db);
            $row = $db->fetchOne("INSERT INTO public.core_npc (npc_name) VALUES ('Placed Reg Test') RETURNING id");
            $character = (int) $row['id'];
            $first = $manager->registerPlaced(['plugin' => 'Fallout4.ESM', 'local_id' => '1A4B2', 'name' => 'Preston', 'form_id' => '0x0001A4B2'], $character);
            $this->assertSame(RegistrationResult::REGISTERED, $first->status);
            $again = $manager->registerPlaced(['plugin' => 'fallout4.esm', 'local_id' => '01a4b2', 'name' => 'Preston Garvey'], $character + 1000);
            $this->assertSame([RegistrationResult::EXISTING, $character], [$again->status, $again->characterId], 'the key is plugin plus local id and the character never moves');
            $this->assertSame('Preston Garvey', NpcActorDao::shared()->find('01a4b2', 'fallout4.esm')->getName());
            foreach ([
                ['plugin' => 'fallout4.esm', 'local_id' => '01a4b2', 'form_id' => '0xFF01A4B2'],
                ['plugin' => 'not a plugin', 'local_id' => '01a4b2'],
                ['plugin' => 'fallout4.esm', 'local_id' => 'zz'],
                ['plugin' => 'fallout4.esm', 'local_id' => '01a4b2', 'name' => "bad\nname"],
            ] as $bad) {
                $this->assertSame('invalid_identity', $manager->registerPlaced($bad, $character)->reason);
            }
        } finally {
            $db->close();
        }
    }

    public function testRuntimeActorsRegisterRenameAndAreRefusedWhenMalformed(): void
    {
        $db = new sql();
        try {
            $manager = $this->manager($db);
            $first = $manager->registerRuntime($this->runtime(self::ID1, '0xFF001234', 'Settler', ['spawn' => ['kind' => 'workshop_settler',
                'cell' => ['plugin' => 'Fallout4.esm', 'local_id' => '0A5F60']], 'gamets' => 10]));
            $this->assertSame(RegistrationResult::REGISTERED, $first->status);
            $second = $manager->registerRuntime($this->runtime(self::ID1, 'FF001299', 'Preston Renamed'));
            $this->assertSame([RegistrationResult::EXISTING, $first->characterId], [$second->status, $second->characterId]);
            $actor = NpcRuntimeActorDao::shared()->find('pt-reg', self::ID1);
            $this->assertSame(['Preston Renamed', 'FF001299', 'workshop_settler'], [$actor->getName(), $actor->getLastFormId(), $actor->getSpawnKind()]);
            $other = $manager->registerRuntime($this->runtime(self::ID2, 'FF001300', 'Settler'));
            $this->assertNotSame($first->characterId, $other->characterId, 'two actors with one name get separate characters');
            foreach ([
                $this->runtime(self::ID3, '0x0001A4B2', 'Placed Form'),
                $this->runtime(self::ID3, 'FF001400', 'Has Plugin', ['origin_plugin' => 'fallout4.esm']),
                $this->runtime('not-a-uuid', 'FF001400', 'Bad Id'),
                $this->runtime(self::ID3, 'FF001400', 'Bad Kind', ['spawn' => ['kind' => 'dragon']]),
                $this->runtime(self::ID3, 'FF001400', ''),
                ['playthrough_id' => ''] + $this->runtime(self::ID3, 'FF001400', 'No Playthrough'),
            ] as $bad) {
                $this->assertSame('invalid_identity', $manager->registerRuntime($bad)->reason);
            }
            $this->assertNull(NpcRuntimeActorDao::shared()->find('pt-reg', self::ID3), 'a refusal stores nothing');
        } finally {
            $db->close();
        }
    }

    public function testRegistryCapRetireAndPostLoadSync(): void
    {
        $db = new sql();
        try {
            $manager = $this->manager($db, 2);
            $dao = NpcRuntimeActorDao::shared();
            $manager->registerRuntime($this->runtime(self::ID1, 'FF002001', 'A'));
            $manager->registerRuntime($this->runtime(self::ID2, 'FF002002', 'B'));
            $this->assertSame('registry_full', $manager->registerRuntime($this->runtime(self::ID3, 'FF002003', 'C'))->reason);
            $this->assertSame('batch_too_large', $manager->registerRuntimeBatch('pt-reg', array_fill(0, 33, []))[0]->reason);

            $sync = $manager->syncRuntime('pt-reg', [['runtime_actor_id' => self::ID1, 'form_id' => '0xFF002101'], ['runtime_actor_id' => self::ID3, 'form_id' => 'FF002103']], [self::ID1, self::ID2]);
            $this->assertSame(['rebound' => 1, 'not_in_save' => 1, 'ignored' => 1], $sync);
            $this->assertSame('FF002101', $dao->find('pt-reg', self::ID1)->getLastFormId());
            $this->assertSame('not_in_save', $dao->find('pt-reg', self::ID2)->getStatus());

            $this->assertFalse($manager->retireRuntime('pt-reg', self::ID1, 'dead'), 'only deleted and id_reused retire an actor');
            $this->assertFalse($manager->retireRuntime('other-pt', self::ID1, 'deleted'), 'ids are playthrough scoped');
            $this->assertTrue($manager->retireRuntime('pt-reg', self::ID1, 'deleted'));
            $this->assertSame('retired', $dao->find('pt-reg', self::ID1)->getStatus());
        } finally {
            $db->close();
        }
    }

    public function testFingerprintReAdoptionNeedsOneCandidateAndAMatchingClaimHint(): void
    {
        $db = new sql();
        try {
            $manager = $this->manager($db);
            $dao = NpcRuntimeActorDao::shared();
            $print = str_repeat('ab', 32);
            $old = $manager->registerRuntime($this->runtime(self::ID1, 'FF003001', 'Raider', ['fingerprint' => $print]));
            $dao->setStatus('pt-reg', self::ID1, 'not_in_save');

            $noHint = $manager->registerRuntime($this->runtime(self::ID2, 'FF003002', 'Raider', ['fingerprint' => $print]));
            $this->assertSame(RegistrationResult::REGISTERED, $noHint->status, 'a fingerprint alone never adopts');
            $this->assertNotSame($old->characterId, $noHint->characterId);

            $adopted = $manager->registerRuntime($this->runtime(self::ID3, 'FF003003', 'Raider', ['fingerprint' => $print, 'claim_hint' => ['last_form_id' => '0xFF003001']]));
            $this->assertSame([RegistrationResult::RE_ADOPTED, $old->characterId], [$adopted->status, $adopted->characterId]);
            $replaced = $dao->find('pt-reg', self::ID1);
            $this->assertSame(['replaced', self::ID3], [$replaced->getStatus(), $replaced->getReplacedByRuntimeActorId()]);
        } finally {
            $db->close();
        }
    }
}
