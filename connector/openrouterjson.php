<?php
// Thin hook: the openrouterjson provider lives in drivers/llm/Drivers/OpenRouterJsonDriver.php. Keeps the legacy global class name
// loadable for call sites and config that still require this file.
require_once dirname(__DIR__) . '/drivers/llm/Drivers/OpenRouterJsonDriver.php';

class openrouterjson extends \Synth\Drivers\Llm\Drivers\OpenRouterJsonDriver
{
}
