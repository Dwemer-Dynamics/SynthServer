<?php

declare(strict_types=1);

namespace Synth\Drivers\Tts;

use Synth\Core\SpeechDriver;

require_once dirname(__DIR__, 2) . '/core/contracts/dialogue.php';

/**
 * What every speech driver offers: the shared Synth\Core\SpeechDriver contract (settings, queue) plus a name.
 *
 * Plans carry only the connector's own provider block and the resolved voice. A driver never reads game state
 * beyond what the caller passes in.
 */
interface TtsDriverInterface extends SpeechDriver
{
    /** Short name for logs and diagnostics, such as "profile-speech". */
    public function getName(): string;
}
