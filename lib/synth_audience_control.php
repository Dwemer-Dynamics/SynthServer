<?php

// Session audience preference (normal/everyone) and the global gate that orders it against mode writes.
// Every function runs inside a caller-owned READ COMMITTED transaction on one raw connection; nothing here
// connects, reconnects, begins, commits or rolls back. A failed statement aborts the caller's transaction.
//
// Lock order: audience gate, then the session advisory lock (hashtext(session_id)), then runtime_sessions
// rows. Everyone admission and audience reads/writes take the gate shared; global mode writers take it
// exclusive, so a mode change and its audience reset are never interleaved with an Everyone admission.

require_once __DIR__.'/synth_control.php';

// Two-key advisory space, distinct from the single bigint key used by session claim locks.
const SYNTH_AUDIENCE_GATE_NAMESPACE = 1398361684;
const SYNTH_AUDIENCE_GATE_KEY = 1;
const SYNTH_AUDIENCE_MODES = ['STANDARD', 'SHOUT'];

function synth_audience_query(\PgSql\Connection $connection, string $sql, array $params): \PgSql\Result
{
    if (pg_transaction_status($connection) !== PGSQL_TRANSACTION_INTRANS) {
        throw new LogicException('audience_transaction_required');
    }
    if (!@pg_send_query_params($connection, $sql, $params) || !($result = pg_get_result($connection))) {
        throw new RuntimeException('audience_query_failed');
    }
    while (pg_get_result($connection) !== false) {}
    $status = pg_result_status($result);
    if ($status === PGSQL_TUPLES_OK || $status === PGSQL_COMMAND_OK) return $result;
    // A missing column or table means the audience migration has not run; never create it here.
    $missing = in_array(pg_result_error_field($result, PGSQL_DIAG_SQLSTATE), ['42703', '42P01'], true);
    throw new RuntimeException($missing ? 'audience_migration_missing' : 'audience_query_failed');
}

// Re-entrant within one transaction. Refuses a shared-to-exclusive upgrade and refuses to take the gate
// after any other advisory lock (such as the session claim lock), because either could deadlock.
function synth_audience_lock_gate(\PgSql\Connection $connection, bool $exclusive): void
{
    $keys = [SYNTH_AUDIENCE_GATE_NAMESPACE, SYNTH_AUDIENCE_GATE_KEY];
    $held = pg_fetch_assoc(synth_audience_query($connection,
        "SELECT COALESCE(bool_or(gate), false) AS gate,
                COALESCE(bool_or(gate AND mode='ExclusiveLock'), false) AS exclusive,
                COALESCE(bool_or(NOT gate), false) AS other
           FROM (SELECT mode, classid=$1::oid AND objid=$2::oid AND objsubid=2 AS gate
                   FROM pg_locks WHERE locktype='advisory' AND pid=pg_backend_pid() AND granted) held",
        $keys));
    if ($held['gate'] === 't') {
        if ($exclusive && $held['exclusive'] !== 't') throw new LogicException('audience_gate_upgrade_refused');
        return;
    }
    if ($held['other'] === 't') throw new LogicException('audience_gate_lock_order');
    $function = $exclusive ? 'pg_advisory_xact_lock' : 'pg_advisory_xact_lock_shared';
    synth_audience_query($connection, "SELECT {$function}($1::int4, $2::int4)", $keys);
}

