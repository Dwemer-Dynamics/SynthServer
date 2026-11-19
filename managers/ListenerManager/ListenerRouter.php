<?php

declare(strict_types=1);

namespace Synth\Managers\ListenerManager;

/**
 * Who a reply is spoken to. Pure: no globals, no database.
 *
 * `parse()` splits the model's `listener` field exactly as legacy `parseDialogueListenerNames()` does (separators
 * `,` `&` and the word `and`, empty parts dropped, case-sensitive de-duplication). `pick()` rotates through the
 * group the way the legacy `SCRIPTLINE_LISTENER_CYCLE` counter does, clamped to the last listener.
 */
final class ListenerRouter
{
    /** @return list<string> */
    public static function parse(string $listener): array
    {
        $listener = trim($listener);
        if ($listener === '') {
            return [];
        }
        $parts = preg_split('/\s*(?:,|&|\band\b)\s*/iu', $listener);
        if ($parts === false) {
            return [$listener];
        }
        $names = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $names[] = $part;
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * @param list<string> $group
     * @param int $line zero-based line number within the reply
     */
    public static function pick(array $group, int $line): ?string
    {
        if ($group === []) {
            return null;
        }
        return $group[max(0, min($line, count($group) - 1))];
    }
}
