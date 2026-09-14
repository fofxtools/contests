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
 *  {id, name, year, pool, label, codes}, one per contest, in chronological
 *  order.
 *
 *  `name` is the long descriptive name ("Spring 2004 Game Contest").
 *
 *  `label` is the short display text ("Spring 2K4", or "SpC2K4", or anything
 *  else) shown wherever the site needs a compact contest name -- nav links,
 *  table cells, contest pickers (see contest.php's amr_contest_list()). Pure
 *  cosmetic text with no effect on any derived data file: editing it takes
 *  effect on the next page load, no rebuild needed.
 *
 *  `codes` is a different thing entirely, despite `codes[0]` looking like a
 *  label -- it's every literal spelling the live database has ever used for
 *  this contest's `contest` column (see build-contest-matches.php's DB
 *  search and contest.php's contest_tid_for()), AND `codes[0]` specifically
 *  is the pipeline's internal join key, baked as a literal string into every
 *  derived data/*.json file downstream (contest-matches.json's own keys,
 *  match-records.json's "contest" field, luce-fit.json's keys, etc.).
 *  Changing `codes` needs a full `scripts/rebuild-all.sh` and changes what
 *  every derived file's "contest" identifier literally is -- not something
 *  to edit casually the way `label` can be. */
function contest_registry(): array
{
    static $data = null;
    if ($data === null) {
        $path = dirname(__DIR__, 2) . '/data/contest-ids.json';
        $data = json_decode((string)file_get_contents($path), true) ?? [];
    }

    return $data;
}
