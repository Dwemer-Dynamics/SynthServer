<?php

// WorldManager: the "where and when" of the game as the client last reported it in a `world_context` payload: location,
// worldspace, weather, and the in-game date and time of the Fallout calendar (Gregorian month names, 24-hour clock, a day
// part such as "late afternoon"). It is the equivalent of chevo's WorldManager for date, time, location and weather.
// What the world means (lore, quests, factions) stays with WorldKnowledgeManager. Immutable and pure: reading the latest payload
// from the database stays with the caller (ContextManager), so this class has no side effects.

declare(strict_types=1);

namespace Synth\Managers\WorldManager;

require_once dirname(__DIR__).'/ContextManager/Native/utils_game_timestamp.php';

/** One world_context payload, read as text. */
final class WorldManager
{
    private const MONTHS = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    /** @param array<string, mixed>|null $payload the world_context payload (location, worldspace, weather, game_time) */
    private function __construct(private readonly ?array $payload)
    {
    }

    /** The world of a payload; null or a non-array payload reads as an unknown world. @param array<string, mixed>|null $payload */
    public static function fromPayload(?array $payload): self
    {
        return new self($payload);
    }

    /** Whether the client reported no usable value: empty, "unknown", "unknown location", "none" or "null". */
    public static function isUnknown(mixed $value): bool
    {
        $clean = strtolower(trim((string) $value));

        return $clean === '' || $clean === 'unknown' || $clean === 'unknown location' || $clean === 'none' || $clean === 'null';
    }

    /** The location, else the worldspace, else an empty string. */
    public function location(): string
    {
        if ($this->payload === null) {
            return '';
        }
        $location = trim((string) ($this->payload['location'] ?? ''));

        return self::isUnknown($location) ? $this->worldspace() : $location;
    }

    /** The worldspace (the outdoor map: Commonwealth, Far Harbor, ...), or an empty string. */
    public function worldspace(): string
    {
        $worldspace = trim((string) ($this->payload['worldspace'] ?? ''));

        return self::isUnknown($worldspace) ? '' : $worldspace;
    }

    /** The weather as reported, or an empty string. */
    public function weather(): string
    {
        return trim((string) ($this->payload['weather'] ?? ''));
    }

    /** The in-game date such as "October 23, 2287", or an empty string when the client sent none or an impossible one. */
    public function date(): string
    {
        $time = $this->gameTime();
        $year = (int) ($time['year'] ?? 0);
        $month = (int) ($time['month'] ?? 0);
        $day = (int) ($time['day'] ?? 0);
        if ($year <= 0 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return '';
        }

        return self::MONTHS[$month].' '.$day.', '.$year;
    }

    /** The in-game time such as "4:30 PM, afternoon", or an empty string when no hour was sent. */
    public function time(): string
    {
        $time = $this->gameTime();
        $hour = (float) ($time['hour'] ?? -1);
        if ($hour < 0.0) {
            return '';
        }
        $minutes = ((int) round($hour * 60.0)) % 1440;
        $hour24 = intdiv($minutes, 60);
        $hour12 = $hour24 % 12 === 0 ? 12 : $hour24 % 12;
        $clock = sprintf('%d:%02d %s', $hour12, $minutes % 60, $hour24 >= 12 ? 'PM' : 'AM');
        $part = \hour2part_of_day(sprintf('%02d', $hour24));

        return $part !== '' ? $clock.', '.$part : $clock;
    }

    /**
     * The `<world>` block of a prompt, with a leading blank line, or an empty string when nothing is known. A fallback is used
     * only when the payload has no location (or weather); it is the caller's older lookup.
     */
    public function promptBlock(string $fallbackLocation = '', string $fallbackWeather = ''): string
    {
        $lines = [];
        $worldspace = $this->worldspace();
        $location = trim($this->location()) !== '' ? trim($this->location()) : trim($fallbackLocation);
        $weather = $this->weather() !== '' ? $this->weather() : trim($fallbackWeather);
        foreach (['worldspace' => $worldspace, 'location' => $location, 'weather' => $weather, 'date' => $this->date(), 'time' => $this->time()] as $tag => $value) {
            if ($value !== '') {
                $lines[] = '  <'.$tag.'>'.htmlspecialchars(trim($value), ENT_QUOTES | ENT_XML1, 'UTF-8').'</'.$tag.'>';
            }
        }

        return $lines === [] ? '' : "\n\n<world>\n".implode("\n", $lines)."\n</world>";
    }

    /** @return array<string, mixed> the game_time object, or an empty array */
    private function gameTime(): array
    {
        $time = $this->payload['game_time'] ?? null;

        return is_array($time) ? $time : [];
    }
}
