<?php
// Thin hook: the groqjson provider lives in drivers/llm/Drivers/GroqJsonDriver.php. Keeps the legacy global class name
// loadable for call sites and config that still require this file.
require_once dirname(__DIR__) . '/drivers/llm/Drivers/GroqJsonDriver.php';

class groqjson extends \Synth\Drivers\Llm\Drivers\GroqJsonDriver
{
}
