<?php

/**
 * SYNTH logger: classifier + level, caller line, redaction, named file sinks with rotation.
 *
 * Shape follows chevo's Chevo\Lib\Logger (required classifier, level, caller file:line, error_log), written for
 * SynthServer's needs: it also keeps the DwemerDistro log files under log/ (synth.log and the other named sinks),
 * size rotation, the per-request id, and the single PHP error/exception handler. The old global `Logger` class in
 * lib/logger.php is a thin shim over this class.
 */
declare(strict_types=1);

namespace Synth\Lib;

final class Logger
{
    public const ERROR = 1;
    public const WARN = 2;
    public const INFO = 3;
    public const DEBUG = 4;
    public const TRACE = 5;

    private const LEVEL_NAMES = [
        self::ERROR => 'error',
        self::WARN => 'warn',
        self::INFO => 'info',
        self::DEBUG => 'debug',
        self::TRACE => 'trace',
    ];

    /** Names the old logger accepted, mapped to levels (legacy shim and setLevel('warn')). */
    private const LEVEL_BY_NAME = [
        'error' => self::ERROR,
        'warn' => self::WARN,
        'info' => self::INFO,
        'debug' => self::DEBUG,
        'trace' => self::TRACE,
    ];

    /** Named DwemerDistro sinks that are rotated at request start. */
    private const KNOWN_LOGS = [
        'synth.log',
        'debugStream.log',
        'debugStreamParsed.log',
        'output_from_llm.log',
        'output_from_llm_fast.log',
        'output_from_llm_fast_step_2.log',
        'output_to_plugin.log',
        'context_sent_to_llm.log',
        'context_sent_to_llm_fast.log',
        'bug_func.txt',
        'manager.log',
        'monitor.log',
    ];

    private const MAX_LOG_SIZE = 10485760;
    private const MAX_ROTATED_LOGS = 3;

    /** Array/object keys whose values are never written. Compared lower-case with `-`/space folded to `_`. */
    private const SECRET_KEYS = [
        'api_key', 'apikey', 'key', 'secret', 'client_secret', 'token', 'access_token', 'refresh_token', 'id_token',
        'password', 'passwd', 'authorization', 'auth', 'bearer', 'x_api_key', 'private_key', 'session_token',
        'xi_api_key', 'cookie', 'set_cookie',
    ];

    private static int $level = self::TRACE;
    private static string $timestampFormat = 'Y-m-d\TH:i:sP';
    private static int $backtraceLevel = 0;
    private static ?string $customLog = null;
    private static string $requestId = '';
    private static array $phaseStarts = [];
    private static array $rotatedThisRequest = [];
    private static array $failedTargets = [];
    private static bool $fileWritesSuspended = false;
    private static bool $handling = false;
    private static bool $handlersInstalled = false;
    private static ?string $handlerLog = null;

    // ----- levels and configuration -------------------------------------------------------------------------

    /** Sets the most verbose level written. Lower numbered levels are always included. Accepts a level or its name. */
    public static function setLevel(int|string $level): void
    {
        $resolved = self::resolveLevel($level);
        if ($resolved === null) {
            error_log('[error] Invalid log level specified: ' . (string)$level);
            return;
        }
        self::$level = $resolved;
    }

    public static function setTimestampFormat(string $format = ''): void
    {
        self::$timestampFormat = $format;
    }

    /** Attach a stack trace to entries at this level or more severe; null or an unknown name disables it. */
    public static function setBacktrace(int|string|null $level): void
    {
        $resolved = $level === null ? null : self::resolveLevel($level);
        self::$backtraceLevel = $resolved ?? 0;
    }

    public static function setCustomLog(?string $logFile): void
    {
        self::$customLog = ($logFile === null || $logFile === '') ? null : $logFile;
    }

    public static function unsetCustomLog(): void
    {
        self::$customLog = null;
    }

