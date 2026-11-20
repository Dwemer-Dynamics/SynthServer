<?php

// Moved from lib/chat_helper_functions.php: the latest diary and saved social task context blocks
// The original file requires this one, so every caller is unchanged.

function synthBuildLatestDiaryContextBlock(string $npcName, array $profileData): string
{
    return synthBuildLatestDiaryReflectionBlock($npcName,$profileData).synthBuildSavedSocialTaskContextBlock($npcName,$profileData);
}

// Saved instruction notes remain a separate NPC-owned journal section, not generated diary prose.
function synthBuildSavedSocialTaskContextBlock(string $npcName,array $profileData): string
{
    $event=\Synth\Bootstrap::requestEvent()['json']??[];$db=\Synth\Bootstrap::db()??null;
    if (empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'])||!is_object($db)||($event['protocol_version']??1)!==2)return '';
    require_once dirname(__DIR__, 3).'/lib'.'/synth_social_tasks.php';
    if (!synth_social_supported($event)||synth_protocol_response_is_narrator(trim($npcName)))return '';
    try {
        $context=synth_protocol_current_context($db,$event);
        if (!$context)return '';
        $actor=synth_protocol_response_actor_identity($db,$event,$context);
        if (!$actor || synth_protocol_actor_identity_id($db,$actor)!==intval($profileData['actor_identity_id']??0))return '';
        $notes=synth_social_owned($db,$event,static fn($connection)=>synth_social_diary_notes($db,$event,$actor));
        if (!$notes)return '';
        $text=implode("\n",array_column($notes,'content'));
        return "\n<saved_conversation_tasks>\n".htmlspecialchars($text,ENT_QUOTES|ENT_XML1|ENT_SUBSTITUTE,'UTF-8')."\n</saved_conversation_tasks>\n";
    } catch(Throwable $error) {Logger::warn('[SOCIAL_TASKS] Saved task notes unavailable for the owned speaker');return '';}
}

function synthBuildLatestDiaryReflectionBlock(string $npcName, array $profileData): string
{
    $native=synth_diary_requires_owned_context();
    if ($native && empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'])) return '';
    $safeNpcName = trim($npcName);
    $narrator=synth_protocol_response_is_narrator($safeNpcName);
    if ($safeNpcName === '' || (!$native && $narrator)) {
        return '';
    }

    $metadata = json_decode(strval($profileData['metadata'] ?? '{}'), true);
    if (!is_array($metadata)
        || !filter_var($metadata['LATEST_DIARY_CONTEXT_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        return '';
    }

    $db = \Synth\Bootstrap::db() ?? null;
    if (!is_object($db) || !method_exists($db, 'fetchOne') || !method_exists($db, 'escape')) {
        return '';
    }

    try {
        if ($native) {
            require_once dirname(__DIR__, 3).'/lib'.'/synth_diary_state.php';
            $event=\Synth\Bootstrap::requestEvent()['json'];
            $actorId=intval($profileData['actor_identity_id'] ?? 0);
            if ($narrator) {
                // The current canonical snapshot owns Narrator history; profile IDs and roleplay names cannot redirect it.
                if (!synth_protocol_current_context($db,$event)) return '';
                $actorId=intval($db->fetchOne('SELECT player_identity_id FROM public.core_runtime_context WHERE session_id='.$db->escapeLiteral($event['session_id'])
                    .' AND generation='.intval($event['generation']).' AND snapshot_sequence='.intval($event['context_sequence']))['player_identity_id'] ?? 0);
            }
            $entry=synth_diary_visible($db,$event,$actorId,$narrator);
        } else {
            $escapedName = $db->escape($safeNpcName);
            $entry = $db->fetchOne(
                "SELECT topic, summary AS content
                 FROM public.core_memory
                 WHERE source_type = 'diary' AND lower(trim(companions)) = lower('{$escapedName}')
                 ORDER BY gamets DESC, localts DESC, rowid DESC
                 LIMIT 1"
            );
        }
    } catch (Throwable $e) {
        Logger::warn("[LATEST_DIARY_CONTEXT] Unable to load diary context for {$safeNpcName}: " . $e->getMessage());
        return '';
    }

    $content = trim(strval($entry['content'] ?? ''));
    if ($content === '') {
        return '';
    }

    $topic = trim(strval($entry['topic'] ?? ''));
    $diaryText = $topic !== '' ? "Date: {$topic}\n{$content}" : $content;
    $escapedText = htmlspecialchars(
        $diaryText,
        ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE,
        'UTF-8'
    );

    return "\n<latest_diary_entry>\n{$escapedText}\n</latest_diary_entry>\n";
}
