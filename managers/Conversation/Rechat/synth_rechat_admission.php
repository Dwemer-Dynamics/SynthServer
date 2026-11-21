<?php

require_once __DIR__.'/synth_rechat_routes.php';

// Preserve the legacy consecutive-success distribution without temp files or unnecessary 100% rolls.
function synth_rechat_roll_budget(int $rounds, int $probability): int
{
    if ($rounds<=0 || $probability<=0) return 0;
    if ($probability>=100) return $rounds;
    $budget=0;
    while ($budget<$rounds && random_int(1,100)<=$probability) ++$budget;
    return $budget;
}

// Called under the native session lock: walk only completed, canonically owned, actually emitted actions.
// $ownFailed (delivery receipts only) also accepts the walked event's own failed, unrevoked execution at the first
// edge, so a line it emitted before failing stays receiptable; every emitting ancestor must still be complete.
function synth_rechat_action_chain_origin($connection, array $event, array $origin, bool $singleStep = false, bool $ownFailed = false): array
{
    $visited=[];
    for ($depth=0;$depth<64;++$depth) {
        $own=$ownFailed && $depth===0 && ($origin['request_id'] ?? null)===($event['request_id'] ?? null);
        if (($origin['type'] ?? '')!=='action_result') return $origin;
        $receipt=$origin['payload']['result'] ?? [];
        $action=$receipt['action_id'] ?? null; $key=$receipt['idempotency_key'] ?? null;
        if (!is_string($action) || !is_string($key) || isset($visited[$action])) {
            throw new LogicException('rechat_action_ancestry_invalid');
        }
        $visited[$action]=true;
        $row=\Synth\Core\DbManager\Dao\ProtocolEventDao::current()->actionAncestry([$action,$key,$event['session_id'],$event['generation'],$event['turn_id'],
             json_encode($receipt,JSON_THROW_ON_ERROR),$event['runtime_variant'],$origin['request_id'],
             synth_protocol_canonical_json($origin),hash('sha256',synth_protocol_canonical_json($origin)),$own ? 't' : 'f']);
        if ($row===null) throw new RuntimeException('rechat_action_ancestry_query_failed');
        if (!$row) throw new LogicException('rechat_action_ancestry_unavailable');
        $origin=json_decode($row['canonical_event'],true,flags:JSON_THROW_ON_ERROR);
        if (($origin['protocol_version'] ?? null)!==2 || ($origin['request_id'] ?? null)!==$row['request_id']
            || ($origin['type'] ?? null)!==$row['event_type']
            || !hash_equals($row['event_sha256'],hash('sha256',synth_protocol_canonical_json($origin)))) {
            throw new LogicException('rechat_action_ancestry_invalid');
        }
        foreach (['session_id','generation','turn_id','context_sequence','runtime_variant'] as $field) {
            if (($origin[$field] ?? null)!==($event[$field] ?? null)) throw new LogicException('rechat_action_ancestry_owner_mismatch');
        }
        if (!synth_protocol_owns_dialogue_turn($origin)) throw new LogicException('rechat_action_ancestry_invalid');
        $lines=synth_rechat_completed_lines(synth_protocol_decode_bytea($row['response_body']),$origin);
        if ($lines===null) throw new LogicException('rechat_action_emission_unavailable');
        $matches=0;
        $actor=['form_id'=>$row['full_form_id'],'origin_plugin'=>$row['origin_plugin'],'playthrough_id'=>$row['playthrough_id']];
        $target=$row['target_form_id']===null ? null : ['form_id'=>$row['target_form_id'],
            'origin_plugin'=>$row['target_plugin'],'playthrough_id'=>$row['target_playthrough']];
        if ($target!==null && $target['playthrough_id']!==$actor['playthrough_id']) throw new LogicException('rechat_action_emission_mismatch');
        foreach ($lines as $entry) {
            $line=$entry['line'];
            if ($line['type']!=='action') continue;
            $emitted=$line['payload']['action'] ?? [];
            if (($emitted['action_id'] ?? null)!==$action) continue;
            if (($emitted['idempotency_key'] ?? null)!==$key || ($emitted['capability'] ?? null)!==$row['capability']
                || ($emitted['name'] ?? null)!==substr($row['capability'],7)
                || synth_rechat_actor_key($emitted['actor'] ?? [])!==synth_rechat_actor_key($actor)
                || ($target===null ? isset($emitted['target'])
                    : synth_rechat_actor_key($emitted['target'] ?? [])!==synth_rechat_actor_key($target))
                || synth_protocol_canonical_json($emitted['arguments'] ?? [])!==
                    synth_protocol_canonical_json(json_decode($row['arguments'],true,flags:JSON_THROW_ON_ERROR))) {
                throw new LogicException('rechat_action_emission_mismatch');
            }
            ++$matches;
        }
        if ($matches!==1) throw new LogicException('rechat_action_emission_unavailable');
        // History follows each proven edge so intermediate replies are not lost or replaced by sibling results.
        if ($singleStep) return $origin;
    }
    throw new LogicException('rechat_action_ancestry_limit');
}

