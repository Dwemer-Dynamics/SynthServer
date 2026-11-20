<?php

if (!function_exists('synthFalloutStatCategories')) {
    function synthFalloutStatCategories(): array
    {
        return [
            'Progression & Exploration' => [
                'Quests Completed',
                'Locations Discovered',
                'Challenges Completed',
                'Books Read',
            ],
            'Combat' => [
                'People Killed',
                'Creatures Killed',
                'Total Things Killed',
                'Disintegrations',
                'Dismembered Limbs',
                'Sandman Kills',
                'Paralyzing Punches',
                'Robots Disabled',
                'Mines Disarmed',
            ],
            'Health & Survival' => [
                'Stimpaks Taken',
                'Health From Stimpaks',
                'Health From Food',
                'Water Consumed',
                'Rad-X Taken',
                'RadAway Taken',
                'Chems Taken',
                'Times Addicted',
                'Times Slept',
                'Doctor Bags Used',
                'Have Limbs Crippled',
                'Corpses Eaten',
            ],
            'Skills & Utility' => [
                'Locks Picked',
                'Computers Hacked',
                'Speech Successes',
                'Speech Failures',
                'Pockets Picked',
                'Weapons Created',
                'Items Crafted',
                'Items Repaired',
                'Weapon Modifications',
            ],
            'Economy & Gambling' => [
                'Barter Amount Traded',
                'Caravan Games Won',
                'Caravan Games Lost',
                'Roulette Games Played',
                'Blackjack Games Played',
                'Slots Games Played',
            ],
            'Special Events' => [
                'Pants Exploded',
                'Mysterious Stranger Visits',
                'Miss Fortunate Occurrences',
            ],
        ];
    }
}

if (!function_exists('synthFalloutStatNames')) {
    function synthFalloutStatNames(): array
    {
        $names = [];
        foreach (synthFalloutStatCategories() as $categoryStats) {
            foreach ($categoryStats as $statName) {
                $names[] = $statName;
            }
        }
        return $names;
    }
}
