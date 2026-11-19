<?php

declare(strict_types=1);

namespace Synth\Managers\ExtensionManager;

// File-backed DeferredEventSink: one JSON file per (extension, event) in data/extensions/events/. No table: the
// queue is disposable runtime state. Events are best-effort, so a full queue drops the new event instead of
// failing the extension, and an event is consumed once whether or not its handler succeeded.
final class FileEventQueue implements DeferredEventSink
{
    public const MAX_EVENTS = 500;
    public const MAX_BYTES = 65536;
    private const STALE_CLAIM_SECONDS = 300;

    public function __construct(private string $dir) {}

    public function defer(string $extensionId, string $hook, array $payload): void
    {
        $json = json_encode(['extension' => $extensionId, 'hook' => $hook, 'payload' => $payload],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_BYTES || $this->count() >= self::MAX_EVENTS) return;
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) throw new \RuntimeException('event_queue_unwritable');
        $name = sprintf('%s/%s-%s', $this->dir, str_replace('.', '', sprintf('%.6F', microtime(true))), bin2hex(random_bytes(4)));
        if (file_put_contents($name.'.tmp', $json) === false || !rename($name.'.tmp', $name.'.json')) {
            @unlink($name.'.tmp');
            throw new \RuntimeException('event_queue_unwritable');
        }
    }

    public function count(): int
    {
        return count(glob($this->dir.'/*.json') ?: []);
    }

    // Claims up to $limit events, oldest first. A claim is a rename, so two workers never get the same event.
    // Unreadable files are dropped. Claims left behind by a crashed worker are put back after a delay.
    /** @return list<array{claim: string, extension: string, hook: string, payload: array}> */
    public function claim(int $limit): array
    {
        $this->recoverStale();
        $events = [];
        $files = glob($this->dir.'/*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            if (count($events) >= $limit) break;
            $claim = substr($file, 0, -5).'.claim';
            if (!@rename($file, $claim)) continue;
            $data = json_decode((string) @file_get_contents($claim), true);
            if (!is_array($data) || !is_string($data['extension'] ?? null) || !is_string($data['hook'] ?? null) || !is_array($data['payload'] ?? null)) {
                @unlink($claim);
                continue;
            }
            $events[] = ['claim' => $claim, 'extension' => $data['extension'], 'hook' => $data['hook'], 'payload' => $data['payload']];
        }
        return $events;
    }

    public function acknowledge(string $claim): void
    {
        if (dirname($claim) === $this->dir && str_ends_with($claim, '.claim')) @unlink($claim);
    }

    private function recoverStale(): void
    {
        foreach (glob($this->dir.'/*.claim') ?: [] as $claim) {
            if (time() - (int) @filemtime($claim) > self::STALE_CLAIM_SECONDS) @rename($claim, substr($claim, 0, -6).'.json');
        }
    }
}
