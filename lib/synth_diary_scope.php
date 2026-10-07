<?php

// A v2 request needs diary provenance even before it is claimed; legacy name-based rows cannot supply it.
function synth_diary_requires_owned_context(): bool
{
    return ($GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version'] ?? 1)===2;
}
