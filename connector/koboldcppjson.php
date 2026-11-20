<?php
// Thin hook: the koboldcppjson provider lives in drivers/llm/Drivers/KoboldCppJsonDriver.php. Keeps the legacy global class name
// loadable for call sites and config that still require this file.

class koboldcppjson extends \Synth\Drivers\Llm\Drivers\KoboldCppJsonDriver
{
}
