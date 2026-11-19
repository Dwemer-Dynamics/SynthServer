<?php declare(strict_types=1);

require_once 'DatabaseTestCase.php';

use Synth\Core\Doctrine\EntityManagerFactory;

// Every mapped entity must match the live schema: each mapped column exists, and each mapped table is read without error.
// This is the drift guard for the entity layer; a column renamed in the baseline fails here before a page does.
final class EntityMappingTest extends DatabaseTestCase
{
    public function testEveryMappedEntityColumnExistsInTheDatabase(): void
    {
        $db = new sql();
        try {
            $entities = EntityManagerFactory::forSharedSession($db);
            require_once dirname(__DIR__, 2).'/core/dbmanager/Database.php';
            \Synth\Core\DbManager\Database::bootWith($entities);
            $connection = $entities->getConnection();
            $names = $entities->getConfiguration()->getMetadataDriverImpl()->getAllClassNames();
            $this->assertNotEmpty($names);
            foreach ($names as $class) {
                $metadata = $entities->getClassMetadata($class);
                $columns = array_map(static fn (string $field): string => $metadata->getColumnName($field), $metadata->getFieldNames());
                $table = $metadata->getTableName();
                $schema = $metadata->getSchemaName() ?: 'public';
                $found = $connection->fetchFirstColumn(
                    'SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ?',
                    [$schema, $table]
                );
                $this->assertNotEmpty($found, "$class maps $schema.$table, which does not exist");
                $this->assertSame([], array_values(array_diff($columns, $found)), "$class maps columns $schema.$table does not have");
            }
        } finally {
            $db->close();
        }
    }

    public function testConnectorAndProfileDaosReadStoredRows(): void
    {
        $db = new sql();
        try {
            $entities = EntityManagerFactory::forSharedSession($db);
            require_once dirname(__DIR__, 2).'/core/dbmanager/Database.php';
            \Synth\Core\DbManager\Database::bootWith($entities);
            require_once dirname(__DIR__, 2).'/core/entities/system/LlmConnector.php';
            require_once dirname(__DIR__, 2).'/core/entities/system/Profile.php';
            $key = '0b9c1d2e-3f40-4a51-8b62-73c84d95e6f7';
            $llm = $db->fetchOne("INSERT INTO public.core_llm_connector (label, driver, model, temperature, top_k, metadata, api_key_id)
                VALUES ('Test model', 'openai_compatible', 'm-1', 0.7, 40, '{\"a\":1}', '$key') RETURNING id");
            $profile = $db->fetchOne("INSERT INTO public.core_profiles (label, llm_primary_id, slot) VALUES ('Test profile', {$llm['id']}, 3) RETURNING id");
            $dao = \Synth\Core\DbManager\Dao\LlmConnectorDao::shared();
            $row = \Synth\Core\Entities\System\LlmConnector::fromMapped($dao->find((int) $llm['id']))->toArray();
            $this->assertSame(['Test model', 'openai_compatible', 'm-1', 40, ['a' => 1], $key], [$row['label'], $row['driver'], $row['model'], $row['top_k'], $row['metadata'], $row['api_key_id']]);
            $this->assertEquals(0.7, $row['temperature']);
            $this->assertNull($row['local_api_key_id']);
            $this->assertNotEmpty($dao->findAll());
            $this->assertNull($dao->find(999999));
            $stored = \Synth\Core\Entities\System\Profile::fromMapped(\Synth\Core\DbManager\Dao\ProfileDao::shared()->find((int) $profile['id']))->toArray();
            $this->assertSame(['Test profile', (int) $llm['id'], 3], [$stored['label'], $stored['llm_primary_id'], $stored['slot']]);
            foreach (['Tts', 'Stt', 'Itt'] as $kind) {
                $dao = ('Synth\\Core\\DbManager\\Dao\\'.$kind.'ConnectorDao')::shared();
                $this->assertIsArray($dao->findAll());
            }
        } finally {
            $db->close();
        }
    }
}
