<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/data_functions.php';

final class NativeEquipmentBiographyTest extends TestCase
{
    private function withMetadata(array $own, array $target, callable $assertions): void
    {
        $keys = ['db', 'SYNTH_REQUEST_EVENT', 'SYNTH_PROTOCOL_REQUEST_CLAIMED', 'HOOKS',
            'DIALOGUE_TARGET', 'ITEM_BLACKLIST', 'PROMPT_CONTEXT_OPTIONS'];
        $saved = [];
        foreach ($keys as $key) {
            $saved[$key] = [array_key_exists($key, $GLOBALS), $GLOBALS[$key] ?? null];
            unset($GLOBALS[$key]);
        }
        try {
            $GLOBALS['ITEM_BLACKLIST'] = '';
            $GLOBALS['DIALOGUE_TARGET'] = 'Equipment Fixture Target';
            // The fake database supplies stale metadata only, with no live lookup.
            $fixtureDb = new class($own, $target) {
                public int $descriptionQueries = 0;
                public function __construct(public array $own, public array $target) {}
                public function escape(string $value): string { return $value; }
                public function escapeLiteral($value): string
                { return "'" . str_replace("'", "''", strval($value)) . "'"; }
                public function fetchOne(string $sql): array
                {
                    if (str_contains($sql, 'general_settings')) return [];
                    $target = str_contains($sql, "npc_name = 'Equipment Fixture Target'");
                    return ['npc_name' => $target ? 'Equipment Fixture Target' : 'Equipment Fixture NPC',
                        'race' => 'Human', 'metadata' => json_encode($target ? $this->target : $this->own),
                        'extended_data' => '{}'];
                }
                public function fetchAll(string $sql): array
                { $this->descriptionQueries++; return []; }
            };
            $GLOBALS['db'] = $fixtureDb;
            $assertions($fixtureDb);
            self::assertSame($own, $fixtureDb->own);
            self::assertSame($target, $fixtureDb->target);
        } finally {
            foreach ($saved as $key => [$existed, $value]) {
                if ($existed) $GLOBALS[$key] = $value; else unset($GLOBALS[$key]);
            }
        }
    }

    private function biography(?int $protocolVersion): string
    {
        if ($protocolVersion === null) unset($GLOBALS['SYNTH_REQUEST_EVENT']);
        else $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json' => ['protocol_version' => $protocolVersion]];
        return buildDynamicBiography(['SYNTH_NAME' => 'Equipment Fixture NPC',
            'SYNTH_APPEARANCE' => 'An authored appearance that survives equipment filtering.']);
    }

    public function testNativeTurnExcludesLegacySlotEquipmentForSpeakerAndTarget(): void
    {
        $this->withMetadata(
            ['equipment' => ['right_hand' => 'Stale Own Shotgun', 'armor' => 'Stale Own Coat']],
            ['equipment' => ['right_hand' => 'Stale Target Rifle', 'armor' => 'Stale Target Suit']],
            function ($db): void {
                foreach ([null, 1, 2, 1] as $version) {
                    $before = $db->descriptionQueries;
                    $bio = $this->biography($version);
                    self::assertStringContainsString('An authored appearance', $bio);
                    foreach (['Stale Own Shotgun', 'Stale Own Coat', 'Stale Target Rifle', 'Stale Target Suit',
                        '<equipment>', '<target_equipment>'] as $stale) {
                        if ($version === 2) self::assertStringNotContainsString($stale, $bio);
                        else self::assertStringContainsString($stale, $bio);
                    }
                    if ($version === 2) self::assertSame($before, $db->descriptionQueries,
                        'Native turns must not look up stale legacy equipment descriptions.');
                }
            }
        );
    }

    public function testNativeTurnAlsoExcludesStructuredMutableEquipment(): void
    {
        $this->withMetadata(
            ['equipment_structured' => ['weapon' => ['name' => 'Stale Structured Own Weapon'],
                'body' => ['name' => 'Stale Structured Own Clothing']]],
            ['equipment_structured' => ['weapon' => ['name' => 'Stale Structured Target Weapon'],
                'body' => ['name' => 'Stale Structured Target Clothing']]],
            function (): void {
                foreach ([1, 2, 1] as $version) {
                    $bio = $this->biography($version);
                    foreach (['Stale Structured Own Weapon', 'Stale Structured Own Clothing',
                        'Stale Structured Target Weapon', 'Stale Structured Target Clothing'] as $stale) {
                        if ($version === 2) self::assertStringNotContainsString($stale, $bio);
                        else self::assertStringContainsString($stale, $bio);
                    }
                    if ($version === 2) {
                        self::assertStringNotContainsString('currently wearing/wielding', $bio);
                        self::assertStringNotContainsString('is naked', $bio);
                        self::assertStringNotContainsString('You are naked', $bio);
                    }
                }
            }
        );
    }
}
