<?php declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'core'
    .DIRECTORY_SEPARATOR.'narrator.class.php';

final class NarratorRoleplayNameTest extends TestCase
{
    private array $savedGlobals = [];

    protected function setUp(): void
    {
        foreach (['SYNTH_NAME', 'NARRATOR_ROLEPLAY_NAME'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS)
                ? ['exists' => true, 'value' => $GLOBALS[$key]]
                : ['exists' => false, 'value' => null];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedGlobals as $key => $state) {
            if ($state['exists']) {
                $GLOBALS[$key] = $state['value'];
            } else {
                unset($GLOBALS[$key]);
            }
        }
    }

    public function testRoleplayNameDefaultsAndNormalizesWhitespace(): void
    {
        $this->assertSame('The Narrator', Narrator::normalizeRoleplayName(''));
        $this->assertSame('The Narrator', Narrator::normalizeRoleplayName("  \t\n"));
        $this->assertSame("Mara's Voice 2", Narrator::normalizeRoleplayName("  Mara's   Voice 2  "));
    }

    #[DataProvider('invalidRoleplayNameProvider')]
    public function testRoleplayNameRejectsUnsafeOrReservedValues(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        Narrator::normalizeRoleplayName($name);
    }

    public static function invalidRoleplayNameProvider(): array
    {
        return [
            'markup' => ['<Mercy>'],
            'routing delimiter' => ['Mercy|Voice'],
            'reserved player' => ['Player'],
            'reserved everyone' => ['everyone'],
            'too long' => [str_repeat('A', 65)],
        ];
    }

    public function testPromptNameChangesOnlyForCanonicalNarrator(): void
    {
        $GLOBALS['NARRATOR_ROLEPLAY_NAME'] = 'Mercy';
        $GLOBALS['SYNTH_NAME'] = Narrator::CANONICAL_NAME;
        $this->assertSame('Mercy', synthGetPromptCharacterName());

        $GLOBALS['SYNTH_NAME'] = 'Veronica';
        $this->assertSame('Veronica', synthGetPromptCharacterName());
        $this->assertSame('Mercy', synthGetNarratorRoleplayName());
    }

    public function testAliasRendersWithoutChangingCanonicalRouting(): void
    {
        $GLOBALS['NARRATOR_ROLEPLAY_NAME'] = 'Mercy';

        $this->assertSame(
            'Mercy watches over the player.',
            synthRenderNarratorRoleplayText('The Narrator watches over the player.')
        );
        $this->assertSame('The Narrator', synthNormalizeNarratorRoleplayActorName('Mercy'));
        $this->assertSame('Veronica', synthNormalizeNarratorRoleplayActorName('Veronica'));
    }

    public function testDisplayHeaderAndContextUseAlias(): void
    {
        $GLOBALS['NARRATOR_ROLEPLAY_NAME'] = 'The Dude';

        $this->assertSame('The Dude', base64_decode(synthGetNarratorDisplayNameHeaderValue(), true));
        $this->assertSame('The Dude: action completed', synthBuildNarratorContextLine('action completed'));
    }

    public function testContextAliasingChangesNarratorReferences(): void
    {
        $GLOBALS['NARRATOR_ROLEPLAY_NAME'] = 'Mercy';
        $context = "The Narrator: Welcome.\nLydia: They call him The Narrator. (Talking to The Narrator)\n"
            . '{"character":"The Narrator","listener":"The Narrator","message":"Ask The Narrator."}';

        $this->assertSame(
            "Mercy: Welcome.\nLydia: They call him Mercy. (Talking to Mercy)\n"
                . '{"character":"Mercy","listener":"Mercy","message":"Ask Mercy."}',
            synthRenderNarratorContextText($context)
        );

        $messages = synthApplyNarratorRoleplayNameToContext([
            ['role' => 'user', 'content' => 'The Narrator: Continue.'],
            ['role' => 'assistant', 'content' => 'Unchanged prose about The Narrator.'],
        ]);
        $this->assertSame('Mercy: Continue.', $messages[0]['content']);
        $this->assertSame('Unchanged prose about Mercy.', $messages[1]['content']);
    }
}
