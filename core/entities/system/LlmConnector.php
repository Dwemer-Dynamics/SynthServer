<?php

// A LLM connector (a model endpoint) as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractLlmConnector;

require_once __DIR__.'/CopiesMappedEntity.php';
require_once dirname(__DIR__).'/base/AbstractLlmConnector.php';

/** One row of core_llm_connector. */
final class LlmConnector extends AbstractLlmConnector
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractLlmConnector $mapped): self
    {
        return self::copyOf($mapped);
    }

    /** The row id. */
    public function id(): int
    {
        return $this->id;
    }
}
