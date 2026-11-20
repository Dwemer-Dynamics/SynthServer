<?php

// PluginManifest: the load order of one session as the client sent it (`session.plugin_manifest`), validated once and then
// read-only. It turns a runtime form id into a plugin and a plugin-local id and back. Nothing stored may contain a load-order
// prefix, so this is the only place a prefix is interpreted (docs/RUNTIME-ACTORS.md, section 3).

declare(strict_types=1);

namespace Synth\Managers\ReferenceResolver;

use InvalidArgumentException;

/** An immutable, validated plugin list. */
final class PluginManifest
{
    public const MAX_NORMAL = 255;
    public const MAX_LIGHT = 4096;
    public const MAX_NAME = 255;

    /** @var array<string, array{name: string, compile_index: ?int, small_file_compile_index: ?int, is_light: bool}> lower-cased name => entry */
    private array $byName = [];
    /** @var array<int, string> full plugin index => lower-cased name */
    private array $byIndex = [];
    /** @var array<int, string> light plugin index => lower-cased name */
    private array $byLightIndex = [];

    /**
     * @param list<array<string, mixed>> $plugins entries of name and compile_index (full plugins), or name, is_light and small_file_compile_index (light plugins)
     * @throws InvalidArgumentException when a name, index or the counts break the limits, or a light plugin appears for the vr runtime
     */
    public function __construct(private readonly array $plugins, private readonly string $runtimeVariant = 'flat')
    {
        $normal = 0;
        $light = 0;
        foreach ($plugins as $entry) {
            $name = is_array($entry) && is_string($entry['name'] ?? null) ? $entry['name'] : '';
            $key = strtolower($name);
            if (preg_match('/^[^\/\\\\]{1,'.(self::MAX_NAME - 4).'}\.(esm|esp|esl)$/Di', $name) !== 1 || isset($this->byName[$key])) {
                throw new InvalidArgumentException('A plugin needs a unique file name that ends in .esm, .esp or .esl.');
            }
            if (!empty($entry['is_light'])) {
                $index = $entry['small_file_compile_index'] ?? null;
                if ($runtimeVariant === 'vr' || !is_int($index) || $index < 0 || $index > 0xFFF || isset($this->byLightIndex[$index]) || ++$light > self::MAX_LIGHT) {
                    throw new InvalidArgumentException('A light plugin needs a unique small file index below 4096 and is not allowed for vr.');
                }
                $this->byLightIndex[$index] = $key;
                $this->byName[$key] = ['name' => $name, 'compile_index' => null, 'small_file_compile_index' => $index, 'is_light' => true];
            } else {
                $index = $entry['compile_index'] ?? null;
                if (!is_int($index) || $index < 0 || $index > 0xFD || isset($this->byIndex[$index]) || ++$normal > self::MAX_NORMAL) {
                    throw new InvalidArgumentException('A plugin needs a unique compile index below 254.');
                }
                $this->byIndex[$index] = $key;
                $this->byName[$key] = ['name' => $name, 'compile_index' => $index, 'small_file_compile_index' => null, 'is_light' => false];
            }
        }
    }

    /** The manifest hash: SHA-256 over the canonical, ordered plugin list; the server recomputes it, never trusts the client's. */
    public function sha256(): string
    {
        $canonical = array_map(static fn (array $p): array => ['name' => strtolower($p['name']), 'compile_index' => $p['compile_index'],
            'small_file_compile_index' => $p['small_file_compile_index'], 'is_light' => $p['is_light']], array_values($this->byName));

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    /** The plugin list in a stable order, for storage as a manifest revision. @return list<array<string, mixed>> */
    public function toList(): array
    {
        return array_values($this->byName);
    }

    /** Whether a plugin of this file name is loaded. */
    public function has(string $plugin): bool
    {
        return isset($this->byName[strtolower($plugin)]);
    }

    /**
     * Decodes a runtime form id into the lower-cased plugin file name and the six-digit local id, or the reason it cannot be.
     *
     * @return array{plugin: string, local_id: string}|array{reason: string} reason is invalid_rid, unknown_plugin_prefix or runtime_form (FF prefix)
     */
    public function decode(string $formId): array
    {
        $hex = preg_replace('/^0x/i', '', $formId) ?? '';
        if (preg_match('/^[0-9a-f]{8}$/Di', $hex) !== 1) {
            return ['reason' => 'invalid_rid'];
        }
        $hex = strtolower($hex);
        $prefix = substr($hex, 0, 2);
        if ($prefix === 'ff') {
            return ['reason' => 'runtime_form'];
        }
        if ($prefix === 'fe') {
            $name = $this->byLightIndex[hexdec(substr($hex, 2, 3))] ?? null;

            return $name === null ? ['reason' => 'unknown_plugin_prefix'] : ['plugin' => $name, 'local_id' => '000'.substr($hex, 5, 3)];
        }
        $name = $this->byIndex[hexdec($prefix)] ?? null;

        return $name === null ? ['reason' => 'unknown_plugin_prefix'] : ['plugin' => $name, 'local_id' => substr($hex, 2, 6)];
    }

    /** The runtime form id (eight upper-case hex digits) of a plugin-local reference under this load order, or null when the plugin is not loaded. */
    public function encode(string $plugin, string $localId): ?string
    {
        $entry = $this->byName[strtolower($plugin)] ?? null;
        $local = strtolower($localId);
        if ($entry === null || preg_match('/^[0-9a-f]{1,6}$/D', $local) !== 1) {
            return null;
        }
        $value = hexdec($local);
        if ($entry['is_light']) {
            return $value > 0xFFF ? null : sprintf('FE%03X%03X', $entry['small_file_compile_index'], $value);
        }

        return sprintf('%02X%06X', $entry['compile_index'], $value);
    }

    /**
     * What changed from an earlier manifest of the same playthrough (names only; indexes may move freely).
     *
     * @return array{added: list<string>, removed: list<string>, moved: list<string>}
     */
    public function diff(?PluginManifest $previous): array
    {
        $before = $previous?->byName ?? [];
        $diff = ['added' => [], 'removed' => [], 'moved' => []];
        foreach ($this->byName as $key => $entry) {
            if (!isset($before[$key])) {
                $diff['added'][] = $entry['name'];
            } elseif ($before[$key]['compile_index'] !== $entry['compile_index'] || $before[$key]['small_file_compile_index'] !== $entry['small_file_compile_index']) {
                $diff['moved'][] = $entry['name'];
            }
        }
        foreach ($before as $key => $entry) {
            if (!isset($this->byName[$key])) {
                $diff['removed'][] = $entry['name'];
            }
        }

        return $diff;
    }

    /** The runtime variant the manifest was sent under. */
    public function runtimeVariant(): string
    {
        return $this->runtimeVariant;
    }
}
