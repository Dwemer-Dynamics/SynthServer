<?php

// Compatibility shim: the logger lives in Synth\Lib\Logger (lib/classes/Logger.php). This global class keeps the
// existing `Logger::info($message, $logFile)` call sites working and forwards everything to it. New code should call
// \Synth\Lib\Logger::log(\Synth\Lib\Logger::INFO, 'Classifier', $data) directly.

require_once __DIR__ . '/classes/Logger.php';

class Logger {
    private const DEFAULT_LOG = __DIR__ . '/../log/synth.log';

    private static function target($logFile): ?string {
        return ($logFile === self::DEFAULT_LOG || $logFile === null || $logFile === '') ? null : (string)$logFile;
    }

    public static function setCustomLog($logFile) { \Synth\Lib\Logger::setCustomLog($logFile === null ? null : (string)$logFile); }
    public static function unsetCustomLog() { \Synth\Lib\Logger::unsetCustomLog(); }
    public static function suspendFileWrites(): void { \Synth\Lib\Logger::suspendFileWrites(); }
    public static function setRequestId($requestId): void { \Synth\Lib\Logger::setRequestId($requestId); }
    public static function getRequestId(): string { return \Synth\Lib\Logger::getRequestId(); }
    public static function ensureRequestId(string $prefix = "req"): string { return \Synth\Lib\Logger::ensureRequestId($prefix); }
    public static function bootstrapRequestId(string $prefix = "req"): string { return \Synth\Lib\Logger::bootstrapRequestId($prefix); }
    public static function setLevel($level) { \Synth\Lib\Logger::setLevel((string)$level); }
    public static function setBacktrace($level, $exposeArgs = false) { \Synth\Lib\Logger::setBacktrace(is_string($level) ? $level : null); }
    public static function setTimestampFormat($format = "") { \Synth\Lib\Logger::setTimestampFormat((string)$format); }
    public static function phaseStart(string $phase, array $context = []): void { \Synth\Lib\Logger::phaseStart($phase, $context, 1); }
    public static function phaseEnd(string $phase, array $context = [], string $level = "debug"): void { \Synth\Lib\Logger::phaseEnd($phase, $context, $level, 1); }
    public static function summarizePayload($value, int $maxTextLength = 180) { return \Synth\Lib\Logger::summarizePayload($value, $maxTextLength); }
    public static function formatContext(array $context): string { return \Synth\Lib\Logger::formatContext($context); }
    public static function rotateKnownLogs(?string $root = null): void { \Synth\Lib\Logger::rotateKnownLogs($root); }
    public static function deleteLogIfTooLarge($logFile = null, $maxSize = 26214400) { \Synth\Lib\Logger::deleteLogIfTooLarge($logFile === null ? null : self::target($logFile), (int)$maxSize); }
    public static function rotateLogIfTooLarge(string $logFile, ?int $maxSize = null): void { \Synth\Lib\Logger::rotateLogIfTooLarge($logFile, $maxSize); }

    public static function trace($message, $logFile = self::DEFAULT_LOG) { \Synth\Lib\Logger::legacy('trace', $message, self::target($logFile)); }
    public static function debug($message, $logFile = self::DEFAULT_LOG) { \Synth\Lib\Logger::legacy('debug', $message, self::target($logFile)); }
    public static function info($message, $logFile = self::DEFAULT_LOG) { \Synth\Lib\Logger::legacy('info', $message, self::target($logFile)); }
    public static function warn($message, $logFile = self::DEFAULT_LOG) { \Synth\Lib\Logger::legacy('warn', $message, self::target($logFile)); }
    public static function error($message, $logFile = self::DEFAULT_LOG) { \Synth\Lib\Logger::legacy('error', $message, self::target($logFile)); }

    // Kept for callers that referenced it directly; the single handler is installed below.
    public static function errorHandler(int $errno, string $errstr, string $errfile, int $errline): bool {
        return \Synth\Lib\Logger::handleError($errno, $errstr, $errfile, $errline);
    }
}

// One error, exception and shutdown handler for the process; installing again is a no-op.
\Synth\Lib\Logger::installHandlers();
