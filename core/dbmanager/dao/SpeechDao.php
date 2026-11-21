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

    /** Rows ordered by primary key, at most $limit. @return list<AbstractSpeech> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractSpeech::class)->findBy([], ['rowid' => 'ASC'], max(1, $limit)));
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
