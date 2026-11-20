<?php

function synthLogFfmpegError($message)
{
    if (class_exists('Logger')) {
        Logger::error($message);
    }
}

function synthResolveFfmpegPath()
{
    $candidates = [];

    if (!empty($GLOBALS["FFMPEG_PATH"])) {
        $candidates[] = $GLOBALS["FFMPEG_PATH"];
    }

    $envPath = getenv('FFMPEG_PATH');
    if (!empty($envPath)) {
        $candidates[] = $envPath;
    }

    $candidates[] = 'C:\\Program Files\\ShareX\\ffmpeg.exe';
    $candidates[] = 'C:\\ffmpeg\\bin\\ffmpeg.exe';
    $candidates[] = 'ffmpeg';

    foreach ($candidates as $candidate) {
        if ($candidate === 'ffmpeg' || file_exists($candidate)) {
            return $candidate;
        }
    }

    return 'ffmpeg';
}

function synthRunFfmpeg(array $args)
{
    $ffmpeg = synthResolveFfmpegPath();
    $command = (PHP_OS_FAMILY === 'Linux' ? 'timeout --signal=KILL 25s ' : '')
        . escapeshellarg($ffmpeg) . ' -nostdin -v error -y';
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }
    $command .= ' 2>&1';

    $output = [];
    $returnVar = 0;
    exec($command, $output, $returnVar);

    if ($returnVar !== 0) {
        synthLogFfmpegError("ffmpeg conversion failed ({$returnVar}): {$command} :: " . implode("\n", $output));
    }

    return $returnVar;
}

function synthNormalizeVoiceSampleToWav($inputFile, $outputFile, $inputFormat = '')
{
    if (!is_file($inputFile) || filesize($inputFile) <= 0) {
        return '';
    }

    $args = [];
    if ($inputFormat !== '') {
        $args[] = '-f';
        $args[] = $inputFormat;
    }
    $args[] = '-i';
    $args[] = $inputFile;
    $args[] = '-vn';
    $args[] = '-af';
    $args[] = 'atrim=duration=15,highpass=f=45,lowpass=f=10000,loudnorm=I=-23:LRA=7:TP=-5';
    $args[] = '-ar';
    $args[] = '22050';
    $args[] = '-ac';
    $args[] = '1';
    $args[] = '-c:a';
    $args[] = 'pcm_s16le';
    $args[] = $outputFile;

    $returnVar = synthRunFfmpeg($args);
    return ($returnVar === 0 && is_file($outputFile) && filesize($outputFile) > 44) ? $outputFile : '';
}

// FUZ v1 is a bounded header, lip block, then XWMA audio. Never print into HTTP JSON.
function fuzToWav($fuzFileName)
{
    if (!is_file($fuzFileName) || filesize($fuzFileName) < 24 || filesize($fuzFileName) > 8388608) return '';
    $bytes = file_get_contents($fuzFileName);
    if (substr($bytes, 0, 4) !== 'FUZE') return '';
    $header = unpack('Vversion/Vlip_bytes', substr($bytes, 4, 8));
    if ($header['version'] !== 1 || $header['lip_bytes'] > strlen($bytes) - 24) return '';
    $audio = substr($bytes, 12 + $header['lip_bytes']);
    if (substr($audio, 0, 4) !== 'RIFF') return '';
    $temporary = tempnam(sys_get_temp_dir(), 'synth_xwm_');
    if ($temporary === false) return '';
    try {
        if (file_put_contents($temporary, $audio) !== strlen($audio)) return '';
        $output = dirname($fuzFileName) . DIRECTORY_SEPARATOR . pathinfo($fuzFileName, PATHINFO_FILENAME) . '.wav';
        return synthNormalizeVoiceSampleToWav($temporary, $output, 'xwma');
    } finally {
        @unlink($temporary);
    }
}

function xwmToWav($file)
{
    $output = dirname($file) . DIRECTORY_SEPARATOR . pathinfo($file, PATHINFO_FILENAME) . '.wav';
    return synthNormalizeVoiceSampleToWav($file, $output, 'xwma');
}

function wavToWav($fuzFileName)
{
    if (file_exists($fuzFileName)) {
        // Output Folder path
        $sOutputPath = dirname($fuzFileName);

        // Convert the *.fuz file to *.xwm
        $sOutputXwmFile = pathinfo($fuzFileName, PATHINFO_FILENAME) . '_normalized.wav';

        // Store the new full path for the xwm
        $sOutputXwm = $sOutputPath . DIRECTORY_SEPARATOR . $sOutputXwmFile;

        // Extract the xwm data from the fuz data file
        if (!file_exists($sOutputXwm) || true) {
            return synthNormalizeVoiceSampleToWav($fuzFileName, $sOutputXwm);
			
        } else {
            return "";
        }
        
    }
     return "";
}

function oggToWav($oggFileName)
{
    if (file_exists($oggFileName)) {
        $sOutputPath = dirname($oggFileName);
        $sOutputWavFile = basename($oggFileName, '.ogg') . '.wav';
        $sOutputWav = $sOutputPath . DIRECTORY_SEPARATOR . $sOutputWavFile;

        return synthNormalizeVoiceSampleToWav($oggFileName, $sOutputWav);
    }

    return "";
}




