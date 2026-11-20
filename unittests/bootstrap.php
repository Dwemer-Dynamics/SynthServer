<?php

// The legacy unittests suite has its own vendor/ (PHPUnit); the server's classes load the way production entries
// load them, through lib/autoload.php, so files no longer need require_once for PSR-4 classes.
require __DIR__ . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/lib/autoload.php';
