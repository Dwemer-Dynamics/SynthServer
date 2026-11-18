<?php

// The read view of one API key, shared or SYNTH-only. It never carries the secret: the secret is fetched server-side with
// ApiBadgeDao::secret().

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Contracts\ApiKeyRef;

/** An API key as pages and connectors see it: a reference, a label, the provider and origin (shared keys), and whether a key is saved. */
final class ApiBadge
{
    /** Holds the validated parts of one key; $revision is the shared revision, or 0 for a SYNTH-only key. */
    public function __construct(
        private readonly ApiKeyRef $ref,
        private readonly string $label,
        private readonly string $provider,
        private readonly string $origin,
        private readonly int $revision,
        private readonly bool $hasKey,
    ) {
    }

    /** The reference that names this key. */
    public function ref(): ApiKeyRef
    {
        return $this->ref;
    }

    /** The key's label. */
    public function label(): string
    {
        return $this->label;
    }

    /** 'shared' for a global key, 'local' for a SYNTH-only key. */
    public function store(): string
    {
        return $this->ref->store();
    }

    /** The shared provider ('openrouter', 'custom_openai', ...), or '' for a SYNTH-only key. */
    public function provider(): string
    {
        return $this->provider;
    }

    /** The one origin a shared key is bound to, or '' for a SYNTH-only key. */
    public function origin(): string
    {
        return $this->origin;
    }

    /** The shared revision (0 for a SYNTH-only key). */
    public function revision(): int
    {
        return $this->revision;
    }

    /** True when a usable secret is saved (a SYNTH-only key may be empty; a sealed shared key reads as empty). */
    public function hasKey(): bool
    {
        return $this->hasKey;
    }

    /** The page/array form: ref (as 'id'), label, store, provider, origin, revision, configured; never the secret. */
    public function toArray(): array
    {
        return ['id' => $this->ref->toString(), 'store' => $this->store(), 'label' => $this->label, 'provider' => $this->provider,
            'origin' => $this->origin, 'revision' => $this->revision, 'configured' => $this->hasKey];
    }
}