    /** Read-only requests: no log file is created, appended or rotated for the rest of the request. */
    public static function suspendFileWrites(): void
    {
        self::$fileWritesSuspended = true;
    }

    // ----- request id ---------------------------------------------------------------------------------------

    public static function setRequestId(mixed $requestId): void
    {
        $requestId = trim((string)$requestId);
        if ($requestId === '') {
            return;
        }
        $requestId = (string)preg_replace('/[^A-Za-z0-9_.:-]/', '_', $requestId);
        self::$requestId = substr($requestId, 0, 96);
        $GLOBALS['SYNTH_REQUEST_ID'] = self::$requestId;
    }

    public static function getRequestId(): string
    {
        if (self::$requestId !== '') {
            return self::$requestId;
        }
        if (!empty($GLOBALS['SYNTH_REQUEST_ID'])) {
            self::setRequestId($GLOBALS['SYNTH_REQUEST_ID']);
            return self::$requestId;
        }
        return '';
    }

    public static function ensureRequestId(string $prefix = 'req'): string
    {
        $existing = self::getRequestId();
        if ($existing !== '') {
            return $existing;
        }
        self::setRequestId($prefix . '_' . str_replace('.', '', uniqid('', true)));
        return self::$requestId;
    }

    public static function bootstrapRequestId(string $prefix = 'req'): string
    {
        $candidate = $_SERVER['HTTP_X_SYNTH_REQUEST_ID'] ?? $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
        if ($candidate !== '') {
            self::setRequestId($candidate);
        }
        return self::ensureRequestId($prefix);
    }

    // ----- logging ------------------------------------------------------------------------------------------

    /**
     * Logs a value with a level, a mandatory classifier, a timestamp and the caller's file:line.
     * Written to the SYNTH log file ($logFile, default log/synth.log) and to error_log().
     */
    public static function log(int $level, string $classifier, mixed $data, ?string $logFile = null): void
    {
        if (trim($classifier) === '') {
            throw new \InvalidArgumentException('A log classifier is required.');
        }
        self::emit($level, $classifier, $data, $logFile, true, 0);
    }

    /**
     * Entry point for the legacy global Logger shim: the classifier is the calling file's name, the legacy
     * destination rules apply (info and below stay file-only, warn and error also reach error_log), and the
     * caller line is the shim's caller.
     */
    public static function legacy(string $levelName, mixed $data, ?string $logFile): void
    {
        $level = self::LEVEL_BY_NAME[$levelName] ?? self::INFO;
        self::emit($level, '', $data, $logFile, $level <= self::WARN, 1);
    }

    public static function phaseStart(string $phase, array $context = [], int $depth = 0): void
    {
        $phase = trim($phase);
        if ($phase === '') {
            return;
        }
        self::$phaseStarts[$phase] = microtime(true);
        self::emit(self::DEBUG, '', '[phase:start] ' . $phase . self::formatContext($context), null, false, $depth);
    }

    public static function phaseEnd(string $phase, array $context = [], string $level = 'debug', int $depth = 0): void
    {
        $phase = trim($phase);
        if ($phase === '') {
            return;
        }
        if (isset(self::$phaseStarts[$phase])) {
            $elapsedMs = round((microtime(true) - self::$phaseStarts[$phase]) * 1000, 2);
            unset(self::$phaseStarts[$phase]);
            $context = array_merge(['elapsed_ms' => $elapsedMs], $context);
        }
        $resolved = self::LEVEL_BY_NAME[$level] ?? self::DEBUG;
        self::emit($resolved, '', '[phase:end] ' . $phase . self::formatContext($context), null, $resolved <= self::WARN, $depth);
    }

    // ----- payload helpers ----------------------------------------------------------------------------------

