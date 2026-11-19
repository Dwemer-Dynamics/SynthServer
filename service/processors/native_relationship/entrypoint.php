<?php

// Thin hook over the worker registry: the manager runs the "relationship" worker, so the registry is the one place that knows how.
$GLOBALS['TASKS']['native_relationship']['fn'] = static function() {
    require_once dirname(__DIR__, 3) . '/lib/autoload.php';
    $report = \Synth\Workers\WorkerRegistry::create('relationship')->run(new \Synth\Workers\WorkerLimits(1, 120));
    // Provider output is never relayed; the adapter already reduced a failed pass to a generic report.
    if ($report->status() === \Synth\Workers\WorkerReport::FAILED) throw new RuntimeException('Native relationship dispatch failed');
};
