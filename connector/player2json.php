<?php
// Thin hook: the player2json provider lives in drivers/llm/Drivers/Player2JsonDriver.php. Keeps the legacy global class name
// loadable for call sites and config that still require this file.
require_once dirname(__DIR__) . '/drivers/llm/Drivers/Player2JsonDriver.php';

class player2json extends \Synth\Drivers\Llm\Drivers\Player2JsonDriver
{
}
