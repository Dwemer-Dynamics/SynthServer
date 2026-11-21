<?php

// Moved from lib/data_functions.php: first meeting and last interaction records
// The original file requires this one, so every caller is unchanged.

function AddFirstTimeMet($followerName,$momentum,$gamets,$ts) {

    $fn=\Synth\Bootstrap::db()->escape($followerName);

    // Check if already recorded - with error handling
    $already = @\Synth\Bootstrap::db()->fetchAll("select 1 as t from core_memory_log where event='first_met' and message like '%met {$fn}%'");
    if ($already === false) {
        Logger::warn("[AddFirstTimeMet] Query to memory table failed for follower: {$followerName}");
        return;
    }

    if (is_array($already) && sizeof($already)>0) {
        // Already exists;
        return;
    }

    // Get first interaction timestamp - with error handling
    $realFirst = @\Synth\Bootstrap::db()->fetchAll("SELECT gamets,convert_gamets2fallout_date(gamets) as fallout_date,ts,localts FROM core_speech where companions ilike '%$fn%' order by rowid asc limit 1 offset 0");

    if ($realFirst === false) {
        Logger::warn("[AddFirstTimeMet] Query to speech table failed for follower: {$followerName}");
        return;
    }

    if (is_array($realFirst) && sizeof($realFirst)>0) {
        $gamets=$realFirst[0]["gamets"];
        $ts=$realFirst[0]["ts"];
        $momentum=$realFirst[0]["localts"];
        $fallout_date=$realFirst[0]["fallout_date"]; // game timestamp converted to fallout date YYYY-MM-DD HH:MM:SS

        logMemory(\Synth\Bootstrap::config("PLAYER_NAME"), $followerName,
        ("(Important note: " . \Synth\Bootstrap::config('PLAYER_NAME') . " met {$followerName} for the first time on {$fallout_date}. This is an important event, so use tag #FirstTimeMet.)"),
        $momentum, $gamets,'first_met',$ts);
    }


}

function DataRetrieveFirstTimeMet($s_player_name, $s_npc_name) {
    $db = \Synth\Bootstrap::db();

	$s_res = "";

	if ((strlen($s_player_name)>0) && (strlen($s_npc_name)>0) && ($s_player_name != $s_npc_name)) {
        if (($s_npc_name == "SYNTH") || ($s_player_name == "SYNTH")) { // SYNTH easter egg
            return "{$s_player_name} met {$s_npc_name} for the first time on 0199-04-26, 15:36:00, years ago.";
        }
		$s_player = $s_player_name;
		$s_npc = $s_npc_name;

        $crt_gamets = intval(DataLastKnownGameTS());

		$first_met = \Synth\Core\DbManager\Dao\MemoryLogDao::current($db)->firstMet($s_player, $s_npc);
		$db_rec = $first_met === null ? [] : [$first_met];

        $b_found_memory = (is_array($db_rec) && sizeof($db_rec)>0);

        if (!$b_found_memory) { // check conversations
            $gts_met = GetFirstInteraction($s_player, $s_npc);
        } else {
			$gts_met = intval($db_rec[0]['gamets'] ?? 0);
		}

        if (($gts_met > 0) && ($crt_gamets > $gts_met)) {
            $gts_ago = $crt_gamets - $gts_met;
            $s_met = convert_gamets2fallout_date($gts_ago);
			$hours_ago = convert_gamets2hours($gts_ago);

			if ($hours_ago < 49)
				$s_time_ago = "{$hours_ago} hours ago";
			else {
				$days_ago = intval($hours_ago / 24);
				$s_time_ago = "{$days_ago} days ago";
			}
			$s_res = "{$s_player_name} met {$s_npc_name} for the first time on {$s_met}, {$s_time_ago}.";

        } else {
			Logger::info("DataRetrieveLastMet: NO match found");
			//$s_res = "There is no record of when {$s_player_name} met {$s_npc_name}.";
		}
	}
	return $s_res;
}