    public static function summarizePayload(mixed $value, int $maxTextLength = 180): mixed
    {
        if (is_array($value)) {
            $summary = [];
            foreach ($value as $key => $item) {
                if (self::isSecretKey((string)$key)) {
                    $summary[$key] = '[REDACTED]';
                } elseif (is_array($item)) {
                    $summary[$key] = ['type' => array_is_list($item) ? 'list' : 'object', 'count' => count($item)];
                } elseif (is_string($item)) {
                    $summary[$key] = self::truncateText(self::redact($item), $maxTextLength);
                } elseif (is_bool($item) || is_int($item) || is_float($item) || $item === null) {
                    $summary[$key] = $item;
                } else {
                    $summary[$key] = gettype($item);
                }
            }
            return $summary;
        }
        if (is_string($value)) {
            return self::truncateText(self::redact($value), $maxTextLength);
        }
        return $value;
    }

    public static function formatContext(array $context): string
    {
        if ($context === []) {
            return '';
        }
        $parts = [];
        foreach ($context as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = json_encode(self::summarizePayload($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $value = 'null';
            } else {
                $value = (string)$value;
            }
            if (self::isSecretKey((string)$key)) {
                $value = '[REDACTED]';
            }
            $parts[] = $key . '=' . self::truncateText(self::redact((string)$value), 220);
        }
        return ' ' . implode(' ', $parts);
    }

    /**
     * Masks credentials in free text: bearer tokens, key-shaped provider tokens, `key=value` query/form pairs and
     * JSON or array-style "api_key": "value" fields.
     */
    public static function redact(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        $names = '(?:api[_-]?key|x-api-key|xi-api-key|access[_-]?token|refresh[_-]?token|id[_-]?token|client[_-]?secret|secret|token|password|passwd|authorization|private[_-]?key|key)';
        $patterns = [
            // Authorization: Bearer abc / Basic abc
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i' => '$1 [REDACTED]',
            // "api_key": "value"  /  'token' => 'value'
            '/(["\']' . $names . '["\']\s*(?::|=>)\s*)(["\'])(?:\\\\.|(?!\2).)*\2/i' => '$1$2[REDACTED]$2',
            // api_key=value  /  token: value  /  ?key=value
            '/\b(' . $names . ')(\s*[=:]\s*)(?!\[REDACTED\])("[^"]*"|\'[^\']*\'|[^\s&,;"\'}\]]+)/i' => '$1$2[REDACTED]',
            // Provider-shaped tokens
            '/\b(?:sk|pk|rk|xai|gsk|hf|r8)[-_][A-Za-z0-9_-]{16,}\b/' => '[REDACTED]',
            '/\bAIza[0-9A-Za-z_-]{30,}\b/' => '[REDACTED]',
        ];
        foreach ($patterns as $pattern => $replacement) {
            $replaced = preg_replace($pattern, $replacement, $text);
            if (is_string($replaced)) {
                $text = $replaced;
            }
        }
        return $text;
    }

    // ----- file sinks and rotation ----------------------------------------------------------------------------

    public static function rotateKnownLogs(?string $root = null): void
    {
        $root = $root ?: self::root();
        $logDir = rtrim($root, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . 'log';
        foreach (self::KNOWN_LOGS as $name) {
            self::rotateLogIfTooLarge($logDir . DIRECTORY_SEPARATOR . $name);
        }
    }

    public static function deleteLogIfTooLarge(?string $logFile = null, int $maxSize = 26214400): void
    {
        $logFile = self::resolveLogFile($logFile);
        if (is_file($logFile) && filesize($logFile) > $maxSize) {
            self::rotateLogIfTooLarge($logFile, $maxSize);
        }
    }

    public static function rotateLogIfTooLarge(string $logFile, ?int $maxSize = null): void
    {
        $maxSize ??= self::MAX_LOG_SIZE;
        if (self::$fileWritesSuspended || isset(self::$rotatedThisRequest[$logFile])) {
            return;
        }
        self::$rotatedThisRequest[$logFile] = true;
        if (!is_file($logFile)) {
            return;
        }
        clearstatcache(true, $logFile);
        $size = @filesize($logFile);
        if ($size === false || $size <= $maxSize) {
            return;
        }
        for ($i = self::MAX_ROTATED_LOGS; $i >= 1; $i--) {
            $from = $i === 1 ? $logFile : $logFile . '.' . ($i - 1);
            $to = $logFile . '.' . $i;
            if (is_file($to)) {
                @unlink($to);
            }
            if (is_file($from)) {
                @rename($from, $to);
            }
        }
        @touch($logFile);
    }

    // ----- PHP error and exception handling (installed once) ------------------------------------------------------

    /**
     * Installs the one PHP error handler, uncaught-exception handler and fatal-error shutdown hook. Safe to call
     * from every entry point: only the first call installs. Temporary capture handlers installed later (tests,
     * connector probes) stack on top of this one and restore it.
     */
    public static function installHandlers(): void
    {
        if (self::$handlersInstalled) {
            return;
        }
        self::$handlersInstalled = true;
        set_error_handler([self::class, 'handleError']);
        if (defined('PHPUNIT_COMPOSER_INSTALL') || class_exists('PHPUnit\\Framework\\TestCase', false)) {
            return; // PHPUnit owns exception and shutdown reporting
        }
        $previous = set_exception_handler(null);
        set_exception_handler(static function (\Throwable $e) use ($previous): void {
            self::handleException($e);
            if ($previous !== null) {
                $previous($e);
                return;
            }
            // PHP reports the uncaught exception itself (error_log) once the handler is removed and it is rethrown.
            restore_exception_handler();
            throw $e;
        });
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /** Daemons with their own log (the relationship worker) route handler output there instead of synth.log. */
    public static function setHandlerLog(?string $logFile): void
    {
        self::$handlerLog = ($logFile === null || $logFile === '') ? null : $logFile;
    }

    /** Writes PHP errors to the SYNTH log; returns false so PHP's own error log still runs. */
    public static function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        if (self::$handling || (error_reporting() & $errno) === 0) {
            return false;
        }
        self::$handling = true;
        try {
            $level = match ($errno) {
                E_USER_ERROR, E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR => self::ERROR,
                E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => self::WARN,
                E_NOTICE, E_USER_NOTICE => self::INFO,
                default => self::DEBUG,
            };
            if ($level <= self::$level) {
                self::writeEntry($level, 'php', $errstr . ' in ' . basename($errfile) . ':' . $errline, self::$handlerLog, false, $errfile, $errline);
            }
        } catch (\Throwable) {
            // a logging failure must never turn into a PHP error
        } finally {
            self::$handling = false;
        }
        return false;
    }

    public static function handleException(\Throwable $e): void
    {
        if (self::$handling) {
            return;
        }
        self::$handling = true;
        try {
            $message = get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString();
            self::writeEntry(self::ERROR, 'uncaught', $message, self::$handlerLog, false, $e->getFile(), $e->getLine());
        } catch (\Throwable) {
        } finally {
            self::$handling = false;
        }
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        if (str_starts_with($error['message'], 'Uncaught ')) {
            return; // already written by handleException()
        }
        self::writeEntry(self::ERROR, 'fatal', $error['message'] . ' in ' . basename($error['file']) . ':' . $error['line'], self::$handlerLog, false, $error['file'], $error['line']);
    }

    // ----- internals ----------------------------------------------------------------------------------------

    private static function emit(int $level, string $classifier, mixed $data, ?string $logFile, bool $toErrorLog, int $depth): void
    {
        if (!isset(self::LEVEL_NAMES[$level])) {
            throw new \InvalidArgumentException('Unknown log level.');
        }
        if ($level > self::$level) {
            return;
        }
        $classifier = trim($classifier);
        $frame = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $depth + 2)[$depth + 1] ?? [];
        $callerFile = is_string($frame['file'] ?? null) ? $frame['file'] : 'unknown';
        $callerLine = is_int($frame['line'] ?? null) ? $frame['line'] : 0;
        if ($classifier === '') {
            // Legacy callers pass no classifier: the file name stands in for it.
            $classifier = pathinfo($callerFile, PATHINFO_FILENAME) ?: 'synth';
        }
        self::writeEntry($level, $classifier, self::formatData($data), $logFile, $toErrorLog, $callerFile, $callerLine);
    }

    private static function writeEntry(int $level, string $classifier, string $message, ?string $logFile, bool $toErrorLog, string $callerFile, int $callerLine): void
    {
        $message = self::redact($message);
        $name = self::LEVEL_NAMES[$level];
        $timestamp = self::$timestampFormat !== '' ? '[' . date(self::$timestampFormat) . '] ' : '';
        $requestId = self::getRequestId();
        $rid = $requestId !== '' ? "[rid={$requestId}] " : '';
        $where = basename($callerFile) . ':' . $callerLine;
        $trace = '';
        if (self::$backtraceLevel > 0 && $level <= self::$backtraceLevel) {
            $trace = "\n" . self::redact((new \Exception())->getTraceAsString());
        }

        if (!self::$fileWritesSuspended) {
            $entry = "{$timestamp}[{$name}] {$rid}[{$classifier}] [{$where}] {$message}{$trace}\n";
            self::append(self::resolveLogFile($logFile), $entry);
        }
        if ($toErrorLog) {
            error_log("[{$name}] {$rid}[{$classifier}] [{$where}] {$message}");
        }
    }

    private static function formatData(mixed $data): string
    {
        if (is_string($data)) {
            return $data;
        }
        if ($data instanceof \Throwable) {
            return get_class($data) . ': ' . $data->getMessage() . ' in ' . basename($data->getFile()) . ':' . $data->getLine();
        }
        if (is_scalar($data) || $data === null) {
            return var_export($data, true);
        }
        if (is_resource($data)) {
            return '[resource:' . get_resource_type($data) . ']';
        }
        $json = json_encode(self::maskSecretKeys($data), JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '[unencodable ' . get_debug_type($data) . ']' : $json;
    }

    private static function maskSecretKeys(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return '[depth limit]';
        }
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::isSecretKey((string)$key) ? '[REDACTED]' : self::maskSecretKeys($item, $depth + 1);
        }
        return $out;
    }

