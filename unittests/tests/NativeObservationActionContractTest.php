<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\PreserveGlobalState;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NativeObservationActionContractTest extends TestCase
{
    private function loadMergedCatalog(int $version): array
    {
        ini_set('error_log', '/dev/null');
        $GLOBALS['PLAYER_NAME'] = 'Fixture Player';
        $GLOBALS['SYNTH_NAME'] = '(actor)';
        $GLOBALS['IS_NPC'] = true;
        $GLOBALS['BASE_FUNCTIONS'] = [];
        $event = json_decode(file_get_contents(dirname(__DIR__, 2)
            . '/protocol/v2/fixtures/valid/init-flat.json'), true, flags: JSON_THROW_ON_ERROR);
        $event['protocol_version'] = $version;
        $event['capabilities'][] = 'action.check_inventory';
        $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json' => $event];
        $GLOBALS['SYNTH_CLIENT_CAPABILITIES'] = $event['capabilities'];
        $GLOBALS['SYNTH_ACTION_CATALOG_DB_READY'] = true;
        // The existing catalog seam represents old/custom persisted rows without
        // requiring real data or allowing this focused test to write a database.
        $GLOBALS['db'] = new class {
            public function fetchOne(string $query): array { return ['has_row' => 1]; }
        };
        $rows = [];
        foreach (['Inspect' => 'Inspect', 'CheckInventory' => 'Check_Inventory'] as $code => $name) {
            $rows[$code] = [
                'code_name' => $code, 'action_name' => $name,
                'description' => $code === 'Inspect' ? 'Custom legacy equipment refresh for any actor.'
                    : 'Custom legacy inventory search for the selected player.',
                'return_message' => 'An authored return message.',
                'available_to_npc' => true, 'available_to_followers' => true,
                'available_to_narrator' => false, 'is_activated' => true, 'game_function' => true,
                'metadata' => ['builtin' => false, 'operator_note' => 'preserve'],
                'script_proxy_program' => null,
                'parameters_json' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => ['target' => ['type' => 'string', 'minLength' => 0, 'maxLength' => 255,
                        'description' => 'An authored legacy actor selector.']],
                    'required' => []],
            ];
        }
        $GLOBALS['SYNTH_ACTION_CATALOG_ROWS_BY_CODE'] = $rows;
        require_once dirname(__DIR__, 2) . '/lib/logger.php';
        restore_error_handler();
        require_once dirname(__DIR__, 2) . '/lib/synth_protocol_state.php';
        require_once dirname(__DIR__, 2) . '/functions/functions.php';
        return $rows;
    }

    public function testNativeMergedDefinitionsClarifyObservationWithoutChangingPersistedRows(): void
    {
        $rows = $this->loadMergedCatalog(2);
        foreach (['Inspect', 'CheckInventory'] as $code) {
            $definition = findFunctionByName($code);
            self::assertIsArray($definition);
            self::assertSame($definition, $GLOBALS['BASE_FUNCTIONS'][$code]);
            self::assertSame($definition['description'], $GLOBALS['F_DESCRIPTIONS'][$code]);
            self::assertStringContainsString('Use Talk to describe visible gear already captured', $definition['description']);
            $parameters = $definition['parameters'];
            $parameters['properties']['target']['description'] = $rows[$code]['parameters_json']['properties']['target']['description'];
            self::assertSame($rows[$code]['parameters_json'], $parameters,
                'Types, limits, required fields and additionalProperties must remain unchanged.');
        }
        $inspect = findFunctionByName('Inspect');
        self::assertStringContainsString('identity, condition and state', $inspect['description']);
        self::assertStringContainsString('does not refresh weapon or clothing observations', $inspect['description']);
        $inventory = findFunctionByName('CheckInventory');
        self::assertStringContainsString('acting NPC\'s own captured inventory', $inventory['description']);
        self::assertStringContainsString('not a player or actor selector', $inventory['description']);
        self::assertStringContainsString('not an actor/player selector', $inventory['parameters']['properties']['target']['description']);
        self::assertSame($rows, $GLOBALS['SYNTH_ACTION_CATALOG_ROWS_BY_CODE']);
        self::assertSame(['Inspect', 'CheckInventory'], $GLOBALS['ENABLED_FUNCTIONS']);
        // A later lookup must not restore a removed/unnegotiated native tool
        // merely because its persisted catalog row remains active.
        $GLOBALS['FUNCTIONS'] = [];
        self::assertNull(findFunctionByName('Inspect'));
        self::assertNull(findFunctionByName('CheckInventory'));
        self::assertSame($rows, $GLOBALS['SYNTH_ACTION_CATALOG_ROWS_BY_CODE']);
    }

    public function testLegacyMergedAuthoredDefinitionsRemainUnchanged(): void
    {
        $rows = $this->loadMergedCatalog(1);
        foreach (['Inspect', 'CheckInventory'] as $code) {
            $definition = findFunctionByName($code);
            self::assertSame($rows[$code]['description'], $definition['description']);
            self::assertSame($rows[$code]['parameters_json'], $definition['parameters']);
            self::assertSame($definition['description'], $GLOBALS['F_DESCRIPTIONS'][$code]);
        }
        self::assertSame($rows, $GLOBALS['SYNTH_ACTION_CATALOG_ROWS_BY_CODE']);
    }

    public function testDefinitionProjectionIsStrictlyNativeAndPreservesAllOtherFields(): void
    {
        $this->loadMergedCatalog(2);
        $definition = ['name' => 'Check_Inventory', 'description' => 'An authored description.',
            'parameters' => ['type' => 'object', 'properties' => ['target' => ['type' => 'string']],
                'required' => ['target']], 'operator_extension' => ['keep' => true]];
        $copy = $definition;
        foreach ([1, '2', null, false, 3] as $version) {
            self::assertSame($definition, synthNativeObservationActionDefinition('CheckInventory', $definition, $version));
        }
        self::assertSame($definition, synthNativeObservationActionDefinition('EquipItem', $definition, 2));
        $native = synthNativeObservationActionDefinition('CheckInventory', $definition, 2);
        self::assertSame($definition['operator_extension'], $native['operator_extension']);
        self::assertSame($definition['parameters']['required'], $native['parameters']['required']);
        self::assertSame($copy, $definition);
        $missingProperty = ['name' => 'Check_Inventory', 'parameters' => ['type' => 'object', 'properties' => []]];
        $native = synthNativeObservationActionDefinition('CheckInventory', $missingProperty, 2);
        self::assertSame($missingProperty['parameters'], $native['parameters']);
    }
}
