<?php

/**
 * Stores timestamps in UTC with microseconds, which Doctrine's built-in date types drop.
 *
 * Ordering and cancellation compare request and event times, and two of them can arrive in the same second, so the
 * full precision must survive the round trip. Ported from the CHIM Evolution persistence model.
 */
declare(strict_types=1);

namespace Synth\Core\DbManager\Types;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Type;

final class PreciseDateTimeType extends Type
{
    public const NAME = 'datetimetz_precise';

    // Every value is written in UTC with this format, so stored text also sorts in time order.
    private const DATABASE_FORMAT = 'Y-m-d H:i:s.uP';

    /**
     * Declares a PostgreSQL timestamp with time zone and microsecond precision.
     *
     * Other platforms keep their normal date-time-with-zone column.
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        if ($platform instanceof PostgreSQLPlatform) {
            return 'TIMESTAMP(6) WITH TIME ZONE';
        }

        return $platform->getDateTimeTzTypeDeclarationSQL($column);
    }

    /**
     * Converts a PHP date to UTC text with microseconds.
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!$value instanceof DateTimeImmutable) {
            throw InvalidType::new($value, self::class, ['null', DateTimeImmutable::class]);
        }

        return $value->setTimezone(new DateTimeZone('UTC'))->format(self::DATABASE_FORMAT);
    }

    /**
     * Reads a stored timestamp back as a UTC date.
     *
     * PostgreSQL omits trailing zeros of the fraction, so the text is parsed leniently instead of with one fixed format.
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DateTimeImmutable
    {
        if ($value === null || $value instanceof DateTimeImmutable) {
            return $value;
        }

        try {
            return (new DateTimeImmutable((string) $value))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception $exception) {
            throw InvalidFormat::new((string) $value, self::class, self::DATABASE_FORMAT, $exception);
        }
    }
}
