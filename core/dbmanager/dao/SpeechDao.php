<?php

// Reads core_speech. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractSpeech;

/** Finds rows of core_speech. */
final class SpeechDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $rowid): ?AbstractSpeech
    {
        return $this->fresh()->find(AbstractSpeech::class, $rowid);
    }

    /**
     * Counts the lines between two names. The gamets window applies to the listener-to-speaker direction alone, because the original
     * statement's AND binds tighter than its OR; kept exactly as it was.
     */
    public function countDirectConversations(string $speaker, string $listener, int|string $beforeGamets): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT count(*) as N FROM core_speech WHERE (speaker=? and listener=?) OR (listener=? and speaker=? and gamets<?)',
            [$speaker, $listener, $speaker, $listener, $beforeGamets]
        );
    }

    /** The newest in-game time of a line between two names (either direction), or 0. */
    public function lastInteractionGamets(string $a, string $b): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT gamets FROM core_speech
        WHERE (gamets > 0) AND
          ((speaker = ? AND listener = ?) OR
          (listener = ? AND speaker = ?))
        ORDER BY gamets DESC LIMIT 1',
            [$a, $b, $a, $b]
        );
    }

    /** The oldest in-game time of a line between two names (either direction), or 0. */
    public function firstInteractionGamets(string $a, string $b): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT gamets FROM core_speech
        WHERE (gamets > 0) AND
          ((speaker = ? AND listener = ?) OR
          (listener = ? AND speaker = ?))
        ORDER BY gamets ASC LIMIT 1',
            [$a, $b, $a, $b]
        );
    }

    /** The newest in-game time of any spoken line, or 0. */
    public function latestGamets(): int
    {
        return (int) $this->connection()->fetchOne('SELECT gamets as gamets FROM core_speech WHERE (gamets > 0) ORDER BY gamets DESC LIMIT 1');
    }

    /** The last five lines of one speaker or the player (newest first, case-insensitive match on the names). @return list<array<string, mixed>> */
    public function lastLinesBySpeaker(string $speaker, string $player): array
    {
        return $this->connection()->fetchAllAssociative(
            "SELECT * from core_speech where (speaker ilike ? or speaker ilike '%'||?||'%' ) order by rowid desc LIMIT 5 OFFSET 0",
            [$speaker, $player]
        );
    }

    /** Lines after $afterGamets by one speaker or the player, newest first. @return list<array<string, mixed>> */
    public function recentLinesSince(int|string $afterGamets, string $speaker, string $player, int $limit): array
    {
        return $this->connection()->fetchAllAssociative(
            "SELECT speaker, location, companions, speech, gamets
     from (select * from core_speech where  gamets>?) AS sp
     where ((speaker ilike ?) or (speaker ilike '%'||?||'%' ))
        order by gamets desc limit {$limit} offset 0",
            [$afterGamets, $speaker, $player]
        );
    }

    /** The newest $limit lines (speaker, location, companions, speech). @return list<array<string, mixed>> */
    public function newestLines(int $limit): array
    {
        return $this->connection()->fetchAllAssociative("SELECT speaker,location,companions,speech from core_speech order by gamets desc limit {$limit} offset 0");
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractSpeech> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractSpeech::class)->findBy([], ['rowid' => 'ASC'], max(1, $limit)));
    }
}
