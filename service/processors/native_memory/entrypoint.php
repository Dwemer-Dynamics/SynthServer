<?php

// Thin hook over the worker registry: the manager runs the "memory" worker, so the registry is the one place that knows how.
$GLOBALS['TASKS']['native_memory']['fn'] = static function() {
    require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
    $report = \Synth\Workers\WorkerRegistry::create('memory')->run(new \Synth\Workers\WorkerLimits(1, 120));
    // Provider output is never relayed; the adapter already reduced a failed pass to a generic report.
    if ($report->status() === \Synth\Workers\WorkerReport::FAILED) throw new RuntimeException('Native memory dispatch failed');
};
