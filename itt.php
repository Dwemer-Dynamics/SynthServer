<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

$enginePath = __DIR__ . DIRECTORY_SEPARATOR;
require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'request.php';
require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'media_store.php';
require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'visual_context_request.php';

function synthIttRespond(int $statusCode, bool $ok, array $request, array $extra = []): void
{
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    http_response_code($statusCode);
    echo json_encode(array_merge([
        'schema' => 'synth.visual_context.response.v1',
        'request_id' => strval($request['request_id'] ?? ''),
        'turn_id' => strval($request['turn_id'] ?? ''),
        'generation' => intval($request['generation'] ?? 0),
        'capture_id' => strval($request['capture_id'] ?? ''),
        'ok' => $ok,
        'status' => $ok ? 'success' : 'error',
    ], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
}

function synthIttStoredResponse(array $row): ?array
{
    $payload = $row['response_payload'] ?? null;
    if (is_string($payload)) {
        $payload = json_decode($payload, true);
    }
    return is_array($payload) && isset($payload['http_status'], $payload['ok'], $payload['extra'])
        ? $payload : null;
}

function synthIttComplete(int $recordId, int $status, bool $ok, array $extra, array $fields = [], ?callable $persistPortrait = null): void
{
    $fields['response_payload'] = [
        'http_status' => $status,
        'ok' => $ok,
        'extra' => $extra,
    ];
    synthVisualContextCompleteOwned($GLOBALS['request'], $recordId, $fields, $persistPortrait);
}

$request = [];
$recordId = 0;
try {
    $request = synth_visual_validate_request(synth_decode_json_body());
    $isDescribe = $request['interaction_mode'] === 'describe';

    require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php';
    synthRuntimeBootstrap($enginePath, [
        'load_general_settings' => true,
        'load_stt_connector' => false,
        'load_itt_connector' => $isDescribe,
        'run_db_updates' => false,
    ]);
    require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'logger.php';
    require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'visual_context.php';
    require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'synth_protocol_state.php';
    require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'npc_master.class.php';
    if ($isDescribe) {
        require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'itt_service.php';
    }
    Logger::bootstrapRequestId('itt-' . $request['request_id']);

    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        throw new SynthVisualRequestException(503, 'database_unavailable', 'Visual context database is unavailable.', true);
    }
    synth_visual_validate_runtime($db, $request);

    $canonical = synth_visual_canonical_json($request);
    $requestHash = hash('sha256', $canonical);
    $existing = synthVisualContextFindCapture($request['capture_id']);
    if ($existing) {
        if (!hash_equals(strval($existing['request_sha256'] ?? ''), $requestHash)) {
            throw new SynthVisualRequestException(409, 'duplicate_conflict', 'Capture ID was already used for another request.');
        }
        $stored = synthIttStoredResponse($existing);
        if ($stored === null) {
            throw new SynthVisualRequestException(409, 'request_in_progress', 'This visual request is already being processed.', true);
        }
        synthIttRespond(intval($stored['http_status']), boolval($stored['ok']), $request, array_merge(
            is_array($stored['extra']) ? $stored['extra'] : [],
            ['replayed' => true]
        ));
        exit;
    }

    $media = synth_media_resolve($enginePath, $request['media']['media_id'], [
        'image/jpeg', 'image/png', 'image/webp',
    ]);
    if (!hash_equals($media['sha256'], $request['media']['sha256'])
        || $media['content_type'] !== $request['media']['content_type']
        || $media['size'] !== $request['media']['size']) {
        throw new SynthVisualRequestException(409, 'media_reference_mismatch', 'Media reference does not match the published object.');
    }
    $mediaRow = $db->fetchOne(
        'SELECT content_type, byte_length, sha256, state FROM synth_meta.media_objects WHERE media_id='
        . $db->escapeLiteral($media['media_id']) . ' LIMIT 1'
    );
    if (!$mediaRow || strval($mediaRow['state'] ?? '') !== 'ready'
        || strval($mediaRow['content_type'] ?? '') !== $media['content_type']
        || intval($mediaRow['byte_length'] ?? -1) !== $media['size']
        || !hash_equals(strval($mediaRow['sha256'] ?? ''), $media['sha256'])) {
        throw new SynthVisualRequestException(409, 'media_not_ready', 'Media object is not ready for visual processing.', true);
    }

    $imageInfo = @getimagesize($media['path']);
    $width = intval($imageInfo[0] ?? 0);
    $height = intval($imageInfo[1] ?? 0);
    $detectedMime = strtolower(strval($imageInfo['mime'] ?? ''));
    if ($width < 1 || $height < 1 || $width > 8192 || $height > 8192
        || ($width * $height) > 32000000 || $detectedMime !== $media['content_type']) {
        throw new SynthVisualRequestException(422, 'invalid_image', 'Published media is not a valid bounded image.');
    }

    $subject = is_array($request['subject'] ?? null) ? $request['subject'] : [];
    $actorIdentityId = $subject !== [] ? synth_protocol_actor_identity_id($db, $subject) : 0;
    synth_media_register($db, $media, [
        'request_id' => $request['request_id'],
        'turn_id' => $request['turn_id'],
        'generation' => $request['generation'],
        'speaker_identity_id' => $actorIdentityId,
    ]);
    $world = $request['world'];
    $subjectType = synthVisualContextSubjectType($request['visual_type'] ?? ($subject !== [] ? 'actor' : 'scene'));
    $subjectName = synthVisualContextText($subject['display_name'] ?? '', 300);
    $subjectKey = synthVisualContextText($request['visual_key'] ?? '', 500);
    if ($subjectKey === '') {
        $subjectKey = $subject !== []
            ? 'actor:fo4:' . strtolower($subject['playthrough_id'] . ':' . $subject['origin_plugin'] . ':' . $subject['form_id'])
            : 'scene:' . strtolower($world['worldspace'] . ':' . $world['location'] . ':' . $world['cell_form_id']);
    }

    // This durable claim is committed before any connector/network work begins.
    $recordId = synthVisualContextStore([
        'capture_id' => $request['capture_id'],
        'session_id' => $request['session_id'],
        'request_id' => $request['request_id'],
        'turn_id' => $request['turn_id'],
        'generation' => $request['generation'],
        'runtime_variant' => $request['runtime_variant'],
        'interaction_mode' => $request['interaction_mode'],
        'actor_identity_id' => $actorIdentityId,
        'media_id' => $media['media_id'],
        'request_sha256' => $requestHash,
        'subject_type' => $subjectType,
        'subject_key' => $subjectKey,
        'subject_name' => $subjectName,
        'plugin' => $subject['origin_plugin'] ?? '',
        'refid' => $subject['form_id'] ?? '',
        'cell_id' => $world['cell_form_id'],
        'worldspace_id' => $world['worldspace_form_id'],
        'location_name' => $world['location'],
        'worldspace_name' => $world['worldspace'],
        'image_path' => '',
        'image_sha256' => $media['sha256'],
        'description' => '',
        'perspective' => $request['perspective'],
        'metadata' => $request,
    ]);
    if ($recordId < 1) {
        $existing = synthVisualContextFindCapture($request['capture_id']);
        if (!$existing) {
            throw new RuntimeException('Visual request claim could not be persisted.');
        }
        if (!hash_equals(strval($existing['request_sha256'] ?? ''), $requestHash)) {
            throw new SynthVisualRequestException(409, 'duplicate_conflict', 'Capture ID was already used for another request.');
        }
        $stored = synthIttStoredResponse($existing);
        if ($stored === null) {
            throw new SynthVisualRequestException(409, 'request_in_progress', 'This visual request is already being processed.', true);
        }
        synthIttRespond(intval($stored['http_status']), boolval($stored['ok']), $request, array_merge(
            is_array($stored['extra']) ? $stored['extra'] : [],
            ['replayed' => true]
        ));
        exit;
    }

    Logger::phaseStart('itt', [
        'capture_id' => $request['capture_id'],
        'mode' => $request['interaction_mode'],
        'media_id' => $media['media_id'],
        'bytes' => $media['size'],
        'width' => $width,
        'height' => $height,
    ]);

    if ($request['interaction_mode'] === 'npc_portrait') {
        $npcData = $db->fetchOne(
            'SELECT * FROM synth.core_npc_master WHERE actor_identity_id=' . $actorIdentityId . ' LIMIT 1'
        );
        if (!$npcData) {
            throw new SynthVisualRequestException(404, 'npc_profile_not_found', 'Targeted NPC profile was not found for this actor identity.');
        }
        $rawImage = @file_get_contents($media['path']);
        $source = is_string($rawImage) ? @imagecreatefromstring($rawImage) : false;
        if ($source === false) {
            throw new SynthVisualRequestException(422, 'invalid_image', 'Published image could not be decoded.');
        }
        $targetWidth = 512;
        $targetHeight = 768;
        $targetRatio = $targetWidth / $targetHeight;
        $sourceRatio = $width / $height;
        $cropWidth = $sourceRatio > $targetRatio ? max(1, intval(round($height * $targetRatio))) : $width;
        $cropHeight = $sourceRatio > $targetRatio ? $height : max(1, intval(round($width / $targetRatio)));
        $portrait = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($portrait === false || !imagecopyresampled(
            $portrait,
            $source,
            0,
            0,
            max(0, intval(($width - $cropWidth) / 2)),
            max(0, intval(($height - $cropHeight) / 2)),
            $targetWidth,
            $targetHeight,
            $cropWidth,
            $cropHeight
        )) {
            imagedestroy($source);
            if ($portrait !== false) {
                imagedestroy($portrait);
            }
            throw new RuntimeException('NPC portrait crop failed.');
        }
        ob_start();
        $encoded = imagejpeg($portrait, null, max(45, min(synthGetGeneralSettingInt('PIPVISION_IMAGE_QUALITY', 88), 95)));
        $portraitBytes = ob_get_clean();
        imagedestroy($source);
        imagedestroy($portrait);
        if (!$encoded || !is_string($portraitBytes) || $portraitBytes === '') {
            throw new RuntimeException('NPC portrait encoding failed.');
        }
        synth_visual_validate_runtime($db, $request);
        $portraitMedia = synth_media_publish_bytes($enginePath, $portraitBytes, 'image/jpeg');
        synth_media_register($db, $portraitMedia, [
            'request_id' => $request['request_id'],
            'turn_id' => $request['turn_id'],
            'generation' => $request['generation'],
            'speaker_identity_id' => $actorIdentityId,
        ]);

        $portraitReference = [
            'schema' => 'synth.media.v1',
            'media_id' => $portraitMedia['media_id'],
            'content_type' => $portraitMedia['content_type'],
            'sha256' => $portraitMedia['sha256'],
            'size' => $portraitMedia['size'],
        ];
        $extra = [
            'record_id' => $recordId,
            'actor' => $subject,
            'portrait_media' => $portraitReference,
        ];
        synthIttComplete($recordId, 200, true, $extra, [], static function () use ($db, $actorIdentityId, $portraitMedia): void {
            $current = $db->fetchOne('SELECT * FROM synth.core_npc_master WHERE actor_identity_id=' . $actorIdentityId . ' FOR UPDATE');
            if (!$current) throw new RuntimeException('NPC portrait profile disappeared.');
            $metadata = json_decode(strval($current['metadata'] ?? '{}'), true);
            $metadata = is_array($metadata) ? $metadata : [];
            $metadata['portrait_media_id'] = $portraitMedia['media_id'];
            if ((new NpcMaster())->update(intval($current['id']), [
                'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ], $current) === false) throw new RuntimeException('NPC portrait metadata update failed.');
        });
        Logger::phaseEnd('itt', ['capture_id' => $request['capture_id'], 'record_id' => $recordId]);
        synthIttRespond(200, true, $request, $extra);
        exit;
    }

    $description = '';
    $provider = '';
    $model = '';
    if ($request['interaction_mode'] === 'describe') {
        $connectorMetadata = [
            'worldspace' => $world['worldspace'],
            'location' => $world['location'],
            'subject' => ['name' => $subjectName],
            'nearby_actors' => array_values(array_filter(array_map(
                static fn(array $identity): string => trim(strval($identity['display_name'] ?? '')),
                $request['nearby_actors']
            ))),
        ];
        $result = synthIttDescribe($media['path'], $connectorMetadata);
        synth_visual_validate_runtime($db, $request);
        $description = $result['description'];
        $provider = $result['provider'];
        $model = $result['model'];
    }
    $extra = ['record_id' => $recordId];
    if ($description !== '') {
        $extra += ['description' => $description, 'provider' => $provider, 'model' => $model];
    }
    if ($request['interaction_mode'] === 'store_only') {
        synth_visual_validate_runtime($db, $request);
    }
    synthIttComplete($recordId, 200, true, $extra, [
        'description' => $description,
        'provider' => $provider,
        'model' => $model,
    ]);
    Logger::phaseEnd('itt', [
        'capture_id' => $request['capture_id'],
        'record_id' => $recordId,
        'provider' => $provider,
        'model' => $model,
    ]);
    synthIttRespond(200, true, $request, $extra);
} catch (SynthVisualRequestException $e) {
    $extra = ['code' => $e->errorCode, 'error' => $e->getMessage(), 'retryable' => $e->retryable];
    if ($recordId > 0 && function_exists('synthVisualContextComplete')) {
        synthVisualContextComplete($recordId, [
            'response_payload' => ['http_status' => $e->httpStatus, 'ok' => false, 'extra' => $extra],
        ]);
    }
    if (class_exists('Logger')) {
        Logger::error('[ITT] ' . $e->errorCode . ': ' . $e->getMessage());
    }
    synthIttRespond($e->httpStatus, false, $request, $extra);
} catch (SynthMediaException $e) {
    $extra = ['code' => $e->errorCode, 'error' => $e->getMessage(), 'retryable' => false];
    if ($recordId > 0 && function_exists('synthVisualContextComplete')) {
        synthVisualContextComplete($recordId, [
            'response_payload' => ['http_status' => $e->httpStatus, 'ok' => false, 'extra' => $extra],
        ]);
    }
    if (class_exists('Logger')) {
        Logger::error('[ITT] ' . $e->errorCode . ': ' . $e->getMessage());
    }
    synthIttRespond($e->httpStatus, false, $request, $extra);
} catch (Throwable $e) {
    $extra = ['code' => 'itt_failed', 'error' => 'PipVision processing failed.', 'retryable' => true];
    if ($recordId > 0 && function_exists('synthVisualContextComplete')) {
        synthVisualContextComplete($recordId, [
            'response_payload' => ['http_status' => 502, 'ok' => false, 'extra' => $extra],
        ]);
    }
    if (class_exists('Logger')) {
        Logger::error('[ITT] ' . $e->getMessage());
    }
    synthIttRespond(502, false, $request, $extra);
}
