<?php

require_once __DIR__.'/request.php';
require_once __DIR__.'/synth_protocol_state.php';

// Provider workers bind a verified immutable ingress owner and restore every swapped request/connector global.
function synth_worker_with_origin($db, array $job, callable $work, string $kind): ?int
{
    if (!in_array($kind,['memory','relationship','middleterm'],true)) throw new InvalidArgumentException('Invalid native worker kind');
    $event=synth_protocol_decode_json($job['canonical_event']);
    if (!is_array($event) || ($event['protocol_version'] ?? null)!==2
        || !hash_equals($job['event_sha256'],hash('sha256',synth_protocol_canonical_json($event)))
        || ($event['session_id'] ?? null)!==$job['session_id'] || ($event['request_id'] ?? null)!==$job['request_id']
        || ($event['turn_id'] ?? null)!==$job['turn_id'] || ($event['type'] ?? null)!==$job['event_type']
        || ($event['generation'] ?? null)!==intval($job['generation'])
        || ($event['context_sequence'] ?? null)!==intval($job['context_sequence'])) {
        throw new RuntimeException('Native '.$kind.' origin mismatch');
    }
    $keys=['db','SYNTH_REQUEST_EVENT','SYNTH_PROTOCOL_REQUEST_CLAIMED','SYNTH_MEMORY_JOB_LEASE','SYNTH_RELATIONSHIP_JOB_LEASE','SYNTH_INTERACTION_JOB_LEASE','SYNTH_MIDDLETERM_JOB_LEASE',
        'PLAYER_NAME','SYNTH_NAME','gameRequest','COMMAND_PROMPT','FORCE_MAX_TOKENS','CURRENT_CONNECTOR',
        'SYNTH_CORE_CURRENT_CONNECTOR_DATA','CONNECTOR','DEBUG_DATA','PATCH_PROMPT_ENFORCE_ACTIONS',
        'COMMAND_PROMPT_ENFORCE_ACTIONS','BASE_FUNCTIONS','SYNTH_CLIENT_CAPABILITIES','DIRECT_NARRATOR_DIALOGUE'];
    $previous=array_intersect_key($GLOBALS,array_flip($keys));
    try {
        $GLOBALS['db']=$db;
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$event];
        $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=true;
        unset($GLOBALS['SYNTH_MEMORY_JOB_LEASE'],$GLOBALS['SYNTH_RELATIONSHIP_JOB_LEASE'],$GLOBALS['SYNTH_INTERACTION_JOB_LEASE'],$GLOBALS['SYNTH_MIDDLETERM_JOB_LEASE']);
        synth_protocol_assert_request_active();
        $context=synth_protocol_current_context($db,$event);
        if ($context===null || !is_array($context['target'])) return null;
        return $work($event,$context);
    } finally {
        foreach ($keys as $key) {
            if (array_key_exists($key,$previous)) $GLOBALS[$key]=$previous[$key];
            else unset($GLOBALS[$key]);
        }
    }
}
