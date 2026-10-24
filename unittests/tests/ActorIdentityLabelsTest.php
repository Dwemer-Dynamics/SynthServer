<?php
require_once __DIR__ . '/DatabaseTestCase.php';
require_once dirname(__DIR__, 2) . '/lib/synth_actor_identity_labels.php';

final class ActorIdentityLabelsTest extends DatabaseTestCase
{
    private function db(): sql { return $GLOBALS['db'] ??= new sql(); }

    private function actor(string $save, string $reference, string $name): int
    {
        $db = $this->db();
        return intval($db->fetchOne("INSERT INTO synth.actor_identities(game,playthrough_id,origin_plugin,full_form_id,display_name)
            VALUES('fo4',".$db->escapeLiteral($save).",'Fallout4.esm',".$db->escapeLiteral($reference).",".$db->escapeLiteral($name).") RETURNING id")['id']);
    }

    private function context(string $session, string $save, int $player, string $receivedAt): void
    {
        $db = $this->db();
        $sessionSql = $db->escapeLiteral($session);
        $saveSql = $db->escapeLiteral($save);
        $db->execQuery("INSERT INTO synth_meta.runtime_sessions(session_id,playthrough_id,game,runtime_variant,client_version,runtime_version,active_generation,state,updated_at)
            VALUES($sessionSql,$saveSql,'fo4','flat','test','1.11.240',1,'active',NOW())");
        $db->execQuery("INSERT INTO synth_meta.context_snapshots(session_id,generation,snapshot_sequence,playthrough_id,player_identity_id,context_payload,received_at)
            VALUES($sessionSql,1,1,$saveSql,$player,'{}',".$db->escapeLiteral($receivedAt).")");
    }

    public function testLatestAcceptedSceneOwnsLabelDespiteNewerLastSeenAndActiveSession(): void
    {
        $db = $this->db();
        $oldPlayer = $this->actor('label-save-old', '0x00000014', 'Sole Survivor');
        $latestPlayer = $this->actor('label-save-latest', '0x00000014', 'Sole Survivor');
        $oldSturges = $this->actor('label-save-old', '0x0001A4D8', 'Sturges');
        $latestSturges = $this->actor('label-save-latest', '0x0001A4D8', 'Sturges');
        $this->context('label-session-old', 'label-save-old', $oldPlayer, '2026-10-06 00:00:00+00');
        $this->context('label-session-latest', 'label-save-latest', $latestPlayer, '2026-10-07 00:00:00+00');
        $db->execQuery("UPDATE synth_meta.runtime_sessions SET updated_at='2026-10-09 00:00:00+00' WHERE session_id='label-session-old'");
        $db->execQuery("UPDATE synth.actor_identities SET last_seen_at='2026-10-09 00:00:00+00' WHERE id=$oldSturges");
        $db->execQuery("UPDATE synth.actor_identities SET last_seen_at='2026-10-01 00:00:00+00' WHERE id=$latestSturges");
        $before = $db->fetchAll('SELECT * FROM synth.actor_identities ORDER BY id');
        $db->execQuery('BEGIN READ ONLY');
        try { $labels = synthNpcActorSaveLabels($db, [$oldSturges, $latestSturges]); }
        finally { $db->execQuery('ROLLBACK'); }
        self::assertSame('other', $labels[$oldSturges]['scope']);
        self::assertSame('latest', $labels[$latestSturges]['scope']);
        self::assertStringStartsWith('Latest captured save · ', $labels[$latestSturges]['label']);
        self::assertStringContainsString('2026-10-07', $labels[$latestSturges]['detail']);
        self::assertNotSame($labels[$oldSturges]['label'], $labels[$latestSturges]['label']);
        self::assertStringNotContainsString('label-save-latest', json_encode($labels));
        self::assertSame($before, $db->fetchAll('SELECT * FROM synth.actor_identities ORDER BY id'));
        self::assertSame('2', $db->fetchOne("SELECT count(*) AS n FROM synth.actor_identities WHERE display_name='Sturges'")['n']);
    }

    public function testAbsentContextFallsBackToSaveLabelsWithoutClaimingCurrentOwnership(): void
    {
        $id = $this->actor('label-no-context', '0x0001A4D8', 'Sturges');
        $labels = synthNpcActorSaveLabels($this->db(), [$id, $id, 0, -1]);
        self::assertCount(1, $labels);
        self::assertSame('unknown', $labels[$id]['scope']);
        self::assertStringStartsWith('Save · ', $labels[$id]['label']);
        self::assertStringContainsString('No accepted game context', $labels[$id]['detail']);
        self::assertSame([], synthNpcActorSaveLabels($this->db(), []));
    }

    public function testMismatchedPlayerOwnershipCannotMarkAnActorAsLatest(): void
    {
        $player = $this->actor('label-player-save', '0x00000014', 'Sole Survivor');
        $sturges = $this->actor('label-other-save', '0x0001A4D8', 'Sturges');
        $this->context('label-mismatched-context', 'label-other-save', $player, '2026-10-07 00:00:00+00');
        $labels = synthNpcActorSaveLabels($this->db(), [$sturges]);
        self::assertSame('unknown', $labels[$sturges]['scope']);
    }
}
