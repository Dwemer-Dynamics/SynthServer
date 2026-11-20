<?php

// Override some descriptions when in instruction mode

// We must use internal named keys here.

\Synth\Bootstrap::setAt('F_DESCRIPTIONS_NEW', "TravelTo", "Travel long distance to a building, city, door or other location. Also known as lead the way.");
\Synth\Bootstrap::setAt('F_NAMES_NEW', "TravelTo", function_exists('synthNormalizeActionCatalogDisplayActionName')
    ? synthNormalizeActionCatalogDisplayActionName("TravelTo")
    : "TravelTo");

foreach (\Synth\Bootstrap::config("FUNCTIONS") as $n=>$f) {
    $internalCode=getFunctionCodeNameByDisplayName($f["name"]);
    if (isset(\Synth\Bootstrap::config('F_DESCRIPTIONS_NEW')[$internalCode]))
        \Synth\Bootstrap::setPath('FUNCTIONS', [$n, "description"], \Synth\Bootstrap::config("F_DESCRIPTIONS_NEW")[$internalCode]);

    if (isset(\Synth\Bootstrap::config('F_NAMES_NEW')[$internalCode]))
        \Synth\Bootstrap::setPath('FUNCTIONS', [$n, "name"], \Synth\Bootstrap::config("F_NAMES_NEW")[$internalCode]);


}

foreach (\Synth\Bootstrap::config("F_DESCRIPTIONS_NEW") as $k=>$v)
    \Synth\Bootstrap::setAt('F_DESCRIPTIONS', $k, $v);

foreach (\Synth\Bootstrap::config("F_NAMES_NEW") as $k=>$v) 
    \Synth\Bootstrap::setAt('F_NAMES', $k, $v);


\Synth\Bootstrap::set('PATCH_PROMPT_ENFORCE_ACTIONS', false);
\Synth\Bootstrap::set('COMMAND_PROMPT_ENFORCE_ACTIONS', "");

?>
