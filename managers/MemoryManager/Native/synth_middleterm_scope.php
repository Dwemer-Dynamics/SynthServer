<?php

require_once __DIR__.'/synth_middleterm.php';

// Exact snapshot and actor keys are mandatory; no display-name lookup or implicit latest-save selection.
function synth_middleterm_scope_input(array $input): array
{
    $operation=$input['operation'] ?? 'scopes';
    $allowed=match($operation) {
        'scopes'=>['operation','before_session','before_generation','before_sequence'],
        'authors'=>['operation','session_id','generation','context_sequence','before_actor_id'],
        'list'=>['operation','session_id','generation','context_sequence','actor_identity_id','before_id'],
        'detail'=>['operation','session_id','generation','context_sequence','actor_identity_id','narrative_id'],
        default=>throw new InvalidArgumentException('Unknown narrative inspection operation.'),
    };
    if (array_diff(array_keys($input),$allowed)) throw new InvalidArgumentException('Unknown narrative inspection field.');
    $out=['operation'=>$operation];
    foreach ($input as $key=>$value) {
        if ($key==='operation') continue;
        if (!is_string($value) && !is_int($value)) throw new InvalidArgumentException('Invalid narrative inspection key.');
        $value=strval($value);
        $pattern=in_array($key,['session_id','before_session'],true)
            ? '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D' : '/^[1-9][0-9]{0,17}$/D';
        if (!preg_match($pattern,$value)) throw new InvalidArgumentException('Invalid narrative inspection key.');
        $out[$key]=$value;
    }
    $required=$operation==='scopes' ? (count($out)>1 ? ['before_session','before_generation','before_sequence'] : [])
        : ['session_id','generation','context_sequence',...($operation==='authors'?[]:['actor_identity_id']),
            ...($operation==='detail'?['narrative_id']:[])];
    foreach ($required as $key) if (!isset($out[$key])) throw new InvalidArgumentException('Incomplete narrative inspection scope.');
    return $out;
}
