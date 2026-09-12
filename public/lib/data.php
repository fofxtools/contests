<?php

declare(strict_types=1);

/**
 * Loaders for data/*.json files that more than one lib/*.php consumer needs.
 * Each function decodes its file once per request (static cache) and returns
 * the raw decoded shape -- no site-specific reshaping here. Callers build
 * whatever lookup/index shape they need on top (see contest.php's
 * amr_contest_data() and contest_tid_for()).
 */

/** data/contest-ids.json (canonical contest registry): a list of
 *  {id, name, year, pool, codes}, one per contest, in chronological order.
 *  `codes` holds every DB/JSON spelling that identifies this contest --
 *  `codes[0]` is the preferred display spelling. */
function contest_registry(): array
{
    static $data = null;
    if ($data === null) {
        $path = dirname(__DIR__, 2) . '/data/contest-ids.json';
        $data = json_decode((string)file_get_contents($path), true) ?? [];
    }

    return $data;
}
