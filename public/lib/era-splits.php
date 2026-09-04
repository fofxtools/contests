<?php

/**
 * Era splits — a raw poll string that names a DIFFERENT release depending on which
 * contest it appeared in. The entrant alias map (scripts/gen-entrants.php $ALIAS)
 * folds spelling variants into one canonical and has no contest axis, so these are
 * resolved separately, keyed by poll id.
 *
 * Shared by scripts/build-contest-matches.php (bakes the split into
 * data/contest-matches.json) and public/lib/contest.php (applies it to the
 * /node/100 All Match Results rows, which read the DB directly).
 *
 * ERA_SPLITS: raw string => [ canonical => <poll id list> | '*' ]. A specific poll
 * list always wins; '*' is the fallback for every other poll. Order is irrelevant.
 *
 *   'God of War' — the 2005 PS2 game in BGE 2K9 / GOTD; the 2018 reboot in GOTD 2.
 *   'Doom' (1993) vs 'DOOM' (2016) — the two raw strings differ only by case, too
 *                  fragile to carry the identity; each is pinned to its year.
 */

declare(strict_types=1);

const ERA_SPLITS = [
    'God of War' => [
        'God of War (2018)' => [7954, 7998, 8020, 8031],   // GOTD 2 (2020)
        'God of War (2005)' => '*',                          // BGE 2K9 / GOTD
    ],
    'Doom' => ['Doom (1993)' => '*'],
    'DOOM' => ['DOOM (2016)' => '*'],
];

/** Resolve $name for the poll it appeared in; unchanged if it is not an era split.
 *  A specific poll list wins; '*' is the fallback; unlisted names pass through. */
function era_disambiguate(string $name, int $poll): string
{
    $fallback = null;
    foreach (ERA_SPLITS[$name] ?? [] as $canon => $polls) {
        if ($polls === '*') {
            $fallback = $canon;
        } elseif (in_array($poll, $polls, true)) {
            return $canon;
        }
    }

    return $fallback ?? $name;
}
