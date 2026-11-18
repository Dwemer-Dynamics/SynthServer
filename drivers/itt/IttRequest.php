<?php

declare(strict_types=1);

namespace Synth\Drivers\Itt;

/** One image description request: a normalized image on disk plus the game hints the caller already built. */
final class IttRequest
{
    public function __construct(public readonly string $imagePath, public readonly string $hints = '')
    {
    }
}
