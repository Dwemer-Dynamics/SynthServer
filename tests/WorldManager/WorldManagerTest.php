<?php declare(strict_types=1);

namespace Synth\Tests\WorldManager;

use PHPUnit\Framework\TestCase;
use Synth\Managers\WorldManager\WorldManager;

// The world_context payload read as text: location, worldspace, weather, the Fallout date and the day part. No database.
final class WorldManagerTest extends TestCase
{
    public function testDateAndTimeReadTheGameTimeObject(): void
    {
        $world = WorldManager::fromPayload(['game_time' => ['year' => 2287, 'month' => 10, 'day' => 23, 'hour' => 16.5]]);
        $this->assertSame('October 23, 2287', $world->date());
        $this->assertSame('4:30 PM, afternoon', $world->time());
        $this->assertSame('12:00 AM, midnight', WorldManager::fromPayload(['game_time' => ['hour' => 0]])->time());
        $this->assertSame('12:00 PM, noon', WorldManager::fromPayload(['game_time' => ['hour' => 12.0]])->time());
        $this->assertSame('', WorldManager::fromPayload(['game_time' => ['year' => 2287, 'month' => 13, 'day' => 1]])->date());
        $this->assertSame('', WorldManager::fromPayload(['game_time' => ['year' => 2287, 'month' => 1, 'day' => 0]])->date());
        $this->assertSame('', WorldManager::fromPayload(['game_time' => ['hour' => -1]])->time());
        $this->assertSame(['', ''], [WorldManager::fromPayload(null)->date(), WorldManager::fromPayload(['game_time' => 'noon'])->time()]);
    }

    public function testLocationFallsBackToTheWorldspaceAndIgnoresUnknownValues(): void
    {
        $this->assertSame('Sanctuary Hills', WorldManager::fromPayload(['location' => ' Sanctuary Hills ', 'worldspace' => 'Commonwealth'])->location());
        $this->assertSame('Commonwealth', WorldManager::fromPayload(['location' => 'Unknown Location', 'worldspace' => 'Commonwealth'])->location());
        $this->assertSame('', WorldManager::fromPayload(['location' => 'none', 'worldspace' => 'unknown'])->location());
        $this->assertSame('', WorldManager::fromPayload(null)->location());
        $this->assertSame('Far Harbor', WorldManager::fromPayload(['worldspace' => 'Far Harbor'])->worldspace());
        $this->assertTrue(WorldManager::isUnknown(null));
        $this->assertFalse(WorldManager::isUnknown('Diamond City'));
    }

    public function testPromptBlockOrdersTagsEscapesAndUsesFallbacksOnlyWhenNeeded(): void
    {
        $world = WorldManager::fromPayload(['worldspace' => 'Commonwealth', 'location' => 'Bunker <Hill>', 'weather' => 'Rain', 'game_time' => ['year' => 2287, 'month' => 10, 'day' => 23, 'hour' => 8]]);
        $this->assertSame("\n\n<world>\n  <worldspace>Commonwealth</worldspace>\n  <location>Bunker &lt;Hill&gt;</location>\n  <weather>Rain</weather>\n"
            ."  <date>October 23, 2287</date>\n  <time>8:00 AM, morning</time>\n</world>", $world->promptBlock('Ignored', 'Ignored'));
        $sparse = WorldManager::fromPayload(['worldspace' => 'Commonwealth']);
        $this->assertStringContainsString('<weather>Fog</weather>', $sparse->promptBlock('', ' Fog '));
        $this->assertSame('', WorldManager::fromPayload([])->promptBlock());
    }
}
