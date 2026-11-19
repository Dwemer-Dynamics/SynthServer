<?php declare(strict_types=1);

namespace Synth\Tests\ListenerManager;

use PHPUnit\Framework\TestCase;
use Synth\Managers\ListenerManager\ListenerRouter;

final class ListenerRouterTest extends TestCase
{
    public function testParseSplitsLegacySeparators(): void
    {
        $this->assertSame(['Piper', 'Nick', 'Preston'], ListenerRouter::parse(' Piper, Nick and Preston '));
        $this->assertSame(['Piper', 'Nick'], ListenerRouter::parse('Piper & Nick & Piper'));
        $this->assertSame([], ListenerRouter::parse('  '));
    }

    public function testPickClampsToLastListener(): void
    {
        $this->assertSame('Piper', ListenerRouter::pick(['Piper', 'Nick'], 0));
        $this->assertSame('Nick', ListenerRouter::pick(['Piper', 'Nick'], 5));
        $this->assertNull(ListenerRouter::pick([], 0));
    }
}
