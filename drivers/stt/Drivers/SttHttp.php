<?php

declare(strict_types=1);

namespace Synth\Drivers\Stt\Drivers;

use Synth\Drivers\Stt\Exception\SttException;

require_once dirname(__DIR__, 3) . '/lib/provider_stream.php';

/** Transport shared by the STT drivers; goes through the request-guarded speech helpers. */
final class SttHttp
{
    /**
     * Sends a multipart/form-data upload built by hand and returns [body, status, headers] or throws.
     *
     * @param list<array{name:string,value:string,filename?:string,type?:string,binary?:bool}> $parts
     * @param array<string,string> $headers extra headers
     * @return array{0:string,1:int,2:list<string>}
     */
    public static function multipart(string $url, array $parts, array $headers, int $timeout, bool $ignoreErrors = false): array
    {
        $boundary = '----WebKitFormBoundary' . md5(mt_rand() . microtime());
        $body = '';
        foreach ($parts as $part) {
            $body .= "--{$boundary}\r\n" . 'Content-Disposition: form-data; name="' . $part['name'] . '"'
                . (isset($part['filename']) ? '; filename="' . $part['filename'] . '"' : '') . "\r\n"
                . (isset($part['type']) ? 'Content-Type: ' . $part['type'] . "\r\n" : '')
                . (!empty($part['binary']) ? "Content-Transfer-Encoding: binary\r\n" : '')
                . "\r\n" . $part['value'] . "\r\n";
        }
        $body .= "--{$boundary}--\r\n";
        $header = '';
        foreach ($headers as $line) {
            $header .= $line . "\r\n";
        }
        $header .= "Content-Type: multipart/form-data; boundary={$boundary}\r\nContent-Length: " . strlen($body) . "\r\n";
        $http = ['method' => 'POST', 'header' => $header, 'content' => $body, 'timeout' => $timeout];
        if ($ignoreErrors) {
            $http['ignore_errors'] = true;
        }
        $responseHeaders = [];
        $response = @synth_speech_fetch($url, stream_context_create(['http' => $http]), $responseHeaders);
        $status = 0;
        foreach ($responseHeaders as $line) {
            if (stripos((string) $line, 'HTTP/') === 0 && preg_match('/\s(\d{3})(?:\s|$)/', (string) $line, $m)) {
                $status = (int) $m[1];
            }
        }
        if ($response === false) {
            throw new SttException('The speech provider could not be reached.', ['stage' => 'transport', 'http_status_code' => $status, 'headers' => $responseHeaders]);
        }

        return [$response, $status, $responseHeaders];
    }

    /**
     * POSTs a body with cURL through the guarded executor and returns [body, status].
     *
     * @param list<string> $headers
     * @return array{0:string,1:int}
     */
    public static function curlPost(string $url, string $body, array $headers, int $timeoutSeconds, int $connectTimeout = 0): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        if ($connectTimeout > 0) {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        }
        $diagnostic = [];
        $response = synth_speech_curl_exec($ch, $timeoutSeconds * 1000, $diagnostic);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false) {
            throw new SttException('The speech provider could not be reached: ' . (string) ($diagnostic['error'] ?? ''));
        }

        return [$response, $status];
    }

    /** Reads the recording or throws. */
    public static function readAudio(string $path): string
    {
        $bytes = is_file($path) && is_readable($path) ? @file_get_contents($path) : false;
        if ($bytes === false) {
            throw new SttException('Audio file is missing or unreadable.', ['stage' => 'input', 'file_path' => $path]);
        }

        return $bytes;
    }
}
