<?php
// Thin hook: the google_openaijson provider lives in drivers/llm/Drivers/GoogleOpenAiJsonDriver.php. Keeps the legacy global class name
// loadable for call sites and config that still require this file.
require_once dirname(__DIR__) . '/drivers/llm/Drivers/GoogleOpenAiJsonDriver.php';

class google_openaijson extends \Synth\Drivers\Llm\Drivers\GoogleOpenAiJsonDriver
{
}
