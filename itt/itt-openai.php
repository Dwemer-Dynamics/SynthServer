<?php

function itt($file, $hints)
{
    return synthIttOpenAiCompatible('openai', strval($file), strval($hints));
}