// Locks and returns the exact active, Everyone-capable session generation with the current mode verdict.
// A claim already holds this row lock; other callers hold it only for their short transaction.
function synth_audience_locked_session(\PgSql\Connection $connection, string $sessionId, int $generation): array
{
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $sessionId) !== 1 || $generation < 1) {
        throw new DomainException('invalid_audience_session');
    }
    synth_audience_lock_gate($connection, false);
    $row = pg_fetch_assoc(synth_audience_query($connection,
        "SELECT r.audience_intent, r.audience_reset_epoch, r.state, r.active_generation,
                r.capabilities ? 'dialogue.everyone' AS capable,
                (SELECT value FROM synth.conf_opts WHERE id='synth_mode') AS mode
           FROM synth_meta.runtime_sessions r WHERE r.session_id=$1 FOR UPDATE OF r",
        [$sessionId]));
    if (!$row || $row['state'] !== 'active') throw new DomainException('runtime_not_initialized');
    if (intval($row['active_generation']) !== $generation) throw new DomainException('stale_generation');
    // Only the capabilities stored by init count; the request's own list is not trusted here.
    if ($row['capable'] !== 't') throw new DomainException('audience_capability_required');
    // A missing mode row is the existing STANDARD default; an unrecognised stored value is refused.
    $mode = $row['mode'] ?? 'STANDARD';
    return [
        'intent' => strval($row['audience_intent']),
        'reset_epoch' => intval($row['audience_reset_epoch']),
        'mode_allows_everyone' => in_array($mode, SYNTH_AUDIENCE_MODES, true),
    ];
}

// Structured status control_audience for one exact session generation.
function synth_audience_read(\PgSql\Connection $connection, string $sessionId, int $generation): array
{
    $audience = synth_audience_locked_session($connection, $sessionId, $generation);
    return ['intent' => $audience['intent'], 'reset_epoch' => $audience['reset_epoch']];
}

// Ordinary preference edit (control or dashboard). It never bumps the reset epoch. Returning to normal is
// always allowed; choosing everyone requires a mode that can broadcast.
function synth_audience_set(\PgSql\Connection $connection, string $sessionId, int $generation, string $intent): array
{
    if (!in_array($intent, ['normal', 'everyone'], true)) throw new DomainException('invalid_audience');
    $audience = synth_audience_locked_session($connection, $sessionId, $generation);
    if ($intent === 'everyone' && !$audience['mode_allows_everyone']) throw new DomainException('audience_mode_incompatible');
    if ($audience['intent'] !== $intent) {
        $updated = synth_audience_query($connection,
            "UPDATE synth_meta.runtime_sessions SET audience_intent=$3
              WHERE session_id=$1 AND active_generation=$2 AND state='active'",
            [$sessionId, $generation, $intent]);
        if (pg_affected_rows($updated) !== 1) throw new RuntimeException('audience_write_failed');
    }
    return ['intent' => $intent, 'reset_epoch' => $audience['reset_epoch']];
}

// First canonical claim of an Everyone input only. Replays never reach this check, so a later reset cannot
// revoke an admitted turn. There is no directed fallback: any mismatch refuses the request.
function synth_audience_assert_everyone(\PgSql\Connection $connection, string $sessionId, int $generation, int $acknowledgedEpoch): void
{
    if ($acknowledgedEpoch < 0) throw new DomainException('invalid_audience_epoch');
    $audience = synth_audience_locked_session($connection, $sessionId, $generation);
    if (!$audience['mode_allows_everyone']) throw new DomainException('audience_mode_incompatible');
    // Intentionally no audience_intent check: an acknowledged Everyone stays in force until the game
    // refreshes, so a pending dashboard edit to normal must not revoke it. Only a forced reset (epoch) does.
    if ($audience['reset_epoch'] !== $acknowledgedEpoch) throw new DomainException('audience_epoch_stale');
}

// The single global mode writer. An incompatible mode resets every active session to normal and advances
// its epoch in the same transaction; STANDARD and SHOUT keep preferences. Returns the reset session count.
function synth_audience_set_global_mode(\PgSql\Connection $connection, string $mode): int
{
    if (!synth_control_valid_change('mode', $mode)) throw new DomainException('invalid_control');
    synth_audience_lock_gate($connection, true);
    synth_audience_query($connection,
        "INSERT INTO synth.conf_opts (id, value) VALUES ('synth_mode', $1)
         ON CONFLICT (id) DO UPDATE SET value=EXCLUDED.value",
        [$mode]);
    if (in_array($mode, SYNTH_AUDIENCE_MODES, true)) return 0;
    return pg_affected_rows(synth_audience_query($connection,
        "UPDATE synth_meta.runtime_sessions
            SET audience_intent='normal', audience_reset_epoch=audience_reset_epoch+1
          WHERE state='active'",
        []));
}
