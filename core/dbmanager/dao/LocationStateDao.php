<?php

// Reads and writes core_location_state: what one playthrough has observed about a location (cleared, discovered). Last
// observation wins, and a null never overwrites a known value (docs/LOCATION-CATALOG.md section 4.1).

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Doctrine\DBAL\ParameterType;
use Synth\Core\Entities\Base\AbstractLocationState;

require_once __DIR__.'/UsesEntityManager.php';
require_once dirname(__DIR__, 2).'/entities/base/AbstractLocationState.php';

/** Finds and records location observations. */
final class LocationStateDao
{
    use UsesEntityManager;

    /** The observation of one location in one playthrough, or null. */
    public function find(string $playthroughId, int $locationId): ?AbstractLocationState
    {
        return $this->fresh()->find(AbstractLocationState::class, ['playthroughId' => $playthroughId, 'locationId' => $locationId]);
    }

    /** Records an observation: a given value replaces the stored one, a null keeps it. */
    public function observe(string $playthroughId, int $locationId, ?bool $cleared, ?bool $discovered): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO public.core_location_state (playthrough_id, location_id, cleared, discovered)
             VALUES (:playthrough_id, :location_id, :cleared, :discovered)
             ON CONFLICT (playthrough_id, location_id) DO UPDATE SET
               cleared = COALESCE(EXCLUDED.cleared, core_location_state.cleared),
               discovered = COALESCE(EXCLUDED.discovered, core_location_state.discovered), updated_at = now()',
            ['playthrough_id' => $playthroughId, 'location_id' => $locationId, 'cleared' => $cleared, 'discovered' => $discovered],
            ['cleared' => ParameterType::BOOLEAN, 'discovered' => ParameterType::BOOLEAN]
        );
    }
}