function GetFirstTimeMetMemory($s_player_name, $s_npc_name) {
    $db = \Synth\Bootstrap::db();
    $i_res = 0;

	if ((strlen($s_player_name)>0) && (strlen($s_npc_name)>0) && ($s_player_name != $s_npc_name)) {
		$s_player = $s_player_name;
		$s_npc = $s_npc_name;

        //$crt_gamets = intval(DataLastKnownGameTS());

		$first_met = \Synth\Core\DbManager\Dao\MemoryLogDao::current($db)->firstMet($s_player, $s_npc);
		$db_rec = $first_met === null ? [] : [$first_met];

        $b_found_memory = (is_array($db_rec) && sizeof($db_rec)>0);

        if ($b_found_memory) {
			$i_res = intval($db_rec[0]['gamets'] ?? 0);
		}

	}
	return $i_res;
}

function GetFirstTimeMet($s_player_name, $s_npc_name) {
    $i_res = 0;

	if ((strlen($s_player_name)>0) && (strlen($s_npc_name)>0) && ($s_player_name != $s_npc_name)) {

        $i_res = GetFirstTimeMetMemory($s_player_name, $s_npc_name);

        if ($i_res <= 0) { // check conversations
            $i_res = GetFirstInteraction($s_player_name, $s_npc_name);
		}
	}
	return $i_res;
}

function GetLastInteraction($s_player_name, $s_npc_name) {
    $db = \Synth\Bootstrap::db();
	$i_res = 0;
	if ((strlen($s_player_name)>0) && (strlen($s_npc_name)>0) && ($s_player_name != $s_npc_name)) {
		$s_player = $s_player_name;
		$s_npc = $s_npc_name;
		$i_res = \Synth\Core\DbManager\Dao\SpeechDao::current($db)->lastInteractionGamets($s_player, $s_npc);
	}
	return $i_res;
}

function GetLastSpeechTs() {
    $db = \Synth\Bootstrap::db();
    $i_res=0;
	$i_res = \Synth\Core\DbManager\Dao\SpeechDao::current($db)->latestGamets();

	return $i_res;
}

function GetFirstInteraction($s_player_name, $s_npc_name) {
    $db = \Synth\Bootstrap::db();
	$i_res = 0;
	if ((strlen($s_player_name)>0) && (strlen($s_npc_name)>0) && ($s_player_name != $s_npc_name)) {
		$s_player = $s_player_name;
		$s_npc = $s_npc_name;
		$i_res = \Synth\Core\DbManager\Dao\SpeechDao::current($db)->firstInteractionGamets($s_player, $s_npc);
	}
	return $i_res;
}

function DataRetrieveLastTimeTalk($s_player_name, $s_npc_name) {
    $db = \Synth\Bootstrap::db();

	$s_res = "";

	if ((strlen($s_player_name)>0) && (strlen($s_npc_name)>0) && (!($s_player_name == 'The Narrator')) && (!($s_npc_name == 'The Narrator'))) {
		$crt_gamets = intval(DataLastKnownGameTS());
		$gts_met = GetLastInteraction($s_player_name, $s_npc_name);
		if ($gts_met > 0) {
			$s_date = gamets2str_format_date($gts_met, $dt_format = 'Y-m-d');
			$gts_ago = $crt_gamets - $gts_met;
			$hours_ago = convert_gamets2hours($gts_ago);
			if ($hours_ago > 3) {
				if ($hours_ago < 48) {
					$s_res = "{$s_player_name} and {$s_npc_name} last spoke {$hours_ago} hours ago.";
				} else {
					$days_ago = convert_gamets2days($gts_ago);
					if ($days_ago < 31) {
						$s_res = "{$s_player_name} and {$s_npc_name} last spoke {$days_ago} days ago.";
					} else {
						$months_ago = intval($days_ago * 0.03333333);
						if ($months_ago < 12) {
							$s_res = "{$s_player_name} and {$s_npc_name} last spoke {$months_ago} months ago on {$s_date}.";
						} else {
							$s_res = "{$s_player_name} and {$s_npc_name} last spoke long time ago on {$s_date}.";
						}
					}
				}
			} else {
                Logger::debug("DataRetrieveLastTimeTalk: {$s_player_name} and {$s_npc_name} spoke recently");
				//$s_res = "{$s_player_name} and {$s_npc_name} spoke recently.";
			}
		} else {
			Logger::debug("DataRetrieveLastTimeTalk: NO match found for {$s_player_name} - {$s_npc_name}");
			//$s_res = "There is no record of when {$s_player_name} and {$s_npc_name} last spoke.";
		}
	}
	return $s_res;
}
