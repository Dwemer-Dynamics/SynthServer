<?php

function itt($file, $hints)
{
    return synthIttOpenAiCompatible('custom', strval($file), strval($hints));
}
