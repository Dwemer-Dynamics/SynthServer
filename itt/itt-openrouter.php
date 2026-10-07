<?php

function itt($file, $hints)
{
    return synthIttOpenAiCompatible('openrouter', strval($file), strval($hints), true);
}
