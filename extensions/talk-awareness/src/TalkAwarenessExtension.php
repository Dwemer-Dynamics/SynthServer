<?php

declare(strict_types=1);

namespace Synth\Extensions\TalkAwareness;

use Synth\Managers\ExtensionManager\ExtensionContext;
use Synth\Managers\ExtensionManager\ExtensionInterface;
use Synth\Managers\ExtensionManager\ExtensionRegistrar;
use Synth\Managers\ExtensionManager\Hooks;

// Reference extension: remembers, per companion, where and (in game ticks) when they last spoke with the player,
// and adds a short <talk_awareness> prompt section from what was recorded before the current turn.
final class TalkAwarenessExtension implements ExtensionInterface
{
    private ExtensionContext $context;

    public function register(ExtensionRegistrar $registrar): void
    {
        $this->context = $registrar->context();
        $registrar->filter(Hooks::DIALOGUE_CONTEXT_SECTIONS, $this->section(...));
        $registrar->on(Hooks::DIALOGUE_REPLY_COMPLETED, $this->record(...));
    }

    public function section(array $payload): array
    {
        $key = strval($payload['character_key'] ?? '');
        $player = strval($payload['player_name'] ?? '');
        if ($key === '' || $player === '') return $payload;
        $last = $this->context->store()->get('talks', [])[$key] ?? null;
        if (!is_array($last)) {
            if ($this->context->setting('mention_first_meeting') === true) {
                $payload['sections']['talk_awareness'] = ["This is the first time you have spoken with {$player}."];
            }
            return $payload;
        }
        $line = "You have spoken with {$player} before";
        $place = strval($last['location'] ?? '');
        $payload['sections']['talk_awareness'] = [$line.($place !== '' ? ", most recently at {$place}." : '.')];
        return $payload;
    }

    public function record(array $payload): void
    {
        $key = strval($payload['character_key'] ?? '');
        if ($key === '') return;
        $talks = $this->context->store()->get('talks', []);
        $talks[$key] = ['location' => mb_substr(strval($payload['world']['location'] ?? ''), 0, 120),
            'game_time_ticks' => is_int($payload['world']['game_time_ticks'] ?? null) ? $payload['world']['game_time_ticks'] : null];
        // Bounded: keep only the most recent 500 companions.
        $this->context->store()->set('talks', array_slice($talks, -500, null, true));
    }
}
