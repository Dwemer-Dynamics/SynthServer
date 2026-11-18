<?php

declare(strict_types=1);

namespace Synth\Managers\ExtensionManager;

// Which extensions are enabled, and their setting values: conf/extensions.json (local, git-ignored). It is a
// config file on purpose: no table exists for it, and a missing file simply means "nothing enabled".
final class ExtensionState
{
    public function __construct(private string $file) {}

    public function exists(): bool
    {
        return is_file($this->file);
    }

    /** @return array{enabled: list<string>, settings: array<string, array>} */
    public function read(): array
    {
        $json = $this->exists() ? file_get_contents($this->file) : false;
        $data = is_string($json) ? json_decode($json, true) : null;
        $enabled = is_array($data['enabled'] ?? null) ? array_values(array_filter($data['enabled'], 'is_string')) : [];
        $settings = is_array($data['settings'] ?? null) ? array_filter($data['settings'], 'is_array') : [];
        return ['enabled' => array_values(array_unique($enabled)), 'settings' => $settings];
    }

    public function setEnabled(string $id, bool $enabled): void
    {
        $state = $this->read();
        $ids = array_values(array_diff($state['enabled'], [$id]));
        if ($enabled) $ids[] = $id;
        sort($ids);
        $this->write(['enabled' => $ids, 'settings' => $state['settings']]);
    }

    public function setSetting(string $id, string $name, mixed $value): void
    {
        $state = $this->read();
        $state['settings'][$id][$name] = $value;
        $this->write($state);
    }

    private function write(array $state): void
    {
        $json = json_encode($state + ['enabled' => [], 'settings' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new \RuntimeException('extension_state_unwritable');
        $temp = $this->file.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (file_put_contents($temp, $json, LOCK_EX) === false || !rename($temp, $this->file)) {
            @unlink($temp);
            throw new \RuntimeException('extension_state_unwritable');
        }
    }
}
