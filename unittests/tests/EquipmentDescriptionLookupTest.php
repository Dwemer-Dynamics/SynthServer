<?php
require_once __DIR__ . '/DatabaseTestCase.php';
require_once dirname(__DIR__, 2) . '/lib/data_functions.php';

final class EquipmentDescriptionLookupTest extends DatabaseTestCase
{
    private function db(): sql { return $GLOBALS['db'] ??= new sql(); }

    private function description(string $table, string $plugin, string $base, string $description): void
    {
        if (!in_array($table, ['descriptions', 'descriptions_custom'], true)) throw new LogicException('Invalid fixture table');
        $db = $this->db();
        $db->execQuery("INSERT INTO synth.$table(plugin,baseid,name,description) VALUES($1,$2,'Equipment fixture',$3)
            ON CONFLICT(plugin,baseid) DO UPDATE SET description=EXCLUDED.description", [$plugin, $base, $description]);
    }

    public function testStableObservedPluginAndLocalIdKeepCustomPrecedenceWithoutWrites(): void
    {
        $db = $this->db();
        $this->description('descriptions', 'EquipmentExact.esp', '00004822', 'Factory pistol');
        $this->description('descriptions_custom', 'EquipmentExact.esp', '00004822', 'Edited pistol');
        $this->description('descriptions_custom', 'DifferentEquipment.esp', '00004822', 'Different mod weapon');
        $before = $db->fetchAll("SELECT * FROM synth.descriptions_custom ORDER BY plugin,baseid");
        $db->execQuery('BEGIN READ ONLY');
        try {
            self::assertSame('Edited pistol', synthLookupCapturedEquipmentDescription('0x02004822', 'EquipmentExact.esp'));
            self::assertSame('Different mod weapon', synthLookupCapturedEquipmentDescription('0x03004822', 'DifferentEquipment.esp'));
            self::assertNull(synthLookupCapturedEquipmentDescription('0x02004822', 'MissingEquipment.esp'));
        } finally { $db->execQuery('ROLLBACK'); }
        self::assertSame($before, $db->fetchAll("SELECT * FROM synth.descriptions_custom ORDER BY plugin,baseid"));
    }

    public function testUnscopedWildcardAndCurrentLoadedRuntimeIdsCannotFillMissingStableDescription(): void
    {
        $db = $this->db();
        $db->execQuery("INSERT INTO synth.game_plugins(plugin_name,formid_prefix) VALUES('EquipmentFallback.esp','03')
            ON CONFLICT(plugin_name) DO UPDATE SET formid_prefix=EXCLUDED.formid_prefix");
        $this->description('descriptions_custom', '', '02004822', 'Bare observed runtime-ID guess');
        $this->description('descriptions_custom', '', 'XX004822', 'Wildcard guess');
        $this->description('descriptions_custom', 'EquipmentFallback.esp', '03004822', 'Current loaded-map guess');
        $this->description('descriptions_custom', 'Fallout4.esm', '00004822', 'Same local-ID in another plugin');
        self::assertNull(synthLookupCapturedEquipmentDescription('0x02004822', 'EquipmentFallback.esp'));
        $this->description('descriptions', 'EquipmentFallback.esp', '00004822', 'Exact observed plugin/local item');
        self::assertSame('Exact observed plugin/local item', synthLookupCapturedEquipmentDescription('0x02004822', 'EquipmentFallback.esp'));
    }

    public function testLightPluginUsesOnlyTheCapturedTwelveBitLocalId(): void
    {
        $this->description('descriptions', 'EquipmentLight.esl', '00000822', 'Light plugin fixture');
        $this->description('descriptions_custom', 'EquipmentLight.esl', '00ABC822', 'Incorrect full tail');
        self::assertSame('Light plugin fixture', synthLookupCapturedEquipmentDescription('0xFEABC822', 'EquipmentLight.esl'));
        self::assertSame('Light plugin fixture', synthLookupCapturedEquipmentDescription('0xFE007822', 'EquipmentLight.esl'));
    }

    public function testDynamicMalformedAndPathLikeIdentitiesNeverReturnCatalogText(): void
    {
        $this->description('descriptions_custom', 'EquipmentBoundary.esp', '00000822', 'Valid fixture description');
        foreach (['0xFF000822', '0xff000822', '0x00000000', '00000822', '0x822', '0x00000822x', "0x00000822\n"] as $form) {
            self::assertNull(synthLookupCapturedEquipmentDescription($form, 'EquipmentBoundary.esp'), $form);
        }
        foreach (['folder/EquipmentBoundary.esp', 'folder\\EquipmentBoundary.esp', 'EquipmentBoundary', ' EquipmentBoundary.esp', 'EquipmentBoundary.esp ', 'EquipmentBoundary.esp|Fallout4.esm'] as $plugin) {
            $this->description('descriptions_custom', $plugin, '00000822', 'Malformed catalog row');
            self::assertNull(synthLookupCapturedEquipmentDescription('0x00000822', $plugin), $plugin);
        }
        foreach (["EquipmentBoundary.esp\0", "EquipmentBoundary.esp\n", "EquipmentBoundary\xff.esp", str_repeat('a', 256).'.esp', ''] as $plugin) {
            self::assertNull(synthLookupCapturedEquipmentDescription('0x00000822', $plugin));
        }
        self::assertSame('Valid fixture description', synthLookupCapturedEquipmentDescription('0x00000822', 'EquipmentBoundary.esp'));
    }
}
