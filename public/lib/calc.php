<?php

/**
 * sc2k5 rebuild — x-stat calculators + Expert Scores parser (Session D / Phase 5).
 *
 * The calculator node partials (content/nodes/48.php, 53.php, 54.php) and the
 * Expert Scores partial (content/nodes/65.php) are plain PHP that echo a form and,
 * when submitted, a results table. They are executed inside an output buffer by
 * calc_render_node() / expert_render_node(), which lib/content.php calls for
 * nodes whose manifest type is "calc" / "expert".
 *
 * Only node 48 needs helpers: get_extrapolated_table() / get_extrapolated_results()
 * (King Morgoth's conditional-probability x-stat model, ported faithfully from the
 * live contestscripts.php, pre-`/* *\/`-block copies) plus a tiny combinations()
 * and the scriptTimer() shim the partial calls. All pure math — no DB.
 */
declare(strict_types=1);

/** Run a calculator node partial and return its HTML. */
function calc_render_node(string $file): string
{
    ob_start();

    try {
        include $file;
    } catch (Throwable $e) {
        ob_end_clean();

        return '<p><em>Could not run this calculator.</em></p>';
    }

    return ob_get_clean();
}

/** Run the Expert Scores node partial and return its HTML. */
function expert_render_node(string $file): string
{
    return calc_render_node($file);
}

/* --------------------------------------------------------------------------
 * Helpers for node 48 (Conditional Probability X-Stat Estimator)
 * ------------------------------------------------------------------------ */

if (!function_exists('microtime_float')) {
    function microtime_float(): float
    {
        return microtime(true);
    }
}

if (!function_exists('script_timer')) {
    /** Faithful shim of the live functions.inc.php timer. */
    function script_timer(string $script_name, string $start_or_end = 'start'): void
    {
        global $timer_array;
        if (!is_array($timer_array)) {
            $timer_array = [];
        }
        if (strtolower($start_or_end) !== 'end') {
            $timer_array[$script_name]['Start'] = microtime_float();
        } else {
            $timer_array[$script_name]['End']     = microtime_float();
            $timer_array[$script_name]['Elapsed'] = round($timer_array[$script_name]['End'] - $timer_array[$script_name]['Start'], 5);
        }
    }
}
if (!function_exists('scriptTimer')) {
    function scriptTimer(string $script_name, string $start_or_end = 'start'): void
    {
        script_timer($script_name, $start_or_end);
    }
}

if (!function_exists('xstat_combinations')) {
    /**
     * All size-$k subsets of $set, values preserved (keys not significant here).
     * Replaces the PEAR Math_Combinatorics class the live code used.
     */
    function xstat_combinations(array $set, int $k): array
    {
        $values = array_values($set);
        $n      = count($values);
        if ($k <= 0) {
            return [[]];
        }
        if ($k > $n) {
            return [];
        }

        $out  = [];
        $pick = function (int $start, array $acc) use (&$pick, &$out, $values, $n, $k): void {
            if (count($acc) === $k) {
                $out[] = $acc;

                return;
            }
            for ($i = $start; $i <= $n - ($k - count($acc)); $i++) {
                $acc[] = $values[$i];
                $pick($i + 1, $acc);
                array_pop($acc);
            }
        };
        $pick(0, []);

        return $out;
    }
}

if (!function_exists('get_extrapolated_results')) {
    /**
     * King Morgoth's conditional-probability x-stat model.
     * Input: one-on-one strength estimates (any positive scale — values are
     * rescaled so the strongest is 1). Output: estimated vote share per entrant
     * (not normalised to sum 1 — matches the live behaviour exactly).
     */
    function get_extrapolated_results(array $strength_set): array
    {
        $return_array = [];

        for ($i = 0; $i < count($strength_set); $i++) {
            $strength_set[$i] = max(0, (float) $strength_set[$i]);
        }
        $peak = max($strength_set);
        if ($peak <= 0) {
            return array_fill(0, count($strength_set), 0.0);
        }
        $scalar = 1 / $peak;
        for ($i = 0; $i < count($strength_set); $i++) {
            $strength_set[$i] = $scalar * $strength_set[$i];
        }

        $num_chars = count($strength_set);
        for ($char_index = 0; $char_index < $num_chars; $char_index++) {
            $char_prob = 1 / $num_chars;
            foreach ($strength_set as $n) {
                $char_prob *= $n;
            }

            $strength_set_others = [];
            for ($index = 0; $index < $num_chars; $index++) {
                if ($index !== $char_index) {
                    $strength_set_others[] = $strength_set[$index];
                }
            }
            $num_other_chars = count($strength_set_others);
            set_time_limit(30);

            for ($num_inv = 1; $num_inv <= $num_other_chars; $num_inv++) {
                $divisor         = $num_chars - $num_inv;
                $inv_index_array = range(0, $num_other_chars - 1);
                $combinations    = xstat_combinations($inv_index_array, $num_inv);
                foreach ($combinations as $combin) {
                    $inverted_array = $strength_set_others;
                    foreach ($combin as $invert_index) {
                        $inverted_array[$invert_index] = 1 - $inverted_array[$invert_index];
                    }
                    $term_prob = $strength_set[$char_index] / $divisor;
                    foreach ($inverted_array as $n) {
                        $term_prob *= $n;
                    }
                    $char_prob += $term_prob;
                }
            }
            $return_array[] = $char_prob;
        }

        return $return_array;
    }
}

if (!function_exists('get_extrapolated_table')) {
    /** HTML results table for get_extrapolated_results(). */
    function get_extrapolated_table(array $input_values, $input_names = false): string
    {
        for ($i = 0; $i < count($input_values); $i++) {
            $input_values[$i] = max(0, (float) $input_values[$i]);
        }
        $output      = "<table><tr><th>Character</th><th>Input Strength</th><th>Estimate</th></tr>\n";
        $value_array = get_extrapolated_results($input_values);
        $char        = 1;
        for ($i = 0; $i < count($value_array); $i++) {
            $rounded = round($value_array[$i] * 100, 2);
            if (is_array($input_names) && ($input_names[$i] ?? '') !== '') {
                $name = $input_names[$i];
            } else {
                $name = "Character $char";
            }
            $output .= '<tr><td>' . htmlspecialchars((string) $name) . '</td><td>'
                     . $input_values[$i] . "</td><td>$rounded%</td></tr>\n";
            $char++;
        }
        $output .= '</table>';

        return $output;
    }
}
