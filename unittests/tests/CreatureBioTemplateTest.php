<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'DatabaseTestCase.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'synth_runtime.php';

final class CreatureBioTemplateTest extends DatabaseTestCase
{
    private function db(): sql
    {
        if (!isset($GLOBALS['db']) || !($GLOBALS['db'] instanceof sql)) {
            $GLOBALS['db'] = new sql();
        }
        return $GLOBALS['db'];
    }

    public function testRepresentativeFallout4BaseIdentitiesResolveCreatureProfiles(): void
    {
        $cases = [
            ['Brahmin', 'Fallout4.esm|00020480', 'creature_brahmin'],
            ['Deathclaw', 'Fallout4.esm|0001DB4C', 'creature_deathclaw'],
            ['Mirelurk', 'Fallout4.esm|00024002', 'creature_mirelurk'],
            ['Mole Rat', 'Fallout4.esm|0001D966', 'creature_molerat'],
            ['Radroach', 'Fallout4.esm|0016CA44', 'creature_radroach'],
            ['Radscorpion', 'Fallout4.esm|000636AA', 'creature_radscorpion'],
            ['Radstag', 'Fallout4.esm|0008CA3C', 'creature_radstag'],
            ['Yao Guai', 'Fallout4.esm|000A0F33', 'creature_yaoguai'],
            ['Bloatfly', 'Fallout4.esm|00029469', 'creature_bloatfly'],
            ['Bloodbug', 'Fallout4.esm|001423B4', 'creature_bloodbug'],
            ['Stingwing', 'Fallout4.esm|0005FBB3', 'creature_stingwing'],
            ['Feral Ghoul', 'Fallout4.esm|000758AD', 'creature_feral_ghoul'],
            ['Feral Mongrel', 'Fallout4.esm|001324D9', 'creature_mongrel'],
            ['Mutant Hound', 'Fallout4.esm|00090C37', 'creature_mutant_hound'],
        ];

        foreach ($cases as [$name, $baseid, $expected]) {
            $template = synth_fetch_bio_template($this->db(), $name, '', $baseid);
            self::assertSame($expected, $template['npc_name'] ?? null, $name);
            self::assertTrue(boolval($template['is_nonverbal_creature'] ?? false), $name);
        }
    }

    public function testDisplayNameAloneCannotClaimNonverbalIdentity(): void
    {
        self::assertNull(synth_fetch_bio_template($this->db(), 'Deathclaw'));
        self::assertNull(synth_fetch_bio_template($this->db(), 'Dogmeat'));
        self::assertNull(synth_fetch_bio_template($this->db(), 'Protectron'));
    }

    public function testRuntimeBaseIdAndPluginResolveThroughLoadOrderIdentity(): void
    {
        $template = synth_fetch_bio_template(
            $this->db(),
            'Runtime creature',
            '',
            '0x0001DB4C',
            '',
            'Fallout4.esm'
        );
        self::assertSame('creature_deathclaw', $template['npc_name'] ?? null);
    }

    public function testCreatureTemplateCannotOverwriteLockedManualProfile(): void
    {
        $db = $this->db();
        synth_ensure_npc($db, 'Runtime Deathclaw', '0xFF001234', [
            'baseid' => 'Fallout4.esm|0001DB4C',
            'voice' => 'CreatureDeathclaw',
        ]);
        $created = $db->fetchOne("SELECT * FROM synth.core_npc_master WHERE npc_name='Runtime Deathclaw'");
        self::assertSame('Fallout4.esm|0001DB4C', $created['base'] ?? null);
        self::assertNotSame('CreatureDeathclaw', $created['voiceid'] ?? null);

        $db->execQuery("
            UPDATE synth.core_npc_master
            SET voiceid='manual_test_voice', npc_static_bio='Manual biography.', lock_profile=1,
                extended_data=jsonb_build_object('voice_metadata', jsonb_build_object('source', 'manual'))
            WHERE npc_name='Runtime Deathclaw'
        ");
        synth_ensure_npc($db, 'Runtime Deathclaw', '0xFF001234', [
            'baseid' => 'Fallout4.esm|0001DB4C',
            'voice' => 'CreatureDeathclaw',
        ]);
        $preserved = $db->fetchOne("
            SELECT voiceid, npc_static_bio, lock_profile, extended_data
            FROM synth.core_npc_master WHERE npc_name='Runtime Deathclaw'
        ");
        self::assertSame('manual_test_voice', $preserved['voiceid'] ?? null);
        self::assertSame('Manual biography.', $preserved['npc_static_bio'] ?? null);
        self::assertSame('1', strval($preserved['lock_profile'] ?? ''));
        $extended = is_array($preserved['extended_data'] ?? null)
            ? $preserved['extended_data']
            : json_decode(strval($preserved['extended_data'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('manual', $extended['voice_metadata']['source'] ?? null);
    }

    public function testFactorySeedIsAdditiveIdempotentAndTransactional(): void
    {
        $connection = pg_connect('host=localhost dbname=testdb user=dwemer password=dwemer');
        self::assertNotFalse($connection);
        $seed = file_get_contents(
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR .
            'fallout4_creature_bio_templates.sql'
        );
        self::assertNotFalse($seed);

        $snapshot = static function ($connection): array {
            $result = pg_query($connection, "
                SELECT
                    count(*) FILTER (WHERE npc_name NOT LIKE 'creature\\_%') AS ordinary_count,
                    md5(string_agg(npc_name || COALESCE(core, ''), '|' ORDER BY npc_name)
                        FILTER (WHERE npc_name NOT LIKE 'creature\\_%')) AS ordinary_hash,
                    count(*) FILTER (WHERE npc_name LIKE 'creature\\_%') AS creature_count,
                    (SELECT count(*) FROM synth.bio_template_actor_map) AS mapping_count
                FROM synth.bio_templates
            ");
            return pg_fetch_assoc($result);
        };

        $before = $snapshot($connection);
        self::assertNotFalse(pg_query($connection, $seed));
        self::assertNotFalse(pg_query($connection, $seed));
        $after = $snapshot($connection);
        self::assertSame($before, $after);
        self::assertSame('14', $after['creature_count']);
        self::assertGreaterThanOrEqual(70, intval($after['mapping_count']));

        $beforeCore = pg_fetch_result(
            pg_query($connection, "SELECT core FROM synth.bio_templates WHERE npc_name='creature_deathclaw'"),
            0,
            0
        );
        $failingSeed = str_replace(
            'COMMIT;',
            "UPDATE synth.bio_templates SET core='rollback marker' WHERE npc_name='creature_deathclaw';\nSELECT 1/0;\nCOMMIT;",
            $seed
        );
        self::assertFalse(@pg_query($connection, $failingSeed));
        pg_query($connection, 'ROLLBACK');
        $afterCore = pg_fetch_result(
            pg_query($connection, "SELECT core FROM synth.bio_templates WHERE npc_name='creature_deathclaw'"),
            0,
            0
        );
        self::assertSame($beforeCore, $afterCore);
        pg_close($connection);
    }
}
