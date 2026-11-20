<?php

// One logged LLM request, as application code and the replay tool see it.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractLlmRequestLog;


/** One row of core_llm_request_log, copied out of the loaded entity. */
final class LlmRequestLog extends AbstractLlmRequestLog
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractLlmRequestLog $mapped): self
    {
        return self::copyOf($mapped);
    }

    /** True once the request ended, whatever the outcome. */
    public function isFinished(): bool
    {
        return $this->status !== self::STATUS_RUNNING;
    }

    /** True when the stored driver input is whole, so a replay sends what was originally sent. */
    public function isReplayable(): bool
    {
        return !$this->requestTruncated && is_array($this->request['messages'] ?? null);
    }
}
