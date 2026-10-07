<?php

function itt($file, $hints)
{
    return synthIttOpenAiCompatible('google_openai', strval($file), strval($hints));
}
