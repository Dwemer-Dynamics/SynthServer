<?php

require_once(dirname(__DIR__, 3).'/lib' . DIRECTORY_SEPARATOR . 'utils_game_timestamp.php');
require_once(dirname(__DIR__, 3).'/lib' . DIRECTORY_SEPARATOR . 'logger.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'playthrough_schema.php');
require_once(dirname(__DIR__, 3).'/lib' . DIRECTORY_SEPARATOR . 'db_connection_settings.php');

/**
 * Timeline Break autosnapshot helper.
 * Detects a large rollback. Snapshot creation is paused (see lib/playthrough_schema.php), so no
 * snapshot is taken and callers receive 0, the existing "no snapshot" result.
 */

function timeline_break_is_enabled() {
	if (!\Synth\Bootstrap::has('TIMELINE_BREAK_AUTOSNAPSHOT')) {
		\Synth\Bootstrap::set('TIMELINE_BREAK_AUTOSNAPSHOT', true);
	}
	return !!\Synth\Bootstrap::config("TIMELINE_BREAK_AUTOSNAPSHOT");
}

function timeline_break_min_days() {
	if (!\Synth\Bootstrap::has('TIMELINE_BREAK_MIN_DAYS')) {
		\Synth\Bootstrap::set('TIMELINE_BREAK_MIN_DAYS', 3);
	}
	return intval(\Synth\Bootstrap::config("TIMELINE_BREAK_MIN_DAYS"));
}

/**
 * Verify the versioned playthrough metadata schema is ready.
 */
function timeline_break_meta_schema_ready($adminConn) {
	return pts_metadata_schema_ready($adminConn);
}

/**
 * Create a snapshot profile. Paused: returns 0 before any connection, lock or clone.
 * Native save identity, checkpoints and rollback pruning do not depend on it.
 */
function timeline_break_create_snapshot($name, $notes) {
	Logger::debug("TimelineBreak: Snapshot skipped; playthrough snapshots are unavailable");
	return 0;
}

/**
 * Compose a Timeline Break snapshot name and create it if not present.
 * Returns snapshot id (existing or newly created), or 0.
 */
function timeline_break_snapshot_if_needed($prevGamets, $incomingGamets) {
	if (!timeline_break_is_enabled()) {
		return 0;
	}
	$prev = intval($prevGamets);
	$incoming = intval($incomingGamets);
	if ($prev <= 0 || $incoming <= 0) {
		return 0;
	}
	if ($incoming >= $prev) {
		return 0;
	}
	$daysRollback = gamets2days_between($incoming, $prev);
	if ($daysRollback < timeline_break_min_days()) {
		return 0;
	}
	$dateNew = convert_gamets2fallout_long_date_no_time($incoming);
	$dateOld = convert_gamets2fallout_long_date_no_time($prev);
	$name = "Timeline Break (" . $dateOld . " -> " . $dateNew . ")";
	$notes = "Auto snapshot due to rollback of {$daysRollback} in-game days ({$incoming} -> {$prev}).";
	return timeline_break_create_snapshot($name, $notes);
}

?>


