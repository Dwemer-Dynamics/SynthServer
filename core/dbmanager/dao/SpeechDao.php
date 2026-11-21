<?php

// Reads core_speech. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractSpeech;
use Doctrine\DBAL\Exception as DbalException;

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

    /** The earliest line with this companion present (gamets, fallout_date, ts, localts), or null. @return array<string, mixed>|null */
    public function firstLineWithCompanion(string $name): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT gamets,convert_gamets2fallout_date(gamets) as fallout_date,ts,localts FROM core_speech where companions ilike '%'||?||'%' order by rowid asc limit 1 offset 0",
            [$name]
        );

        return $row === false ? null : $row;
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractSpeech> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractSpeech::class)->findBy([], ['rowid' => 'ASC'], max(1, $limit)));
    }

    /** The speaker of the newest core_speech row, or null when there is none. */
    public function latestSpeaker(): ?string
    {
        $speaker = $this->connection()->fetchOne('SELECT speaker FROM public.core_speech ORDER BY rowid DESC LIMIT 1');

        return $speaker === false ? null : (string) $speaker;
    }

    /** The highest gamets of a line spoken by, to or in company of this actor, or null when there is none. */
    public function lastGametsFor(string $actor): ?string
    {
        $last = $this->connection()->fetchOne(
            'SELECT max(gamets) FROM core_speech WHERE speaker = :actor OR listener = :actor OR companions LIKE :company',
            ['actor' => $actor, 'company' => '%|'.$actor.'|%']
        );

        return $last === false || $last === null ? null : (string) $last;
    }

    private const COLUMNS = ['ts', 'gamets', 'listener', 'speaker', 'speech', 'location', 'companions', 'sess', 'audios', 'utterance_id', 'topic', 'localts'];

    /**
     * Appends one spoken line (column => value over the known columns; others are refused). False when the statement fails or
     * nothing valid was given.
     *
     * @param array<string, mixed> $row
     */
    public function record(array $row): bool
    {
        $row = array_intersect_key($row, array_flip(self::COLUMNS));
        if ($row === []) {
            return false;
        }
        try {
            $this->connection()->executeStatement(
                'INSERT INTO public.core_speech ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')',
                array_values($row)
            );
        } catch (DbalException $error) {
            return false;
        }

        return true;
    }

    /** Removes every spoken line (the wipe command). */
    public function deleteAll(): bool
    {
        try {
            $this->connection()->executeStatement('DELETE FROM public.core_speech');
        } catch (DbalException $error) {
            return false;
        }

        return true;
    }

    /** The speaker of the newest spoken line, or null when there is none. */
    public function lastSpeaker(): ?string
    {
        $speaker = $this->connection()->fetchOne('SELECT speaker FROM public.core_speech ORDER BY rowid DESC LIMIT 1');

        return $speaker === false || $speaker === null ? null : (string) $speaker;
    }

    /**
     * Replaces the text of the pending player lines (speaker matching $speakerPattern, within 100 gamets of $gamets) with
     * $speech (the Auto Chat rewrite). False when the statement fails.
     */
    public function rewritePendingPlayerSpeech(string $speakerPattern, string $speech, int $gamets): bool
    {
        try {
            $this->connection()->executeStatement(
                "UPDATE public.core_speech SET speech = ? WHERE speaker ILIKE ? AND gamets >= ? - 100 AND gamets <= ? + 100 AND sess = 'pending'",
                [$speech, $speakerPattern, $gamets, $gamets]
            );
        } catch (DbalException $error) {
            return false;
        }

        return true;
    }

    /**
     * The 80 newest lines this speaker (compared case-insensitively) said after the cutoff local time: rowid, companions,
     * listener, speaker, speech, topic, localts. A failed statement reads as no rows.
     *
     * @return list<array<string, mixed>>
     */
    public function recentBySpeaker(string $speaker, int $cutoff): array
    {
        return $this->rows(
            'SELECT rowid, companions, listener, speaker, speech, topic, localts FROM core_speech
             WHERE LOWER(speaker) = LOWER(:speaker) AND localts > :cutoff ORDER BY rowid DESC LIMIT 80',
            ['speaker' => $speaker, 'cutoff' => $cutoff]
        );
    }

    /**
     * The 20 newest spatial lines after the cutoff local time in which the participant spoke, was addressed, or is listed among
     * the companions (matched as the pipe-delimited token, a LIKE match): rowid, companions, listener, speaker, topic.
     *
     * @return list<array<string, mixed>>
     */
    public function recentSpatialForParticipant(string $participant, string $pipeToken, int $cutoff): array
    {
        return $this->rows(
            "SELECT rowid, companions, listener, speaker, topic FROM core_speech
             WHERE localts > :cutoff AND topic LIKE '%spatial:%'
               AND (LOWER(speaker) = LOWER(:participant) OR LOWER(listener) = LOWER(:participant)
                    OR LOWER(companions) LIKE '%' || :token || '%')
             ORDER BY rowid DESC LIMIT 20",
            ['cutoff' => $cutoff, 'participant' => $participant, 'token' => $pipeToken]
        );
    }

    /**
     * The 1000 newest lines whose companions mention this actor (a LIKE match), with the game-time gap to the previous line:
     * speaker, speech, listener, gamets, localts, 'speech', gamets_diff, location, ts.
     *
     * @return list<array<string, mixed>>
     */
    public function expandedHistoryForActor(string $actor): array
    {
        return $this->rows(
            "SELECT speaker, speech, listener, gamets, localts, 'speech', gamets - LAG(gamets) OVER (ORDER BY gamets ASC) AS gamets_diff, location, ts
             FROM core_speech WHERE companions LIKE '%' || :actor || '%' ORDER BY ts DESC LIMIT 1000 OFFSET 0",
            ['actor' => $actor]
        );
    }

    /**
     * Every known line that involves this topic (as speaker, listener, location or companion, LIKE matches), newest first, with
     * the game date: speaker, speech, location, listener, quest, fallout_date, gamets.
     *
     * @return list<array<string, mixed>>
     */
    public function linesAboutTopic(string $topic): array
    {
        return $this->rows(
            "SELECT speaker, speech, location, listener, topic AS quest, convert_gamets2fallout_date(gamets) AS fallout_date, gamets FROM core_speech
             WHERE (speaker LIKE '%' || :topic || '%' OR listener LIKE '%' || :topic || '%' OR location LIKE '%' || :topic || '%'
                OR companions LIKE '%|' || :topic || '|%' OR companions LIKE '%' || :topic || '%' OR companions LIKE '%|' || :topic || ' (busy)|%'
                OR companions LIKE '%|' || :topic || ' (hostile)|%' OR companions LIKE '%|' || :topic || ' (restrained)|%')
               AND listener <> 'unknown' ORDER BY rowid DESC",
            ['topic' => $topic]
        );
    }

    /**
     * Reads as the legacy fetchAll did: a failed statement reads as no rows.
     *
     * @param array<string, mixed> $parameters
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $parameters): array
    {
        try {
            return $this->connection()->fetchAllAssociative($sql, $parameters);
        } catch (\Doctrine\DBAL\Exception) {
            return [];
        }
    }
}
