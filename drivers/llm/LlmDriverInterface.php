<?php

declare(strict_types=1);

namespace Synth\Drivers\Llm;

use Synth\Core\LlmDriver;

require_once dirname(__DIR__, 2) . '/core/contracts/dialogue.php';

/**
 * What every LLM driver offers: the shared Synth\Core\LlmDriver contract (supports, stream) plus a name.
 *
 * A driver turns a stored connector row and provider-neutral messages into plain text deltas. It receives its
 * credentials and transport settings through its constructor, never reads globals, and never writes to the database.
 */
interface LlmDriverInterface extends LlmDriver
{
    /** Short name for logs and diagnostics, such as "openai-compatible". */
    public function getName(): string;
}
