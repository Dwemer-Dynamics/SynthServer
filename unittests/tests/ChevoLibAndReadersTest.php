<?php declare(strict_types=1);

require_once 'DatabaseTestCase.php';
require_once dirname(__DIR__, 2).'/lib/PlayerName.php';
require_once dirname(__DIR__, 2).'/lib/PronunciationDictionary.php';
require_once dirname(__DIR__, 2).'/managers/SaveState/HistoryRead.php';
require_once dirname(__DIR__, 2).'/managers/CharacterManager/NpcViewRead.php';

use Synth\Lib\PlayerName;
use Synth\Lib\PronunciationDictionary;
use Synth\Managers\CharacterManager\NpcViewRead;
use Synth\Managers\SaveState\HistoryRead;

// The small Synth\Lib classes and the two read-only readers behind context/history and npc/view.
final class ChevoLibAndReadersTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        PlayerName::set(null);
    }

    public function testPlayerNameSubstitutesGenericWordsOnly(): void
    {
        PlayerName::set('');
        self::assertSame('Player', PlayerName::forDisplay());
        self::assertSame('The player waves.', PlayerName::substitute('The player waves.'));
        PlayerName::set('Nate');
        self::assertSame('Nate', PlayerName::forDisplay());
        self::assertSame('Nate waves at a lute player; Nate nods.', PlayerName::substitute('The player waves at a lute player; Player nods.'));
        PlayerName::set('A$1\\B');
        self::assertSame('A$1\\B waves', PlayerName::substitute('the player waves'), 'a name is never read as a pattern');
    }

    public function testPronunciationRulesResolveBySpecificityAndApplyWholeWordsOnce(): void
    {
        $rows = [
            ['source_text' => 'radroach', 'spoken_text' => 'rad roach', 'is_builtin' => true],
            ['source_text' => 'radroach', 'spoken_text' => 'rad-roach', 'is_builtin' => false],
            ['source_text' => 'Mr. Handy', 'spoken_text' => 'mister handy', 'races' => 'Robot'],
            ['source_text' => 'Handy', 'spoken_text' => 'HANDY', 'enabled' => false],
            ['source_text' => 'a', 'spoken_text' => 'b'],
            ['source_text' => 'b', 'spoken_text' => 'c'],
        ];
        $entries = PronunciationDictionary::resolve($rows, [], 'Codsworth', 'Robot');
        self::assertSame('rad-roach', array_column($entries, 'spoken', 'source')['radroach'], 'a custom rule beats a built-in one');
        self::assertSame('rad-roach and mister handy', PronunciationDictionary::apply('Radroach and Mr. Handy', $entries));
        self::assertSame('c', PronunciationDictionary::apply('b', $entries));
        self::assertSame('b', PronunciationDictionary::apply('a', $entries), 'replaced text is not read again');
        self::assertSame('radroachs', PronunciationDictionary::apply('radroachs', $entries), 'whole words only');
        $human = PronunciationDictionary::resolve($rows, [], 'Piper', 'Human');
        self::assertSame('Mr. Handy', PronunciationDictionary::apply('Mr. Handy', $human), 'a rule scoped to another race is skipped');
    }

    public function testReadersRefuseMalformedIdsAndInactiveSessions(): void
    {
        $db = new sql();
        try {
            foreach ([[null, 's'], ['p', "bad id"], ['p', str_repeat('a', 200)]] as [$playthrough, $session]) {
                try {
                    HistoryRead::read($db, $playthrough, $session);
                    self::fail('malformed ids must be refused');
                } catch (InvalidArgumentException) {
                    self::assertTrue(true);
                }
            }
            try {
                HistoryRead::read($db, 'p', 's', 1000);
                self::fail('an over-large limit must be refused');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
            try {
                HistoryRead::read($db, 'playthrough-1', 'session-1');
                self::fail('an unknown session is not the active load');
            } catch (DomainException) {
                self::assertTrue(true);
            }
            try {
                NpcViewRead::read($db, 'playthrough-1', 'session-1', null, 1);
                self::fail('an unknown session is not the active load');
            } catch (DomainException) {
                self::assertTrue(true);
            }
            try {
                NpcViewRead::read($db, 'playthrough-1', 'session-1', 'not-a-uuid', 1);
                self::fail('a malformed runtime id must be refused');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        } finally {
            $db->close();
        }
    }
}