    private static function isSecretKey(string $key): bool
    {
        $key = strtolower(str_replace(['-', ' '], '_', $key));
        return in_array($key, self::SECRET_KEYS, true);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function resolveLogFile(?string $logFile): string
    {
        if ($logFile === null || $logFile === '') {
            $logFile = self::$customLog ?? self::root() . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'synth.log';
        }
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        self::rotateLogIfTooLarge($logFile);
        return $logFile;
    }

    private static function append(string $logFile, string $entry): void
    {
        if (isset(self::$failedTargets[$logFile])) {
            return;
        }
        if (@file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX) !== false) {
            return;
        }
        self::$failedTargets[$logFile] = true;
        $marker = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'synth_log_failure_' . md5($logFile) . '.marker';
        $last = is_file($marker) ? (int)@filemtime($marker) : 0;
        if ($last > 0 && (time() - $last) < 300) {
            return;
        }
        @touch($marker);
        @error_log("[warn] [Logger] SYNTH logger cannot write to {$logFile}; repeated warnings are suppressed for five minutes");
    }

    private static function resolveLevel(int|string $level): ?int
    {
        if (is_int($level)) {
            return isset(self::LEVEL_NAMES[$level]) ? $level : null;
        }
        return self::LEVEL_BY_NAME[strtolower($level)] ?? null;
    }

    private static function truncateText(string $text, int $maxLength): string
    {
        $text = (string)preg_replace('/\s+/', ' ', $text);
        if (strlen($text) <= $maxLength) {
            return $text;
        }
        return substr($text, 0, max(0, $maxLength - 3)) . '...';
    }
}
