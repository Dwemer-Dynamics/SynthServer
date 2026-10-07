<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'data_functions.php';

final class InventoryPromptContextTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ITEM_BLACKLIST'] = '';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['ITEM_BLACKLIST']);
    }

    public function testInventoryMarkdownIncludesNormalizedIdsAndFalloutMetadata(): void
    {
        $described = [];
        $lines = synthFormatInventoryPromptLines([
            [
                'name' => '9mm Pistol',
                'baseid' => 'e3778',
                'count' => 1,
                'equipped' => true,
                'condition' => 0.83,
                'ammo' => '9mm Round',
                'mods' => ['Extended Mags'],
            ],
        ], static fn(string $name, ?string $baseid): ?string => 'A compact semi-automatic handgun.', $described);

        $this->assertSame(
            '- `0x000E3778:9mm Pistol` (1) [equipped; condition 83%; ammo 9mm Round; mods Extended Mags] - A compact semi-automatic handgun.',
            $lines[0]
        );
        $this->assertSame(['0x000E3778'], $described);
    }

    public function testNativeBiographyDoesNotReintroduceMutableLegacyInventory(): void
    {
        $keys = ['db', 'SYNTH_REQUEST_EVENT', 'HOOKS', 'DIALOGUE_TARGET', 'INVENTORY_ITEMS_DESCRIPTIONS_ONLY'];
        $saved = [];
        foreach ($keys as $key) { $saved[$key] = $GLOBALS[$key] ?? null; unset($GLOBALS[$key]); }
        try {
            $GLOBALS['db'] = new class {
                public function escape(string $value): string { return $value; }
                public function escapeLiteral($value): string { return "'".str_replace("'", "''", strval($value))."'"; }
                public function fetchOne(string $sql): array
                {
                    return ['metadata' => json_encode(['inventory' => [['name' => 'Stale Legacy Jet', 'count' => 7]]]), 'extended_data' => '{}'];
                }
                public function fetchAll(string $sql): array { throw new LogicException('Inventory must not fetch descriptions for this probe'); }
            };
            foreach ([1, 2, 1] as $version) {
                $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json' => ['protocol_version' => $version]];
                $bio = buildDynamicBiography(['SYNTH_NAME' => 'Inventory Test NPC']);
                if ($version === 2) $this->assertStringNotContainsString('Stale Legacy Jet', $bio);
                else $this->assertStringContainsString('Stale Legacy Jet', $bio);
            }
        } finally {
            foreach ($saved as $key => $value) {
                if ($value === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $value;
            }
        }
    }

    public function testInventoryContextUsesDocumentedBaseIdFormat(): void
    {
        $described = [];
        $context = synthBuildInventoryPromptContext([
            ['name' => 'Leather Armor', 'baseid' => '20420', 'count' => 1],
        ], null, $described);

        $this->assertSame(
            "<inventory>\n# INVENTORY\nFormat: BaseID:ItemName (quantity)\n\n- `0x00020420:Leather Armor` (1)\n</inventory>",
            $context
        );
    }

    public function testInventoryEscapesPromptSyntaxAndSupportsDescriptionFilter(): void
    {
        $described = [];
        $lines = synthFormatInventoryPromptLines([
            ['name' => 'Odd `<Relic> & Thing', 'count' => 2],
            ['name' => '9mm Round', 'baseid' => '8ed03', 'count' => 20],
        ], static fn(string $name, ?string $baseid): ?string => null, $described, false);

        $this->assertSame('- Odd &#96;&lt;Relic&gt; &amp; Thing (2)', $lines[0]);
        $this->assertSame('- `0x0008ED03:9mm Round` (20)', $lines[1]);

        $described = [];
        $this->assertSame([], synthFormatInventoryPromptLines([
            ['name' => '9mm Round', 'baseid' => '8ed03', 'count' => 20],
        ], static fn(string $name, ?string $baseid): ?string => null, $described, true));
    }

    public function testInventoryDescriptionIsDeduplicatedAgainstEquipmentId(): void
    {
        $descriptionCalls = 0;
        $described = ['00020420'];
        $lines = synthFormatInventoryPromptLines([
            ['name' => 'Leather Armor', 'baseid' => '0x00020420', 'count' => 1],
        ], static function (string $name, ?string $baseid) use (&$descriptionCalls): ?string {
            $descriptionCalls++;
            return 'Should not be repeated.';
        }, $described);

        $this->assertSame(0, $descriptionCalls);
        $this->assertSame('- `0x00020420:Leather Armor` (1)', $lines[0]);
    }
}
