<?php

declare(strict_types=1);

namespace Synth\Drivers\Itt;

use Synth\Drivers\Itt\Exception\IttException;

/** The operation every image-to-text driver offers, so the wrapper can use any provider the same way. */
interface IttDriverInterface
{
    /** Driver name as stored in core_itt_connector.driver, such as "openai". */
    public function getName(): string;

    /**
     * Returns the provider's description of the image; never ''.
     *
     * @throws IttException When the image is unusable, the provider cannot be reached, fails, or describes nothing.
     */
    public function describe(IttRequest $request): string;
}
