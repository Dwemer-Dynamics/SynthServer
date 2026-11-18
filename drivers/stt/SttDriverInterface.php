<?php

declare(strict_types=1);

namespace Synth\Drivers\Stt;

use Synth\Drivers\Stt\Exception\SttException;

/** The operation every speech-to-text driver offers, so the wrapper can use any provider the same way. */
interface SttDriverInterface
{
    /** Driver name as stored in core_stt_connector.driver, such as "whisper". */
    public function getName(): string;

    /**
     * Returns the words spoken in the recording; '' when the provider heard nothing.
     *
     * @throws SttException When the provider cannot be reached, fails, or answers unusably.
     */
    public function transcribe(SttRequest $request): SttResult;
}
