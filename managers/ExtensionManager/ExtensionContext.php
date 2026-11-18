<?php

declare(strict_types=1);

namespace Synth\Managers\ExtensionManager;

// What an extension may use besides its payloads: its id, its settings and its own key/value store.
final class ExtensionContext
{
    public function __construct(private string $id, private array $settingDefinitions, private array $settingValues,
        private ExtensionStore $store) {}

    public function id(): string
    {
        return $this->id;
    }

    // The stored value when it matches the declared type, else the manifest default; null for an undeclared name.
    public function setting(string $name): mixed
    {
        $definition = $this->settingDefinitions[$name] ?? null;
        if (!is_array($definition)) return null;
        $value = $this->settingValues[$name] ?? $definition['default'];
        return ManifestValidator::settingValueValid($definition, $value) ? $value : $definition['default'];
    }

    public function store(): ExtensionStore
    {
        return $this->store;
    }
}
