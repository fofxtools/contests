<?php

/**
 * Bonus / novelty polls — matches that ran alongside a contest but are NOT part of
 * its scored bracket, so they are excluded from the `official` count in
 * data/contest-matches.* and hidden by default on /node/100.
 *
 * Shared by scripts/build-contest-matches.php and public/lib/contest.php.
 *
 * Provenance — the GameFAQs page that fixes each contest's scored-battle count,
 * which is what makes the extra poll a bonus (including it would overshoot):
 *   2926        cb6 "Correct Picks by Battle" = 63
 *   3308        cb7 table = 63
 *   3509        bge09 table = 63
 *   4196        gotd "Correct Picks by Battle" = 127
 *   4573        rivals_bracket_final table = 63
 *   5224/52/65/67   cb9 page: "121 three-way ... battles"
 *   6178-6181   bge20_stats table = 127
 *   8090        gotd_20 bracket = 127 battles
 */
declare(strict_types=1);

const BONUS_POLLS = [
    2926 => 'CB VI: "? Block" novelty poll',
    3308 => 'CB VII: Classic/CD-I/Toon/Young Link 4-way novelty',
    3509 => 'BGE 2K9: OoT vs FFVII 2-way rematch',
    4196 => 'GOTD: Link vs Santa Claus',
    4573 => 'Rivalry: 3rd-place match',
    5224 => 'CB IX Bonus 1: Last Place',
    5252 => 'CB IX Bonus 2: Revenge Match',
    5265 => 'CB IX Bonus 3: Runners-Up Battle',
    5267 => 'CB IX Bonus 4: Link vs Solid Snake',
    6178 => 'BGE 2K15: 3rd-place Melee vs SMRPG',
    6179 => 'BGE 2K15: Grudge Match',
    6180 => 'BGE 2K15: Grudge Match',
    6181 => 'BGE 2K15: Grudge Match',
    8090 => "GOTD 2: BotW vs Majora's Mask",
];
