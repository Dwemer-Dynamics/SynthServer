<?php

// Reads core_context_event. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Doctrine\DBAL\Exception as DbalException;
use Synth\Core\Entities\Base\AbstractContextEvent;

/** Finds rows of core_context_event. */
class ContextEventDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $rowid): ?AbstractContextEvent
    {
        return $this->fresh()->find(AbstractContextEvent::class, $rowid);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractContextEvent> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractContextEvent::class)->findBy([], ['rowid' => 'ASC'], max(1, $limit)));
    }

    /** The legacy (no native session) in-flight chat rows' delivery states that a speech row counts as live. */
    private const LIVE_CHAT = "synth_session_id IS NULL AND COALESCE(delivery_state, 'emitted') <> 'aborted'";

    /**
     * Marks legacy chat rows (no native session) whose delivery state (NULL counts as 'emitted') is one of $states as aborted;
     * only the rows carrying one of $utteranceIds when those are given. False when the statement fails.
     *
     * @param list<string> $states
     * @param list<string>|null $utteranceIds
     */
    public function abortInFlightChat(array $states, ?array $utteranceIds = null): bool
    {
        if ($states === [] || ($utteranceIds !== null && $utteranceIds === [])) {
            return true;
        }
        $sql = "UPDATE public.core_context_event SET delivery_state = 'aborted' WHERE type = 'chat' AND synth_session_id IS NULL AND COALESCE(delivery_state, 'emitted') IN ("
            .implode(', ', array_fill(0, count($states), '?')).')';
        $parameters = array_map('strval', $states);
        if ($utteranceIds !== null) {
            $sql .= ' AND utterance_id IN ('.implode(', ', array_fill(0, count($utteranceIds), '?')).')';
            array_push($parameters, ...array_map('strval', $utteranceIds));
        }
        try {
            $this->connection()->executeStatement($sql, array_values($parameters));
        } catch (DbalException $error) {
            return false;
        }

        return true;
    }

    /** Row ids of the live legacy chat rows of one utterance, newest first. @return list<int> */
    public function liveChatRowIdsForUtterance(string $utteranceId): array
    {
        return array_map('intval', $this->connection()->fetchFirstColumn(
            "SELECT rowid FROM public.core_context_event WHERE type = 'chat' AND utterance_id = ? AND ".self::LIVE_CHAT.' ORDER BY rowid DESC',
            [$utteranceId]
        ));
    }

    /** Live legacy chat rows at one gamets whose text starts with "<speaker>:", newest first (rowid, gamets, data). @return list<array<string, mixed>> */
    public function liveChatRowsBySpeakerAtGamets(int $gamets, string $speaker, int $limit = 40): array
    {
        return $this->connection()->fetchAllAssociative(
            "SELECT rowid, gamets, data FROM public.core_context_event WHERE gamets = ? AND type = 'chat' AND ".self::LIVE_CHAT
            .' AND data ILIKE ? ORDER BY ts DESC, rowid DESC LIMIT '.max(1, $limit),
            [$gamets, $speaker.':%']
        );
    }

    /** Live legacy chat rows received after $sinceLocalts whose text starts with "<speaker>:", newest first (rowid, gamets, data). @return list<array<string, mixed>> */
    public function recentLiveChatRowsBySpeaker(string $speaker, int $sinceLocalts, int $limit = 60): array
    {
        return $this->connection()->fetchAllAssociative(
            "SELECT rowid, gamets, data FROM public.core_context_event WHERE type = 'chat' AND ".self::LIVE_CHAT
            .' AND localts > ? AND data ILIKE ? ORDER BY rowid DESC LIMIT '.max(1, $limit),
            [$sinceLocalts, $speaker.':%']
        );
    }

    /** Sets the delivery state of one live legacy chat row; an aborted row stays aborted. */
    public function setLiveChatDeliveryState(int $rowid, string $state): bool
    {
        try {
            $this->connection()->executeStatement(
                'UPDATE public.core_context_event SET delivery_state = ? WHERE rowid = ? AND '.self::LIVE_CHAT,
                [$state, $rowid]
            );
        } catch (DbalException $error) {
            return false;
        }

        return true;
    }

    /** Sets the delivery state of the live legacy chat rows of one utterance. */
    public function setLiveChatDeliveryStateForUtterance(string $utteranceId, string $state): bool
    {
        try {
            $this->connection()->executeStatement(
                "UPDATE public.core_context_event SET delivery_state = ? WHERE type = 'chat' AND utterance_id = ? AND ".self::LIVE_CHAT,
                [$state, $utteranceId]
            );
        } catch (DbalException $error) {
            return false;
        }

        return true;
    }

    /** Newest live legacy chat row whose text is exactly $text (and, when given, whose people contain $speaker), or null. */
    public function latestLiveChatRowId(string $text, string $speaker = ''): ?int
    {
        $sql = "SELECT rowid FROM public.core_context_event WHERE type = 'chat' AND ".self::LIVE_CHAT.' AND data = ?';
        $parameters = [$text];
        if ($speaker !== '') {
            $sql .= " AND people ILIKE '%' || ? || '%'";
            $parameters[] = $speaker;
        }
        $rowid = $this->connection()->fetchOne($sql.' ORDER BY rowid DESC LIMIT 1', $parameters);

        return $rowid === false ? null : (int) $rowid;
    }

    /** The party column of the newest row of this type by rowid, or null. */
    public function latestPartyByRowid(string $type): ?string
    {
        $party = $this->connection()->fetchOne('SELECT party FROM public.core_context_event WHERE type = ? ORDER BY rowid DESC LIMIT 1', [$type]);

        return $party === false || $party === null ? null : (string) $party;
    }

    /** The party column of the newest row of this type by gamets then rowid, or null. */
    public function latestPartyByGamets(string $type): ?string
    {
        $party = $this->connection()->fetchOne('SELECT party FROM public.core_context_event WHERE type = ? ORDER BY gamets DESC, rowid DESC LIMIT 1', [$type]);

        return $party === false || $party === null ? null : (string) $party;
    }

    /** Gives the SYNTH rows received since $cutoffLocalts that still carry a placeholder gamets (5 or less) the real $gamets. */
    public function adoptRecentSynthGamets(int $gamets, int $cutoffLocalts): bool
    {
        try {
            $this->connection()->executeStatement(
                "UPDATE public.core_context_event SET gamets = ? WHERE sess = 'synth' AND gamets <= 5 AND localts >= ?",
                [$gamets, $cutoffLocalts]
            );
        } catch (DbalException $error) {
            return false;
        }

        return true;
    }

    /** Removes every event row (the wipe command). */
    public function deleteAll(): bool
    {
        try {
            $this->connection()->executeStatement('DELETE FROM public.core_context_event');
        } catch (DbalException $error) {
            return false;
        }

        return true;
    }

    /** Removes the chat and prechat rows received after $sinceLocalts whose text contains $needle (a literal substring, as the legacy LIKE matched it). */
    public function deleteRecentChatContaining(string $needle, int $sinceLocalts): bool
    {
        try {
            $this->connection()->executeStatement(
                "DELETE FROM public.core_context_event WHERE type IN ('chat', 'prechat') AND data LIKE ? AND localts > ?",
                ['%'.$needle.'%', $sinceLocalts]
            );
        } catch (DbalException $error) {
            return false;
        }

        return true;
    }

    /** Gamets and ts of the newest row (by rowid), or null when there are none. @return array{gamets: mixed, ts: mixed}|null */
    public function newestTimes(): ?array
    {
        $row = $this->connection()->fetchAssociative('SELECT gamets, ts FROM public.core_context_event ORDER BY rowid DESC LIMIT 1');

        return $row === false ? null : $row;
    }

    /** The text of the newest chat row by gamets, or null when there is none. */
    public function latestChatData(): ?string
    {
        $data = $this->connection()->fetchOne("SELECT data FROM public.core_context_event WHERE type = 'chat' ORDER BY gamets DESC LIMIT 1");

        return $data === false || $data === null ? null : (string) $data;
    }

    /** Whether a user_input row newer than $ts exists. */
    public function hasUserInputAfter(int|string $ts): bool
    {
        try {
            return $this->connection()->fetchOne("SELECT rowid FROM public.core_context_event WHERE type = 'user_input' AND ts > ? ORDER BY rowid DESC LIMIT 1", [$ts]) !== false;
        } catch (DbalException $error) {
            return false;
        }
    }

    /** The type of the newest row (by gamets, then ts) among the given types, or null when there is none or the read fails. @param list<string> $types */
    public function latestTypeAmong(array $types): ?string
    {
        if ($types === []) {
            return null;
        }
        try {
            $type = $this->connection()->fetchOne(
                'SELECT type FROM public.core_context_event WHERE type IN ('.implode(', ', array_fill(0, count($types), '?')).') ORDER BY gamets DESC, ts DESC LIMIT 1',
                array_map('strval', array_values($types))
            );
        } catch (DbalException $error) {
            return null;
        }

        return $type === false || $type === null ? null : (string) $type;
    }

    /** How many rechat and player-input rows are newer (by gamets) than the newest narration row; null when the read fails. */
    public function countSinceNarration(): ?int
    {
        try {
            return (int) $this->connection()->fetchOne(
                "SELECT COUNT(*) FROM public.core_context_event WHERE type IN ('rechat', 'inputtext', 'inputtext_s')
                 AND gamets > (SELECT COALESCE(MAX(gamets), 0) FROM public.core_context_event WHERE type = 'narration')"
            );
        } catch (DbalException $error) {
            return null;
        }
    }
}
