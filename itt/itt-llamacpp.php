<?php

function itt($file, $hints)
{
    return synthIttLlamaCpp(strval($file), strval($hints));
}
