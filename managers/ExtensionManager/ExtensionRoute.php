<?php

declare(strict_types=1);

namespace Synth\Managers\ExtensionManager;

// One request to /api/v1/ext/<id>/<path>, as an extension's route handler sees it. The body is the decoded JSON object
// (null when there is none); query values are strings.
final class ExtensionRoute
{
    /**
     * @param array<string, string> $parameters values of the {name} segments of the matched pattern
     * @param array<string, string> $query
     * @param array<string, mixed>|null $body
     */
    public function __construct(public readonly string $method, public readonly string $path, public readonly array $parameters,
        public readonly array $query, public readonly ?array $body) {}
}
