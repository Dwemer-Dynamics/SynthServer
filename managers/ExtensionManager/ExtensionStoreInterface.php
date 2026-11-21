<?php

declare(strict_types=1);

namespace Synth\Managers\ExtensionManager;

// The key/value store an extension gets from its context: values are anything JSON can hold, at most 1 MB each.
interface ExtensionStoreInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function delete(string $key): void;

    /** @return list<string> keys starting with $prefix */
    public function keys(string $prefix = ''): array;
}
