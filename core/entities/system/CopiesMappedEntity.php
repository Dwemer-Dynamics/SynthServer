<?php

/**
 * Lets a system entity class hand out itself instead of the Doctrine-mapped base class.
 *
 * DAOs return the mapped Abstract* objects from core/entities/base. Top-level code must not depend on those, so the
 * system class copies the loaded values into an instance of itself. The copy is not tracked by Doctrine, so changing it
 * does not change the database; the system class saves it by writing its values back with copyValuesTo() onto the
 * entity the DAO tracks. Ported from the CHIM Evolution persistence model.
 */
declare(strict_types=1);

namespace Synth\Core\Entities\System;

use ReflectionClass;

trait CopiesMappedEntity
{
    /**
     * Returns an instance of the using class holding the same values as the loaded entity.
     *
     * The constructor is skipped, because the values come from the stored row.
     */
    private static function copyOf(object $mapped): static
    {
        $copy = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();
        foreach (get_object_vars($mapped) as $property => $value) {
            $copy->{$property} = $value;
        }

        return $copy;
    }

    /**
     * Writes this object's values onto the entity Doctrine tracks, so the DAO can save them.
     */
    private function copyValuesTo(object $mapped): void
    {
        foreach (get_object_vars($this) as $property => $value) {
            $mapped->{$property} = $value;
        }
    }
}
