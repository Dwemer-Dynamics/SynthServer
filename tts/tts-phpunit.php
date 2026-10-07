<?php

$GLOBALS['SYNTH_TTS_DRIVERS']['phpunit'] = $GLOBALS["TTS_IN_USE"]=function($textString, $mood , $stringforhash) {
    return "soundcache/" . md5(trim($stringforhash)) . ".wav";
};
