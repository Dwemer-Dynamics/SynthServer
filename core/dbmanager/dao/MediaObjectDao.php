<?php

// Reads core_media_object. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMediaObject;

/** Finds rows of core_media_object. */
final class MediaObjectDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $mediaId): ?AbstractMediaObject
    {
        return $this->fresh()->find(AbstractMediaObject::class, $mediaId);
    }

    /** Registers (or re-publishes) a ready media object, keeping any earlier correlation fields; true when the statement ran. @param array<string, mixed> $correlation */
    public function publishReady(string $mediaId, string $contentType, int $size, string $sha256, array $correlation): bool
    {
        $text = static fn($value): ?string => ($value = trim(strval($value ?? ''))) === '' ? null : $value;
        $number = static fn($value): ?int => is_numeric($value) && intval($value) > 0 ? intval($value) : null;
        try {
            $this->connection()->executeStatement(
                "INSERT INTO public.core_media_object AS existing
                    (media_id, storage_key, content_type, byte_length, sha256, state, request_id,
                     turn_id, utterance_id, generation, speaker_identity_id, published_at)
                 VALUES (?, ?, ?, ?, ?, 'ready', ?, ?, ?, ?, ?, NOW())
                 ON CONFLICT (media_id) DO UPDATE SET
                    content_type=EXCLUDED.content_type, byte_length=EXCLUDED.byte_length,
                    sha256=EXCLUDED.sha256, state='ready', published_at=NOW(), failure_code=NULL,
                    request_id=COALESCE(existing.request_id, EXCLUDED.request_id),
                    turn_id=COALESCE(existing.turn_id, EXCLUDED.turn_id),
                    utterance_id=COALESCE(existing.utterance_id, EXCLUDED.utterance_id),
                    generation=COALESCE(existing.generation, EXCLUDED.generation),
                    speaker_identity_id=COALESCE(existing.speaker_identity_id, EXCLUDED.speaker_identity_id)",
                [$mediaId, $mediaId, $contentType, $size, $sha256, $text($correlation['request_id'] ?? ''),
                    $text($correlation['turn_id'] ?? ''), $text($correlation['utterance_id'] ?? ''),
                    $number($correlation['generation'] ?? null), $number($correlation['speaker_identity_id'] ?? null)]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }

    /** Delivery facts of one media object (content_type, byte_length, sha256, state), or null when it is not registered. @return array<string, mixed>|null */
    public function delivery(string $mediaId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT content_type, byte_length, sha256, state FROM public.core_media_object WHERE media_id = ? LIMIT 1',
            [$mediaId]
        );

        return $row === false ? null : $row;
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMediaObject> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMediaObject::class)->findBy([], ['mediaId' => 'ASC'], max(1, $limit)));
    }
}
