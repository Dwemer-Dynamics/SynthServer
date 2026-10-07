<?php

require_once __DIR__.'/synth_protocol_state.php';
require_once __DIR__.'/media_store.php';

// A cache hit must be usable audio, not a truncated/error body that permanently prevents synthesis.
function synth_speech_cache_valid(string $path): bool
{
    synth_protocol_assert_request_active();
    clearstatcache(true,$path);
    if (is_link($path) || !is_file($path)) return false;
    $size=@filesize($path);
    if (!is_int($size) || $size<=44 || $size>SYNTH_MEDIA_MAX_BYTES) return false;
    // Bound the read even if another process grows/replaces the file after the size check.
    $bytes=@file_get_contents($path,false,null,0,SYNTH_MEDIA_MAX_BYTES+1);
    if (!is_string($bytes) || strlen($bytes)!==$size) return false;
    try { synth_media_validate_bytes($bytes,'audio/wav'); }
    catch (SynthMediaException) { return false; }
    synth_protocol_assert_request_active();
    return true;
}

// Publish an already valid WAV without re-encoding it or exposing a partial public file.
function synth_publish_speech_cache(string $bytes, string $target): bool
{
    $directory=realpath(dirname($target));
    if ($directory===false || basename($directory)!=='soundcache'
        || !preg_match('/^[a-f0-9]{32}\.wav$/D',basename($target)) || is_link($target)) {
        throw new InvalidArgumentException('Invalid speech publication request');
    }
    if ($bytes==='' || strlen($bytes)>SYNTH_MEDIA_MAX_BYTES) return false;
    try { synth_media_validate_bytes($bytes,'audio/wav'); }
    catch (SynthMediaException) { return false; }
    synth_protocol_assert_request_active();
    $private=dirname($directory).'/data/tmp/npc_tts_jobs';
    if (!is_dir($private) && !@mkdir($private,0775,true) && !is_dir($private)) return false;
    $output=tempnam($private,'.speech-output-');
    if ($output===false) return false;
    try {
        if (file_put_contents($output,$bytes,LOCK_EX)!==strlen($bytes)) return false;
        return synth_commit_speech_cache_file($output,$directory.DIRECTORY_SEPARATOR.basename($target));
    } finally {
        if (is_file($output)) @unlink($output);
    }
}

// Internal final boundary shared by validated direct WAV and completed converter output.
function synth_commit_speech_cache_file(string $output, string $target): bool
{
    $publish=static function() use($output,$target): bool {
        synth_protocol_assert_request_active();
        @chmod($output,0664);
        return @rename($output,$target);
    };
    $event=$GLOBALS['SYNTH_REQUEST_EVENT']['json'] ?? [];
    if (!empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']) && ($event['protocol_version'] ?? 1)===2) {
        // Claim/cancel/save-load transitions use this same session lock; no provider or conversion runs inside it.
        $db=$GLOBALS['db'] ?? null;
        if (!is_object($db) || !method_exists($db,'withNativeRequestLock')) throw new LogicException('Speech publication requires its native owner lock');
        return $db->withNativeRequestLock($publish,2000);
    }
    return $publish();
}

// Convert outside the public cache, cancel the direct child promptly, and publish only under current ownership.
function synth_transcode_speech_cache(string $bytes, string $target, string $filter, ?string $ffmpeg = null, int $timeoutMs = 30000, ?array $pcmInput = null): bool
{
    // Raw provider PCM has a typed input contract and the established 22050 Hz mono output.
    $inputArgs=[];
    $outputArgs=[];
    if ($pcmInput!==null) {
        if (array_diff(array_keys($pcmInput),['format','sample_rate','channels'])
            || ($pcmInput['format'] ?? '')!=='s16le'
            || !is_int($pcmInput['sample_rate'] ?? null) || $pcmInput['sample_rate']<8000 || $pcmInput['sample_rate']>192000
            || !in_array($pcmInput['channels'] ?? null,[1,2],true)
            || strlen($bytes) % (2*$pcmInput['channels'])!==0) {
            throw new InvalidArgumentException('Invalid speech PCM format');
        }
        $inputArgs=['-f','s16le','-ar',strval($pcmInput['sample_rate']),'-ac',strval($pcmInput['channels'])];
        $outputArgs=['-c:a','pcm_s16le','-ar','22050','-ac','1'];
    }
    if ($ffmpeg===null) {
        require_once __DIR__.'/voice_clone_resolver.php';
        $ffmpeg=synth_voice_clone_ffmpeg_path();
    }
    $directory=realpath(dirname($target));
    if ($directory===false || basename($directory)!=='soundcache'
        || !preg_match('/^[a-f0-9]{32}\.wav$/D',basename($target)) || is_link($target)
        || $bytes==='' || strlen($bytes)>SYNTH_MEDIA_MAX_BYTES || $timeoutMs<1 || $timeoutMs>120000) {
        throw new InvalidArgumentException('Invalid speech conversion request');
    }
    $target=$directory.DIRECTORY_SEPARATOR.basename($target);
    $private=dirname($directory).'/data/tmp/npc_tts_jobs';
    if (!is_dir($private) && !@mkdir($private,0775,true) && !is_dir($private)) return false;
    synth_protocol_assert_request_active();
    $input=tempnam($private,'.transcode-input-');
    $output=tempnam($private,'.transcode-output-');
    $process=null;
    try {
        if ($input===false || $output===false) return false;
        if (file_put_contents($input,$bytes,LOCK_EX)!==strlen($bytes)) return false;
        $null=PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null';
        // An argument array bypasses the shell; cancellation owns the actual converter process.
        $process=proc_open(array_merge([$ffmpeg,'-nostdin','-v','error','-y'],$inputArgs,['-i',$input,'-af',$filter],$outputArgs,['-f','wav',$output]),
            [0=>['file',$null,'r'],1=>['file',$null,'w'],2=>['file',$null,'w']],$pipes,null,null,['bypass_shell'=>true]);
        if (!is_resource($process)) return false;
        $deadline=hrtime(true)+$timeoutMs*1000000;
        do {
            synth_protocol_assert_request_active();
            clearstatcache(true,$output);
            if (@filesize($output)>SYNTH_MEDIA_MAX_BYTES) return false;
            $state=proc_get_status($process);
            if (!$state['running']) break;
            if (hrtime(true)>=$deadline) return false;
            usleep(50000);
        } while (true);
        if ($state['exitcode']!==0) return false;
        $audio=file_get_contents($output);
        if (!is_string($audio)) return false;
        try { synth_media_validate_bytes($audio,'audio/wav'); }
        catch (SynthMediaException) { return false; }
        return synth_commit_speech_cache_file($output,$target);
    } finally {
        if (is_resource($process)) {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
                $stopDeadline=hrtime(true)+250000000;
                while (proc_get_status($process)['running'] && hrtime(true)<$stopDeadline) usleep(10000);
                if (proc_get_status($process)['running']) proc_terminate($process,9);
            }
            proc_close($process);
        }
        foreach ([$input,$output] as $temporary) if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
    }
}
