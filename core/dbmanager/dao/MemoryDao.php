<?php

// Reads core_memory. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMemory;

/** Finds rows of core_memory. */
final class MemoryDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int $rowid): ?AbstractMemory
    {
        return $this->fresh()->find(AbstractMemory::class, $rowid);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMemory> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMemory::class)->findBy([], ['rowid' => 'ASC'], max(1, $limit)));
    }

    /** Packs diary and auto-diary log lines that have no summary row yet into global diary summaries. False when the statement failed. */
    public function packMissingDiaries(): bool
    {
        return $this->run("insert into core_memory (gamets_truncated,n,packed_message,summary,classifier,uid,companions,scope)
        select gamets,1,message,message,'diary',uid,
            case
                when nullif(trim(speaker), '') is null then ''
                else '|' || trim(both '|' from trim(speaker)) || '|'
            end,
            'global'
        from core_memory_log
        where event in ('diary','auto_diary')
        and uid not in (select uid from core_memory where source_type='summary' AND classifier in  ('diary','auto_diary'))");
    }

    /** Packs diary log lines newer than this game time into global diary summaries. False when the statement failed. */
    public function packDiariesAfter(int $maxRow): bool
    {
        return $this->run("insert into core_memory (gamets_truncated,n,packed_message,summary,classifier,uid,companions,scope)
                                    select gamets,1,message,message,'diary',uid,
                                        case
                                            when nullif(trim(speaker), '') is null then ''
                                            else '|' || trim(both '|' from trim(speaker)) || '|'
                                        end,
                                        'global'
                                    from core_memory_log
                                    where event='diary'
                                    and gamets>$maxRow
                                ");
    }

    /**
     * Packs the dialogue rows newer than $maxRow and older than $minRowTs into scene summaries: queues are cut at location changes and
     * time buckets of $pfi game-time units, and a queue needs $minEventsPerSummary rows. False when the statement failed.
     */
    public function packDialogueSummaries(int $maxRow, int $minRowTs, int $pfi, int $minEventsPerSummary): bool
    {
        $query="insert into core_memory (gamets_truncated,n,packed_message,summary,classifier,uid,scope,companions)
                                with source_rows as (
                                    select
                                        uid,
                                        gamets,
                                        coalesce(ts, 0) as ts,
                                        message,
                                        speaker,
                                        listener,
                                        round(gamets::numeric/$pfi, 0) as time_bucket,
                                        trim(regexp_replace(coalesce(
                                            substring(message from '(?i)\\(context\\s+(?:new\\s+)?location:\\s*([^,\\)]+)'),
                                            substring(message from '(?i)\\(at\\s+([^\\)]+)\\)'),
                                            ''
                                        ), '\\s+', ' ', 'g')) as location_key
                                    from memory_v
                                    where message not ilike 'Dear Diary%'
                                      and gamets>$maxRow
                                ),
                                location_grouped_rows as (
                                    select
                                        *,
                                        count(location_key) filter (where location_key <> '') over (
                                            order by gamets asc, ts asc, uid asc
                                            rows between unbounded preceding and current row
                                        ) as location_group
                                    from source_rows
                                ),
                                normalized_rows as (
                                    select
                                        uid,
                                        gamets,
                                        ts,
                                        message,
                                        speaker,
                                        listener,
                                        time_bucket,
                                        case
                                            when location_group=0 then null
                                            else lower(max(nullif(location_key, '')) over (partition by location_group))
                                        end as location_key
                                    from location_grouped_rows
                                ),
                                queue_boundaries as (
                                    select
                                        uid,
                                        gamets,
                                        ts,
                                        message,
                                        speaker,
                                        listener,
                                        time_bucket,
                                        location_key,
                                        lag(location_key) over (order by gamets asc, ts asc, uid asc) as prev_location_key,
                                        lag(time_bucket) over (order by gamets asc, ts asc, uid asc) as prev_time_bucket
                                    from normalized_rows
                                ),
                                queued_rows as (
                                    select
                                        uid,
                                        gamets,
                                        ts,
                                        message,
                                        speaker,
                                        listener,
                                        case
                                            when prev_time_bucket is null then 1
                                            when location_key is null then 1
                                            when prev_location_key is null then 1
                                            when location_key<>prev_location_key then 1
                                            when time_bucket<>prev_time_bucket then 1
                                            else 0
                                        end as is_new_queue
                                    from queue_boundaries
                                ),
                                grouped_rows as (
                                    select
                                        uid,
                                        gamets,
                                        ts,
                                        message,
                                        speaker,
                                        listener,
                                        sum(is_new_queue) over (
                                            order by gamets asc, ts asc, uid asc
                                            rows between unbounded preceding and current row
                                        ) as queue_id
                                    from queued_rows
                                ),
                                grouped_summaries as (
                                    select
                                        queue_id,
                                        max(gamets) as gamets_truncated,
                                        count(*) as n,
                                        STRING_AGG(message, chr(13) || chr(10) || chr(13) || chr(10) order by gamets asc, ts asc, uid asc) AS packed_message,
                                        NULL as summary,
                                        'dialogue' as classifier,
                                        max(uid) as uid,
                                        'global' as scope
                                    from grouped_rows
                                    group by queue_id
                                    having count(*)>=$minEventsPerSummary
                                ),
                                queue_participants as (
                                    select queue_id, '|' || string_agg(name, '|' order by name) || '|' as companions
                                    from (
                                        select distinct
                                            queue_id,
                                            trim(regexp_replace(person, '\\s*\\([^)]*\\)\\s*$', '', 'g')) as name
                                        from grouped_rows
                                        cross join lateral unnest(array[speaker, listener]) as participant(person)
                                        where lower(trim(person)) not in ('', '-', '--', 'unknown', 'the narrator')
                                    ) participant_names
                                    where name <> ''
                                    group by queue_id
                                )
                                select * from (
                                    select
                                        summaries.gamets_truncated,
                                        summaries.n,
                                        summaries.packed_message,
                                        summaries.summary,
                                        summaries.classifier,
                                        summaries.uid,
                                        summaries.scope,
                                        participants.companions
                                    from grouped_summaries summaries
                                    left join queue_participants participants on participants.queue_id=summaries.queue_id
                                    order by summaries.gamets_truncated asc
                                ) as T
                                where gamets_truncated>$maxRow and gamets_truncated<$minRowTs";

        return $this->run($query);
    }

    /** Runs one packing statement; its failure is reported, never thrown, as the legacy query() did. */
    private function run(string $sql): bool
    {
        try {
            $this->connection()->executeStatement($sql);
        } catch (\Doctrine\DBAL\Exception) {
            return false;
        }

        return true;
    }

    /**
     * The game timestamp of the newest summary row, as a one-row list (empty when there is no summary): gamets_truncated.
     *
     * @return list<array<string, mixed>>
     */
    public function lastSummaryGameTimestamp(): array
    {
        try {
            return $this->connection()->fetchAllAssociative("SELECT gamets_truncated FROM core_memory WHERE source_type = 'summary' ORDER BY gamets_truncated DESC LIMIT 1");
        } catch (\Doctrine\DBAL\Exception) {
            return [];
        }
    }
}
