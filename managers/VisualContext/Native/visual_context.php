<?php

require_once(dirname(__DIR__, 3).'/lib' . DIRECTORY_SEPARATOR . 'settings.php');

if (!function_exists('synthVisualContextText')) {
    function synthVisualContextText($value, int $maxLength = 500): string
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', strval($value)) ?? '');
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $maxLength)
            : substr($value, 0, $maxLength);
    }
}

if (!function_exists('synthVisualContextSubjectType')) {
    function synthVisualContextSubjectType($value): string
    {
        $value = strtolower(trim(strval($value)));
        $allowed = ['scene', 'location', 'actor', 'player', 'item', 'furniture', 'object'];
        return in_array($value, $allowed, true) ? $value : 'scene';
    }
}

if (!function_exists('synthVisualContextStore')) {
    function synthVisualContextStore(array $record): int
    {
        $db = \Synth\Bootstrap::db() ?? null;
        if (!$db) {
            return 0;
        }

        $metadata = $record['metadata'] ?? [];
        if (!is_array($metadata)) {
            $metadata = [];
        }
        $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($metadataJson === false) {
            $metadataJson = '{}';
        }

        $subjectType = synthVisualContextSubjectType($record['subject_type'] ?? 'scene');
        $subjectName = synthVisualContextText($record['subject_name'] ?? '', 300);
        $location = synthVisualContextText($record['location_name'] ?? '', 300);
        $worldspace = synthVisualContextText($record['worldspace_name'] ?? '', 300);
        $subjectKey = synthVisualContextText($record['subject_key'] ?? '', 500);
        if ($subjectKey === '') {
            $subjectKey = $subjectType . ':' . strtolower($subjectName !== '' ? $subjectName : ($location . ':' . $worldspace));
        }

        $nullableInt = static function ($value): ?int {
            return is_numeric($value) && intval($value) > 0 ? intval($value) : null;
        };
        $nullableText = static function ($value, int $maxLength): ?string {
            $text = synthVisualContextText($value, $maxLength);
            return $text === '' ? null : $text;
        };
        $responsePayload = is_array($record['response_payload'] ?? null) ? $record['response_payload'] : [];
        $responseJson = json_encode($responsePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($responseJson === false) {
            $responseJson = '{}';
        }

        return \Synth\Core\DbManager\Dao\VisualContextDao::current($db)->insert([
            'capture_id' => synthVisualContextText($record['capture_id'] ?? '', 160),
            'session_id' => $nullableText($record['session_id'] ?? '', 128),
            'request_id' => synthVisualContextText($record['request_id'] ?? '', 128),
            'turn_id' => synthVisualContextText($record['turn_id'] ?? '', 128),
            'generation' => $nullableInt($record['generation'] ?? null),
            'runtime_variant' => in_array($record['runtime_variant'] ?? '', ['flat', 'vr'], true) ? $record['runtime_variant'] : 'flat',
            'interaction_mode' => in_array($record['interaction_mode'] ?? '', ['store_only', 'describe', 'npc_portrait'], true)
                ? $record['interaction_mode'] : 'store_only',
            'actor_identity_id' => $nullableInt($record['actor_identity_id'] ?? null),
            'media_id' => $nullableText($record['media_id'] ?? '', 128),
            'request_sha256' => $nullableText($record['request_sha256'] ?? '', 64),
            'response_payload' => $responseJson,
            'subject_type' => $subjectType,
            'subject_key' => $subjectKey,
            'subject_name' => $subjectName,
            'plugin' => synthVisualContextText($record['plugin'] ?? '', 255),
            'baseid' => synthVisualContextText($record['baseid'] ?? '', 32),
            'refid' => synthVisualContextText($record['refid'] ?? '', 32),
            'cell_id' => synthVisualContextText($record['cell_id'] ?? '', 32),
            'worldspace_id' => synthVisualContextText($record['worldspace_id'] ?? '', 32),
            'location_name' => $location,
            'worldspace_name' => $worldspace,
            'image_path' => synthVisualContextText($record['image_path'] ?? '', 1000),
            'image_sha256' => synthVisualContextText($record['image_sha256'] ?? '', 64),
            'description' => synthVisualContextText($record['description'] ?? '', 12000),
            'perspective' => synthVisualContextText($record['perspective'] ?? 'first_person', 50),
            'provider' => synthVisualContextText($record['provider'] ?? '', 100),
            'model' => synthVisualContextText($record['model'] ?? '', 255),
            'metadata' => $metadataJson,
            'locked' => !empty($record['locked']),
            'active' => !(array_key_exists('active', $record) && empty($record['active'])),
            'user_edited' => !empty($record['user_edited']),
        ]);
    }
}

if (!function_exists('synthVisualContextFindCapture')) {
    function synthVisualContextFindCapture(string $captureId): ?array
    {
        $captureId = synthVisualContextText($captureId, 160);
        if ($captureId === '' || !\Synth\Bootstrap::has('db')) {
            return null;
        }
        $row = \Synth\Core\DbManager\Dao\VisualContextDao::current()->findByCapture($captureId);
        return is_array($row) && $row !== [] ? $row : null;
    }
}

if (!function_exists('synthVisualContextComplete')) {
    function synthVisualContextComplete(int $id, array $values): bool
    {
        if ($id < 1 || !\Synth\Bootstrap::has('db')) {
            return false;
        }
        $texts = [];
        foreach ([
            'description' => 12000,
            'provider' => 100,
            'model' => 255,
            'image_sha256' => 64,
            'media_id' => 128,
        ] as $key => $maxLength) {
            if (array_key_exists($key, $values)) {
                $texts[$key] = synthVisualContextText($values[$key], $maxLength);
            }
        }
        $payloadJson = null;
        if (array_key_exists('response_payload', $values)) {
            $payload = is_array($values['response_payload']) ? $values['response_payload'] : [];
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $payloadJson = $encoded === false ? '{}' : $encoded;
        }
        return \Synth\Core\DbManager\Dao\VisualContextDao::current()->complete($id, $texts, $payloadJson);
    }
}

// Commit visual results and portrait metadata only while the original runtime/context still owns them.
function synthVisualContextCompleteOwned(array $request, int $recordId, array $fields, ?callable $persistPortrait = null): void
{
    require_once __DIR__ . '/visual_context_request.php';
    $db = \Synth\Bootstrap::db();
    if (!$db->isTransactionIdle() || $db->execQuery('BEGIN') === false) throw new RuntimeException('Visual completion requires an idle transaction.');
    try {
        $session = $db->escapeLiteral($request['session_id']);
        $db->fetchOne("SELECT pg_advisory_xact_lock(hashtext($session))");
        $db->fetchOne("SELECT session_id FROM public.core_runtime_session WHERE session_id=$session FOR UPDATE");
        synth_visual_validate_runtime($db, $request);
        $capture = $db->escapeLiteral($request['capture_id']);
        $record = $db->fetchOne("SELECT request_sha256 FROM public.core_visual_context
            WHERE id=$recordId AND capture_id=$capture AND session_id=$session FOR UPDATE");
        if (!$record || !hash_equals(strval($record['request_sha256']), hash('sha256', synth_visual_canonical_json($request)))) {
            throw new RuntimeException('Visual completion claim does not match the request.');
        }
        if ($persistPortrait !== null) $persistPortrait();
        if (!synthVisualContextComplete($recordId, $fields)) {
            throw new RuntimeException('Visual context completion could not be persisted.');
        }
        if (($request['protocol_version'] ?? 1) === 2 && $request['interaction_mode'] === 'describe'
            && ($fields['response_payload']['ok'] ?? false) === true) {
            synthVisualContextRecordResult($db, $request, $fields);
        }
        if ($db->execQuery('COMMIT') === false) throw new RuntimeException('Visual context completion commit failed.');
    } catch (Throwable $error) {
        $db->execQuery('ROLLBACK');
        throw $error;
    }
}

// Runs inside the completion lock: one accepted result advances the same frontier captured by co-save contexts.
function synthVisualContextRecordResult($db, array $request, array $fields): void
{
    if ($db->isTransactionIdle()) throw new LogicException('Visual history requires the completion transaction.');
    $session=$db->escapeLiteral($request['session_id']);
    $capture=$db->escapeLiteral($request['capture_id']);
    $generation=intval($request['generation']);$sequence=intval($request['context_sequence']);
    $context=$db->fetchOne("SELECT playthrough_id,target_identity_id FROM public.core_runtime_context
        WHERE session_id=$session AND generation=$generation AND snapshot_sequence=$sequence");
    if (!$context || !$context['target_identity_id'] || empty($request['subject'])) throw new RuntimeException('Visual history requires the frozen subject.');
    $description=synthVisualContextText($fields['description'] ?? '',12000);
    if ($description==='') throw new RuntimeException('Successful visual history requires a description.');
    $result=['schema'=>'synth.visual.result.v1','capture_id'=>$request['capture_id'],'description'=>$description,
        'subject_name'=>$request['subject']['display_name'] ?? '', 'world'=>$request['world'],
        'perspective'=>$request['perspective'],'media'=>$request['media'],
        'provider'=>synthVisualContextText($fields['provider'] ?? '',100),'model'=>synthVisualContextText($fields['model'] ?? '',255)];
    $resultJson=synth_visual_canonical_json($result);$resultHash=hash('sha256',$resultJson);
    $wire=synth_visual_canonical_json($request);$wireHash=hash('sha256',$wire);
    $existing=$db->fetchOne("SELECT v.result_sha256,e.request_sha256 FROM public.core_visual_result v
        JOIN public.core_request_execution e USING(session_id,request_id) WHERE v.capture_id=$capture");
    if ($existing) {
        if (!hash_equals($existing['result_sha256'],$resultHash) || !hash_equals($existing['request_sha256'],$wireHash)) {
            throw new RuntimeException('Visual history replay conflicts with its accepted result.');
        }
        return;
    }
    $actor=intval($context['target_identity_id']);
    $gamets=max(1,intval($request['world']['game_time_ticks']));$now=time();
    // This is the real image endpoint's accepted result, not an impersonated dialogue request.
    if ($db->execQuery("INSERT INTO public.core_request_execution
        (session_id,request_id,turn_id,generation,request_sha256,event_type,state,response_content_type,response_body,completed_at)
        VALUES($1,$2,$3,$4,$5,'visual_capture','complete',
            'application/vnd.synth.visual-result+json',convert_to($6,'UTF8'),NOW())",
            [$request['session_id'],$request['request_id'],$request['turn_id'],$generation,$wireHash,$resultJson])===false
        || $db->execQuery("INSERT INTO public.core_protocol_event
            (session_id,request_id,turn_id,generation,event_type,canonical_event,event_sha256)
            VALUES($1,$2,$3,$4,'visual_capture',$5::jsonb,$6)",
            [$request['session_id'],$request['request_id'],$request['turn_id'],$generation,$wire,$wireHash])===false) {
        throw new RuntimeException('Visual history request ownership could not be persisted.');
    }
    if ($db->execQuery("WITH publication AS (
        INSERT INTO public.core_context_event(type,data,sess,gamets,localts,ts,people,location,party,delivery_state,
            synth_session_id,synth_generation,synth_context_sequence,synth_request_id)
        VALUES('visual_result','Accepted native image description',$5,$6,$7,$7,'','','','derived',
            $1,$2,$3,$4) RETURNING *
        ), history AS (
            INSERT INTO public.core_history_event(session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
            SELECT $1,$2,$3,$4,$5,rowid,to_jsonb(publication) FROM publication RETURNING id
        ) INSERT INTO public.core_visual_result(history_event_id,capture_id,session_id,generation,context_sequence,request_id,
            actor_identity_id,result_payload,result_sha256)
        SELECT id,$8,$1,$2,$3,$4,$9,$10::jsonb,$11 FROM history",
        [$request['session_id'],$generation,$sequence,$request['request_id'],$context['playthrough_id'],
            $gamets,$now,$request['capture_id'],$actor,$resultJson,$resultHash])===false) {
        throw new RuntimeException('Visual history result could not be persisted.');
    }
}

// Shared prompt/management reader: accepted observations and corrections must both be in the saved frontier.
function synthVisualContextVisibleCte($db, array $event): string
{
    require_once dirname(__DIR__, 3).'/lib' . '/synth_protocol_state.php';
    return synth_protocol_history_cte($db,$event).", native_visual_results AS (SELECT
            v.*,v.result_payload->>'subject_name' AS subject_name,
            v.result_payload->>'description' AS original_description,
            COALESCE(correction.description,v.result_payload->>'description') AS description,
            COALESCE(correction.history_event_id,0) AS revision,
            gallery.locked,gallery.active,(correction.description IS NOT NULL) AS user_edited,h.recorded_at AS captured_at
        FROM native_history_scopes scope JOIN public.core_visual_result v
            ON v.session_id=scope.session_id AND v.generation=scope.generation AND v.actor_identity_id=scope.reader_identity_id
        JOIN public.core_history_event h ON h.id=v.history_event_id AND h.id<=scope.cutoff
            AND h.session_id=v.session_id AND h.generation=v.generation AND h.request_id=v.request_id
            AND h.context_sequence=v.context_sequence AND h.playthrough_id=scope.playthrough_id
            AND h.event_payload->>'type'='visual_result' AND h.event_payload->>'delivery_state'='derived'
        JOIN public.core_runtime_context origin ON origin.session_id=h.session_id AND origin.generation=h.generation
            AND origin.snapshot_sequence=h.context_sequence AND origin.playthrough_id=h.playthrough_id
            AND origin.target_identity_id=v.actor_identity_id
        JOIN public.core_request_execution e ON e.session_id=v.session_id AND e.request_id=v.request_id
            AND e.generation=v.generation AND e.event_type='visual_capture' AND e.state='complete'
            AND encode(sha256(e.response_body),'hex')=v.result_sha256
            AND convert_from(e.response_body,'UTF8')::jsonb=v.result_payload
        JOIN public.core_protocol_event p ON p.session_id=e.session_id AND p.request_id=e.request_id
            AND p.generation=e.generation AND p.turn_id=e.turn_id AND p.event_type=e.event_type AND p.event_sha256=e.request_sha256
            AND p.canonical_event->>'schema'='synth.visual_context.capture.v2'
            AND p.canonical_event->>'protocol_version'='2'
            AND p.canonical_event->>'session_id'=v.session_id AND p.canonical_event->>'request_id'=v.request_id
            AND p.canonical_event->>'generation'=v.generation::text AND p.canonical_event->>'turn_id'=e.turn_id
            AND p.canonical_event->>'context_sequence'=v.context_sequence::text
            AND p.canonical_event->>'capture_id'=v.capture_id
            AND p.canonical_event->>'interaction_mode'='describe'
            AND p.canonical_event->'world'=v.result_payload->'world'
            AND p.canonical_event->'media'=v.result_payload->'media'
            AND p.canonical_event->>'perspective'=v.result_payload->>'perspective'
            AND upper(p.canonical_event->'subject'->>'form_id')=upper(origin.context_payload->'target'->>'form_id')
            AND lower(p.canonical_event->'subject'->>'origin_plugin')=lower(origin.context_payload->'target'->>'origin_plugin')
            AND p.canonical_event->'subject'->>'playthrough_id'=h.playthrough_id
        JOIN public.core_visual_context gallery ON gallery.capture_id=v.capture_id
        LEFT JOIN LATERAL (SELECT edit.history_event_id,edit.description
            FROM native_history_scopes edited_scope JOIN public.core_history_event edited_history
                ON edited_history.session_id=edited_scope.session_id AND edited_history.generation=edited_scope.generation
                    AND edited_history.playthrough_id=edited_scope.playthrough_id AND edited_history.id<=edited_scope.cutoff
                    AND edited_history.request_id IS NULL AND edited_history.event_payload->>'type'='visual_manual'
                    AND edited_history.event_payload->>'delivery_state'='derived'
            JOIN public.core_visual_description_revision edit ON edit.history_event_id=edited_history.id
                AND edit.actor_identity_id=edited_scope.reader_identity_id AND edit.actor_identity_id=v.actor_identity_id
                AND edit.capture_id=v.capture_id AND edit.history_event_id>v.history_event_id
                AND edit.input_payload=edited_history.event_payload->'manual_correction'
                AND edit.input_hash=encode(sha256(convert_to(edit.input_payload::text,'UTF8')),'hex')
                AND edit.input_hash=edited_history.event_payload->>'visual_input_sha256'
                AND edit.input_payload->>'capture_id'=edit.capture_id
                AND edit.input_payload->>'actor_id'=edit.actor_identity_id::text
                AND edit.input_payload->'description'=COALESCE(to_jsonb(edit.description),'null'::jsonb)
            JOIN public.core_runtime_context edited_context ON edited_context.session_id=edited_history.session_id
                AND edited_context.generation=edited_history.generation AND edited_context.snapshot_sequence=edited_history.context_sequence
                AND edited_context.target_identity_id=edit.actor_identity_id AND edited_context.playthrough_id=edited_history.playthrough_id
            ORDER BY edit.history_event_id DESC LIMIT 1) correction ON TRUE)";
}

// Both current and restored snapshots read frozen accepted results, with only save-visible corrections.
function synthVisualContextSavedHistory($db, array $event, array $world, int $ttlMinutes): array
{
    if (($event['protocol_version'] ?? 1)!==2) return [];
    $location=$db->escapeLiteral(trim(strval($world['location'] ?? '')));
    $worldspace=$db->escapeLiteral(trim(strval($world['worldspace'] ?? '')));
    if ($location==="''" && $worldspace==="''") return [];
    $ttlMinutes=max(1,min($ttlMinutes,1440));
    return $db->fetchAll(synthVisualContextVisibleCte($db,$event)." SELECT subject_name,description,locked,user_edited,captured_at
        FROM native_visual_results WHERE active=TRUE
            AND ($location='' OR lower(result_payload->'world'->>'location')=lower($location))
            AND ($worldspace='' OR lower(result_payload->'world'->>'worldspace')=lower($worldspace))
            AND (locked=TRUE OR captured_at>=CURRENT_TIMESTAMP-INTERVAL '$ttlMinutes minutes')
        ORDER BY locked DESC,user_edited DESC,history_event_id DESC LIMIT 3") ?: [];
}

if (!function_exists('synthVisualContextList')) {
    function synthVisualContextList(int $limit = 250): array
    {
        $limit = max(1, min($limit, 1000));
        try {
            return \Synth\Core\DbManager\Dao\VisualContextDao::current()->listRecent($limit);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('synthVisualContextUpdate')) {
    function synthVisualContextUpdate(int $id, array $values): bool
    {
        if ($id < 1) {
            return false;
        }
        $visual = \Synth\Core\DbManager\Dao\VisualContextDao::current();
        $description = null;
        if (array_key_exists('description', $values)) {
            // Native descriptions are immutable observations; edits require an actor/save-scoped journal revision.
            $metadata=json_decode(strval($visual->metadataJson($id) ?? '{}'),true);
            if (($metadata['schema'] ?? '')==='synth.visual_context.capture.v2') {
                throw new LogicException('Use native visual corrections to edit this description.');
            }
            $description = synthVisualContextText($values['description'], 12000);
        }
        return $visual->edit($id, $description,
            array_key_exists('locked', $values) ? !empty($values['locked']) : null,
            array_key_exists('active', $values) ? !empty($values['active']) : null);
    }
}

if (!function_exists('synthVisualContextDelete')) {
    function synthVisualContextDelete(int $id): bool
    {
        return $id > 0 && \Synth\Core\DbManager\Dao\VisualContextDao::current()->delete($id);
    }
}

if (!function_exists('synthBuildVisualContextPrompt')) {
    function synthBuildVisualContextPrompt(array $worldPayload = []): string
    {
        $db = \Synth\Bootstrap::db() ?? null;
        if (!$db) {
            return '';
        }

        $event = \Synth\Bootstrap::requestEvent()['json'] ?? [];
        if (($event['protocol_version'] ?? 1) === 2) {
            require_once __DIR__ . '/visual_context_request.php';
            require_once dirname(__DIR__, 3).'/lib' . '/synth_protocol_state.php';
            if (!synth_protocol_current_context($db, $event)) return '';
            if (isset($event['payload']['capture_id'])) {
                try {
                    $capture = synth_visual_bound_capture($db, $event);
                } catch (DomainException $error) {
                    return '<visual_context>Requested PipVision capture is unavailable. Do not invent image contents.</visual_context>';
                }
                $limit = max(200, min(synthGetGeneralSettingInt('VISUAL_CONTEXT_PROMPT_MAX_CHARS', 1800), 12000));
                $body = htmlspecialchars(synthVisualContextText($capture['description'], $limit), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                return "<visual_context>\n# BOUND PIPVISION CAPTURE\n{$body}\n</visual_context>";
            }
        }

        $location = trim(strval($worldPayload['location'] ?? ''));
        $worldspace = trim(strval($worldPayload['worldspace'] ?? ''));
        $cellId = strtolower(trim(strval($worldPayload['cell_formid'] ?? '')));
        if ($location === '' && $worldspace === '' && $cellId === '') {
            return '';
        }

        $ttlMinutes = max(1, min(synthGetGeneralSettingInt('VISUAL_CONTEXT_SCENE_TTL_MINUTES', 10), 1440));
        $maxChars = max(200, min(synthGetGeneralSettingInt('VISUAL_CONTEXT_PROMPT_MAX_CHARS', 1800), 12000));

        try {
            $rows = ($event['protocol_version'] ?? 1)===2
                ? synthVisualContextSavedHistory($db,$event,$worldPayload,$ttlMinutes)
                : \Synth\Core\DbManager\Dao\VisualContextDao::current($db)->forPrompt($cellId, $location, $worldspace, $ttlMinutes);
        } catch (Throwable $e) {
            return '';
        }

        $entries = [];
        foreach ((array)$rows as $row) {
            $description = synthVisualContextText($row['description'] ?? '', $maxChars);
            if ($description === '') {
                continue;
            }
            $name = synthVisualContextText($row['subject_name'] ?? '', 120);
            $entries[] = ($name !== '' ? $name . ': ' : '') . $description;
        }
        if (!$entries) {
            return '';
        }

        $body = synthVisualContextText(implode("\n", $entries), $maxChars);
        $body = htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return "<visual_context>\n# RECENT PIPVISION CONTEXT\n{$body}\n</visual_context>";
    }
}
