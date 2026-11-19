<?php
// Thin hook: the openaijson provider lives in drivers/llm/Drivers/OpenAiJsonDriver.php. Keeps the legacy global class name
// loadable for call sites and config that still require this file.
require_once dirname(__DIR__) . '/drivers/llm/Drivers/OpenAiJsonDriver.php';

class openaijson extends \Synth\Drivers\Llm\Drivers\OpenAiJsonDriver
{
}
