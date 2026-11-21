<?php

// Reads core_visual_context. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractVisualContext;

/** Finds rows of core_visual_context. */
final class VisualContextDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractVisualContext
    {
        return $this->fresh()->find(AbstractVisualContext::class, $id);
    }

    /** Columns of core_visual_context that complete() may set. */
    private const COMPLETE_COLUMNS = ['description', 'provider', 'model', 'image_sha256', 'media_id'];

    /**
     * Inserts one capture row (null stores NULL) and returns its id, or 0 when the capture id already exists. $values holds the
     * columns capture_id, session_id, request_id, turn_id, generation, runtime_variant, interaction_mode, actor_identity_id,
     * media_id, request_sha256, response_payload and metadata (JSON text), subject_type, subject_key, subject_name, plugin,
     * baseid, refid, cell_id, worldspace_id, location_name, worldspace_name, image_path, image_sha256, description, perspective,
     * provider, model, and the booleans locked, active, user_edited.
     *
     * @param array<string, mixed> $values
     */
    public function insert(array $values): int
    {
        $columns = ['capture_id', 'session_id', 'request_id', 'turn_id', 'generation', 'runtime_variant', 'interaction_mode',
            'actor_identity_id', 'media_id', 'request_sha256', 'response_payload', 'subject_type', 'subject_key', 'subject_name',
            'plugin', 'baseid', 'refid', 'cell_id', 'worldspace_id', 'location_name', 'worldspace_name', 'image_path',
            'image_sha256', 'description', 'perspective', 'provider', 'model', 'metadata', 'locked', 'active', 'user_edited'];
        $placeholders = [];
        $parameters = [];
        foreach ($columns as $column) {
            $value = $values[$column] ?? null;
            if (in_array($column, ['response_payload', 'metadata'], true)) {
                $placeholders[] = 'CAST(? AS jsonb)';
            } elseif (in_array($column, ['locked', 'active', 'user_edited'], true)) {
                $placeholders[] = 'CAST(? AS boolean)';
                $value = $value ? 'true' : 'false';
            } else {
                $placeholders[] = '?';
            }
            $parameters[] = $value;
        }
        $id = $this->connection()->fetchOne(
            'INSERT INTO public.core_visual_context ('.implode(', ', $columns).') VALUES ('.implode(', ', $placeholders)
            .') ON CONFLICT (capture_id) DO NOTHING RETURNING id',
            $parameters
        );

        return $id === false ? 0 : (int) $id;
    }

    /** The full row of one capture (active ones only when asked), or null. @return array<string, mixed>|null */
    public function findByCapture(string $captureId, bool $activeOnly = false): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT * FROM public.core_visual_context WHERE capture_id = ?'.($activeOnly ? ' AND active = TRUE' : '').' LIMIT 1',
            [$captureId]
        );

        return $row === false ? null : $row;
    }

    /**
     * Sets the named text columns (description, provider, model, image_sha256, media_id; an empty media_id stores NULL) and, when
     * $responsePayloadJson is given, the response payload; stamps updated_at. False when nothing is set or the statement fails.
     *
     * @param array<string, string> $texts
     */
    public function complete(int $id, array $texts, ?string $responsePayloadJson = null): bool
    {
        $assignments = [];
        $parameters = [];
        foreach (self::COMPLETE_COLUMNS as $column) {
            if (array_key_exists($column, $texts)) {
                $assignments[] = $column.' = ?';
                $parameters[] = $texts[$column] === '' && $column === 'media_id' ? null : $texts[$column];
            }
        }
        if ($responsePayloadJson !== null) {
            $assignments[] = 'response_payload = CAST(? AS jsonb)';
            $parameters[] = $responsePayloadJson;
        }
        if ($assignments === []) {
            return false;
        }
        $parameters[] = $id;
        try {
            $this->connection()->executeStatement(
                'UPDATE public.core_visual_context SET '.implode(', ', $assignments).', updated_at = CURRENT_TIMESTAMP WHERE id = ?',
                $parameters
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }

    /** Gallery rows: locked first, newest first. @return list<array<string, mixed>> */
    public function listRecent(int $limit): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT * FROM public.core_visual_context ORDER BY locked DESC, captured_at DESC LIMIT '.max(1, $limit)
        );
    }

    /** The metadata JSON text of one row, or null. */
    public function metadataJson(int $id): ?string
    {
        $value = $this->connection()->fetchOne('SELECT metadata FROM public.core_visual_context WHERE id = ?', [$id]);

        return $value === false || $value === null ? null : (string) $value;
    }

    /** The stored image path of one row, or null. */
    public function imagePath(int $id): ?string
    {
        $value = $this->connection()->fetchOne('SELECT image_path FROM public.core_visual_context WHERE id = ?', [$id]);

        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * Applies a gallery edit: a description (which also marks the row user-edited) and/or the locked and active flags (null
     * leaves a flag alone). False when there is nothing to set or the statement fails.
     */
    public function edit(int $id, ?string $description, ?bool $locked, ?bool $active): bool
    {
        $assignments = [];
        $parameters = [];
        if ($description !== null) {
            $assignments[] = 'description = ?';
            $parameters[] = $description;
            $assignments[] = 'user_edited = TRUE';
        }
        foreach (['locked' => $locked, 'active' => $active] as $column => $flag) {
            if ($flag !== null) {
                $assignments[] = $column.' = CAST(? AS boolean)';
                $parameters[] = $flag ? 'true' : 'false';
            }
        }
        if ($assignments === []) {
            return false;
        }
        $parameters[] = $id;
        try {
            $this->connection()->executeStatement(
                'UPDATE public.core_visual_context SET '.implode(', ', $assignments).', updated_at = CURRENT_TIMESTAMP WHERE id = ?',
                $parameters
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }

    /** Deletes one row; false when the statement fails. */
    public function delete(int $id): bool
    {
        try {
            $this->connection()->executeStatement('DELETE FROM public.core_visual_context WHERE id = ?', [$id]);
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }

    /**
     * Up to three active, described rows for a prompt about the current place (the cell, else location and worldspace), locked ones
     * or captured within the last $ttlMinutes; locked, then user-edited, then newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function forPrompt(string $cellId, string $location, string $worldspace, int $ttlMinutes): array
    {
        return $this->connection()->fetchAllAssociative(
            "SELECT subject_name, description, locked, user_edited, captured_at FROM public.core_visual_context
             WHERE active = TRUE AND BTRIM(description) <> ''
               AND ((?::text <> '' AND LOWER(cell_id) = ?::text)
                    OR ((?::text = '' OR BTRIM(cell_id) = '')
                        AND (?::text = '' OR LOWER(location_name) = LOWER(?::text))
                        AND (?::text = '' OR LOWER(worldspace_name) = LOWER(?::text))))
               AND (locked = TRUE OR captured_at >= CURRENT_TIMESTAMP - (? * INTERVAL '1 minute'))
             ORDER BY locked DESC, user_edited DESC, captured_at DESC LIMIT 3",
            [$cellId, $cellId, $cellId, $location, $location, $worldspace, $worldspace, max(1, $ttlMinutes)]
        );
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractVisualContext> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractVisualContext::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }
}
