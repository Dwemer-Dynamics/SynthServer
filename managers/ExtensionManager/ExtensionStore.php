<?php

declare(strict_types=1);

namespace Synth\Managers\ExtensionManager;

// Small JSON key/value file owned by one extension: data/extensions/<id>.json. It is not the database and does not
// follow save loads. Values are anything JSON can hold; the file is capped so a bug cannot fill the disk.
final class ExtensionStore implements ExtensionStoreInterface
{
    public const MAX_BYTES = 1048576;

    public function __construct(private string $file) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->read()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->write([$key => $value] + $this->read());
    }

    public function delete(string $key): void
    {
        $all = $this->read();
        unset($all[$key]);
        $this->write($all);
    }

    public function keys(string $prefix = ''): array
    {
        return array_values(array_filter(array_map('strval', array_keys($this->read())), static fn(string $key): bool => str_starts_with($key, $prefix)));
    }

    private function read(): array
    {
        $json = is_file($this->file) ? file_get_contents($this->file) : false;
        $data = is_string($json) ? json_decode($json, true) : null;
        return is_array($data) ? $data : [];
    }

    private function write(array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_BYTES) throw new \LengthException('extension_store_too_large');
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new \RuntimeException('extension_store_unwritable');
        $temp = $this->file.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (file_put_contents($temp, $json, LOCK_EX) === false || !rename($temp, $this->file)) {
            @unlink($temp);
            throw new \RuntimeException('extension_store_unwritable');
        }
    }
}