// Resolve the same root/reservation link for initial preparation and subsequent admission.
function synth_rechat_find_chain($connection, array $event, string $rootRequest): ?array
{
    $chains=\Synth\Core\DbManager\Dao\RechatChainDao::current()->forRoot($event['session_id'],$rootRequest);
    if ($chains===null) throw new RuntimeException('rechat_chain_query_failed');
    if (count($chains)>1) throw new LogicException('rechat_chain_ambiguous');
    return $chains[0] ?? null;
}

// Reserve ownership and budget only; a reservation is not permission to generate before actor selection.
function synth_rechat_reserve_continuation($db, array $event, array $policy, ?string $initialTerminal = null): array
{
    if ($initialTerminal!==null && !in_array($initialTerminal,['no_candidate','ineligible'],true)) {
        throw new InvalidArgumentException('rechat_initial_terminal_invalid');
    }
    return $db->withNativeRequestLock(static function($connection,array $owner) use($db,$event,$policy,$initialTerminal) {
        if ($owner!==$event) throw new LogicException('rechat_owner_changed');
        // Native lock preserves caller transactions; this savepoint additionally makes admission all-or-nothing.
        if (!@pg_query($connection,'SAVEPOINT synth_rechat_admission')) throw new RuntimeException('rechat_savepoint_failed');
        try {
            $proof=synth_rechat_completed_parent($db,$event);
            if ($proof===null) throw new LogicException('rechat_parent_unavailable');
            $parent=$event['payload']['reply_to_request_id'];
            // Receipt-owned parents gate reservation itself. Even a child with only legacy lines can write history.
            if (($proof['line']['payload']['receipt_required'] ?? null)===true) {
                $heard=\Synth\Core\DbManager\Dao\DialogueUtteranceDao::current()->heardLine($event['session_id'],$parent,$proof['line']['line_id'],
                    $event['generation'],$event['context_sequence'],$event['turn_id']);
                if ($heard===null) throw new RuntimeException('rechat_parent_delivery_query_failed');
                if (!$heard) throw new LogicException('rechat_parent_not_heard');
            }
            $rows=\Synth\Core\DbManager\Dao\RechatContinuationDao::current()->replayRows($event['session_id'],$event['request_id'],$parent);
            if ($rows===null) throw new RuntimeException('rechat_replay_query_failed');
            if ($rows) {
                if (count($rows)!==1 || $rows[0]['request_id']!==$event['request_id']
                    || $rows[0]['parent_request_id']!==$parent) throw new LogicException('rechat_parent_already_continued');
                $saved=$rows[0];
                if ($saved['parent_line_id']!==$event['payload']['reply_to_line_id']
                    || !hash_equals($saved['parent_response_sha256'],$proof['response_sha256'])) {
                    throw new LogicException('rechat_replay_proof_changed');
                }
                // Return only immutable per-request fields: later rounds must not change this replay.
                unset($saved['used_rounds']);
                $saved['policy']=json_decode($saved['policy'],true,flags:JSON_THROW_ON_ERROR);
                if (!@pg_query($connection,'RELEASE SAVEPOINT synth_rechat_admission')) throw new RuntimeException('rechat_savepoint_release_failed');
                return $saved;
            }
            $origin=synth_rechat_action_chain_origin($connection,$event,$proof['request']);
            $rootRequest=$origin['request_id'];
            $chain=synth_rechat_find_chain($connection,$event,$rootRequest);
            $rechatParent=($origin['type'] ?? '')==='trigger' && ($origin['payload']['kind'] ?? '')==='rechat';
            if (!$chain && $rechatParent) throw new LogicException('rechat_parent_reservation_missing');
            if (!$chain) {
                // These values must come from the selected authoritative profile, not the trigger payload.
                foreach (['rounds','probability','narration_chance','narration_cooldown'] as $key) {
                    if (!is_int($policy[$key] ?? null)) throw new InvalidArgumentException('rechat_policy_invalid');
                }
                foreach (['private','allow_actions','random_narration'] as $key) {
                    if (!is_bool($policy[$key] ?? null)) throw new InvalidArgumentException('rechat_policy_invalid');
                }
                if (!in_array($policy['mode'] ?? '',['tight','conversational','group','random'],true)) {
                    throw new InvalidArgumentException('rechat_policy_invalid');
                }
                foreach (['source_profile_id','source_actor_identity_id'] as $key) {
                    if (isset($policy[$key]) && (!is_int($policy[$key]) || $policy[$key]<1)) throw new InvalidArgumentException('rechat_policy_invalid');
                }
                $policy=array_intersect_key($policy,array_flip(['rounds','probability','mode','private','allow_actions',
                    'random_narration','narration_chance','narration_cooldown','source_profile_id','source_actor_identity_id']));
                $mode=$policy['mode']==='random' ? ['tight','conversational','group'][random_int(0,2)] : $policy['mode'];
                $budget=($policy['private'] || $initialTerminal!==null) ? 0 : synth_rechat_roll_budget($policy['rounds'],$policy['probability']);
                $terminal=$policy['private'] ? 'private' : ($initialTerminal ?? ($budget===0 ? 'budget_exhausted' : null));
                $chain=\Synth\Core\DbManager\Dao\RechatChainDao::current()->insert([$event['session_id'],$rootRequest,$event['generation'],$event['context_sequence'],$event['turn_id'],
                     $event['runtime_variant'],$mode,json_encode($policy,JSON_THROW_ON_ERROR),$budget,$terminal]);
                if (!$chain) throw new RuntimeException('rechat_chain_insert_failed');
            }
            foreach (['session_id','turn_id','runtime_variant'] as $field) {
                if ($chain[$field]!==$event[$field]) throw new LogicException('rechat_chain_owner_mismatch');
            }
            foreach (['generation','context_sequence'] as $field) {
                if (intval($chain[$field])!==$event[$field]) throw new LogicException('rechat_chain_owner_mismatch');
            }
            $terminal=$chain['terminal_reason'];
            $used=intval($chain['used_rounds']); $budget=intval($chain['budget']);
            if ($terminal===null && $used>=$budget) $terminal='budget_exhausted';
            $round=$terminal===null ? $used+1 : null;
            $saved=\Synth\Core\DbManager\Dao\RechatContinuationDao::current()->insert([$event['session_id'],$event['request_id'],$chain['id'],$parent,$event['payload']['reply_to_line_id'],
                 $proof['response_sha256'],$round,$terminal===null ? 'reserved' : 'terminal',$terminal]);
            if (!$saved) throw new RuntimeException('rechat_reservation_insert_failed');
            if (!\Synth\Core\DbManager\Dao\RechatChainDao::current()->updateBudget($chain['id'],$round ?? $used,
                $terminal ?? ($round===$budget ? 'budget_exhausted' : null))) throw new RuntimeException('rechat_budget_update_failed');
            if (!@pg_query($connection,'RELEASE SAVEPOINT synth_rechat_admission')) throw new RuntimeException('rechat_savepoint_release_failed');
            return $saved+['mode'=>$chain['mode'],'policy'=>json_decode($chain['policy'],true,flags:JSON_THROW_ON_ERROR),'budget'=>$chain['budget']];
        } catch (Throwable $error) {
            if (@pg_query($connection,'ROLLBACK TO SAVEPOINT synth_rechat_admission')===false
                || @pg_query($connection,'RELEASE SAVEPOINT synth_rechat_admission')===false) {
                throw new RuntimeException('rechat_admission_rollback_failed',0,$error);
            }
            throw $error;
        }
    },2000);
}
