<?php declare(strict_types=1);
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/lib/quickstart_service_choices.php';

final class QuickstartServiceChoicesTest extends TestCase
{
    public static function cases(): array
    {
        return [
            [['pockettts','inworld'],'cartesia',['pockettts','inworld','cartesia'],['pockettts','inworld','cartesia']],
            [['parakeet','deepgram'],'localwhisper',['parakeet','deepgram','localwhisper'],['parakeet','deepgram','localwhisper']],
            [['parakeet','deepgram'],'deepgram',['parakeet','deepgram'],['parakeet','deepgram']],
            [['parakeet','deepgram'],'none',['parakeet','deepgram','none'],['parakeet','deepgram','none']],
            [['parakeet','deepgram'],'removed-provider',['parakeet','deepgram'],['parakeet','deepgram']],
            [['parakeet','deepgram'],'',['parakeet','deepgram'],['parakeet','deepgram']],
        ];
    }
    #[DataProvider('cases')]
    public function testConfiguredProvidersRemainSelectableWithoutAddingUnknownDrivers(array $recommended,string $active,array $supported,array $expected): void
    {
        $this->assertSame($expected,synth_quickstart_service_choices($recommended,$active,$supported));
    }
}
