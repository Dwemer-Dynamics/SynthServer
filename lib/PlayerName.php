<?php

/**
 * Knows the name of the player's character and writes it in place of the generic words for the player.
 *
 * The name is the runtime PLAYER_NAME (the stored player name that the settings loader puts in the globals, or the
 * sample value). While it is empty, texts are left as they are and the player is simply called "Player".
 */
declare(strict_types=1);

namespace Synth\Lib;

final class PlayerName
{
    public const GENERIC_NAME = 'Player';

    // "The player" is how a game description names the player. A "lute player" is left alone.
    private const GENERIC_WORDS = '/\b(?:[Tt]he player|Player)\b/u';

    private static ?string $override = null;

    /** The configured name, or an empty string when none is set. */
    public static function configured(): string
    {
        if (self::$override !== null) {
            return self::$override;
        }
        $name = $GLOBALS['PLAYER_NAME'] ?? '';

        return is_string($name) ? trim($name) : '';
    }

    /** Sets the name for this process instead of reading the runtime value; null reads it again. Tests use it. */
    public static function set(?string $name): void
    {
        self::$override = $name === null ? null : trim($name);
    }

    /** The name to show for the player: the configured one, or "Player" when none is set. */
    public static function forDisplay(): string
    {
        $name = self::configured();

        return $name === '' ? self::GENERIC_NAME : $name;
    }

    /** The text with the generic words for the player replaced by the configured name. */
    public static function substitute(string $text): string
    {
        $name = self::configured();
        if ($name === '') {
            return $text;
        }

        // A callback, so that a name with "$" or "\" is written exactly as it is.
        return preg_replace_callback(self::GENERIC_WORDS, static fn(): string => $name, $text) ?? $text;
    }
}
