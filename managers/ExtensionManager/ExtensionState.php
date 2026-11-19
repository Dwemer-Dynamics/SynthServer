<?php

declare(strict_types=1);

namespace Synth\Managers\ExtensionManager;

// Which extensions are enabled, their setting values and their failure bookkeeping: conf/extensions.json (local,
// git-ignored). It is a config file on purpose: no table exists for it, and a missing file simply means "nothing
// enabled".
//   {"enabled": [ids], "settings": {id: {name: value}}, "failures": {id: count}, "auto_disabled": {id: {at, reason}},
//    "deferred_events": bool}
// Every change is a locked read-modify-write followed by an atomic rename, so two processes (a dialogue request and
// the ExtensionEvents worker) cannot lose each other's update.
final class ExtensionState
{
    public function __construct(private string $file) {}

    public function exists(): bool
    {
        return is_file($this->file);
    }

    /** @return array{enabled: list<string>, settings: array<string, array>, failures: array<string, int>, auto_disabled: array<string, array{at: int, reason: string}>, deferred_events: bool} */
    public function read(): array
    {
        $json = $this->exists() ? file_get_contents($this->file) : false;
        $data = is_string($json) ? json_decode($json, true) : null;
        $data = is_array($data) ? $data : [];
        $enabled = is_array($data['enabled'] ?? null) ? array_values(array_filter($data['enabled'], 'is_string')) : [];
        $settings = is_array($data['settings'] ?? null) ? array_filter($data['settings'], 'is_array') : [];
        $failures = [];
        foreach (is_array($data['failures'] ?? null) ? $data['failures'] : [] as $id => $count) {
            if (is_string($id) && is_int($count) && $count > 0) $failures[$id] = $count;
        }
        $auto = [];
        foreach (is_array($data['auto_disabled'] ?? null) ? $data['auto_disabled'] : [] as $id => $entry) {
            if (is_string($id) && is_array($entry)) $auto[$id] = ['at' => intval($entry['at'] ?? 0), 'reason' => strval($entry['reason'] ?? '')];
        }
        return ['enabled' => array_values(array_unique($enabled)), 'settings' => $settings, 'failures' => $failures,
            'auto_disabled' => $auto, 'deferred_events' => ($data['deferred_events'] ?? false) === true];
    }

    // An explicit enable clears the extension's failure history and any auto-disable record.
    public function setEnabled(string $id, bool $enabled): void
    {
        $this->mutate(static function (array $state) use ($id, $enabled): array {
            $ids = array_values(array_diff($state['enabled'], [$id]));
            if ($enabled) {
                $ids[] = $id;
                unset($state['failures'][$id], $state['auto_disabled'][$id]);
            }
            sort($ids);
            $state['enabled'] = $ids;
            return $state;
        });
    }

    public function setSetting(string $id, string $name, mixed $value): void
    {
        $this->mutate(static function (array $state) use ($id, $name, $value): array {
            $state['settings'][$id][$name] = $value;
            return $state;
        });
    }

    // Counts one failure and returns the new count. At $limit the extension is removed from the enabled list and
    // recorded as auto-disabled in the same write.
    public function recordFailure(string $id, string $reason, int $limit): int
    {
        $count = 0;
        $this->mutate(function (array $state) use ($id, $reason, $limit, &$count): array {
            $count = ($state['failures'][$id] ?? 0) + 1;
            $state['failures'][$id] = $count;
            if ($count >= $limit && in_array($id, $state['enabled'], true)) {
                $state['enabled'] = array_values(array_diff($state['enabled'], [$id]));
                $state['auto_disabled'][$id] = ['at' => time(), 'reason' => mb_substr($reason, 0, 200)];
                unset($state['failures'][$id]);
            }
            return $state;
        });
        return $count;
    }

    public function clearFailures(string $id): void
    {
        if (($this->read()['failures'][$id] ?? 0) === 0) return;
        $this->mutate(static function (array $state) use ($id): array {
            unset($state['failures'][$id]);
            return $state;
        });
    }

    /** @param callable(array): array $change */
    private function mutate(callable $change): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new \RuntimeException('extension_state_unwritable');
        $lock = @fopen($this->file.'.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new \RuntimeException('extension_state_unwritable');
        try { $this->write($change($this->read())); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function write(array $state): void
    {
        $state['failures'] = (object) ($state['failures'] ?? []);
        $state['auto_disabled'] = (object) ($state['auto_disabled'] ?? []);
        $state['settings'] = (object) ($state['settings'] ?? []);
        if (($state['deferred_events'] ?? false) !== true) unset($state['deferred_events']);
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $temp = $this->file.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (file_put_contents($temp, $json, LOCK_EX) === false || !rename($temp, $this->file)) {
            @unlink($temp);
            throw new \RuntimeException('extension_state_unwritable');
        }
    }
}
