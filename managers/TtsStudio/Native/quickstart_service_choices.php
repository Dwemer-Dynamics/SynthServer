<?php

// Keep configured providers selectable without silently changing a working connector.
function synth_quickstart_service_choices(array $recommended, string $active, array $supported): array
{
    if ($active !== '' && in_array($active, $supported, true)) $recommended[] = $active;
    return array_values(array_unique($recommended));
}
