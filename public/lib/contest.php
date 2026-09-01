<?php
/**
 * Contest display functions — ported from contestscripts.php (the live defs, ~line 646+),
 * mysql_* -> PDO (lib/db.php), no file cache, graph links -> /graph/{matchnum}.
 * HTML output kept faithful to the Drupal-era site.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

/** Run a node's stored <?php ... ?> partial with the 4 fns in scope; capture its echo. */
function contest_render_fn_node(string $file): string
{
    ob_start();

    try {
        include $file;                       // node partial, e.g. displaybracket("summer_2k2", "...")
    } catch (Throwable $e) {
        ob_end_clean();

        return '<p><em>Could not render this contest table.</em></p>';
    }

    return ob_get_clean();
}

/** number_format(value*100, 2) . '%' — the site's percent format. */
function gf_pct($v): string
{
    return number_format((float)$v * 100, 2) . '%';
}

function mysql2timestamp(string $datetime): int
{
    $val  = explode(' ', $datetime);
    $date = explode('-', $val[0]);
    $time = explode(':', $val[1] ?? '0:0:0');

    return (int) mktime(
        (int)$time[0],
        (int)($time[1] ?? 0),
        (int)($time[2] ?? 0),
        (int)($date[1] ?? 1),
        (int)($date[2] ?? 1),
        (int)$date[0]
    );
}

// ---------------------------------------------------------------------------
// displaybracket — reads <contest>_bracket
// ---------------------------------------------------------------------------
function displaybracket($contest, $title, $numrounds = 6, $numdivisions = 4, $outputstyle = ''): void
{
    if (!preg_match('/^[a-z0-9_]+$/', (string)$contest)) {
        echo '<p>Unknown bracket.</p>';

        return;
    }
    $rows         = gfq("SELECT * FROM `{$contest}_bracket`");
    $displayvotes = $_GET['displayvotes'] ?? null;

    echo "<b class='style1'>The " . htmlspecialchars((string)$title) . ' Bracket</b><br><br>';
    echo '<table><tr>';

    $winnersmatrix = [];
    for ($j = 0; $j <= $numrounds; $j++) {
        $numwinners = pow(2, $numrounds - $j);
        for ($i = 0; $i < $numwinners; $i++) {
            if ($j == 0) {
                $winnersmatrix[$j][$i] = $i;

                continue;
            }
            if (($winnersmatrix[$j - 1][$i * 2] ?? -1) == -1 || ($winnersmatrix[$j - 1][$i * 2 + 1] ?? -1) == -1) {
                $winnersmatrix[$j][$i] = -1;
            } else {
                $a      = $winnersmatrix[$j - 1][$i * 2];
                $b      = $winnersmatrix[$j - 1][$i * 2 + 1];
                $votes1 = $rows[$a]["round{$j}votes"] ?? 0;
                $votes2 = $rows[$b]["round{$j}votes"] ?? 0;
                if ($votes1 == $votes2) {
                    $winnersmatrix[$j][$i] = -1;
                } elseif ($votes1 > $votes2) {
                    $winnersmatrix[$j][$i] = $a;
                } else {
                    $winnersmatrix[$j][$i] = $b;
                }
            }
        }
    }

    $numwinners = pow(2, $numrounds);
    $numrows    = $numwinners * 2 - 1;
    echo '<table>';
    for ($i = 0; $i < $numrows; $i++) {
        $entrantsperdivision = $numwinners / $numdivisions;
        $numrowsperdivision  = $entrantsperdivision * 2 - 1;
        if ($i % ($entrantsperdivision * 2) == 0) {
            echo "<td rowspan=$numrowsperdivision class='division'>&nbsp;</td>";
        }
        if (($i + 1) % ($entrantsperdivision * 2) == 0) {
            echo '<td></td>';
        }
        for ($j = 0; $j <= $numrounds; $j++) {
            $wm = $winnersmatrix[$j][(int) floor($i / pow(2, $j + 1))] ?? -1;
            if ($wm != -1) {
                $entrant = $rows[$wm]['entrant'] ?? '_____';
                $jplus1  = $j + 1;
                if ($j < $numrounds && $displayvotes != -1) {
                    $votes = $rows[$wm]["round{$jplus1}votes"] ?? '';
                } else {
                    $votes = '';
                }
                if ($votes == 0) {
                    $votes = '';
                }
                $seed = $rows[$wm]['seed'] ?? '';
                if ($seed !== '' && $seed < 10) {
                    $seed = '&nbsp;&nbsp;' . $seed;
                }
                $link = $rows[$wm]['link'] ?? '';
                if ($link == '') {
                    $link = 'http://images.google.com/images?q=' . str_replace(' ', '%20', (string)$entrant);
                }
            } else {
                $entrant = '_____';
                $votes   = '';
                $seed    = '';
                $link    = '';
            }
            if (($i - pow(2, $j) + 1) % (pow(2, $j + 2)) == 0) {
                if ($wm != -1) {
                    echo $outputstyle == 'excel'
                        ? "<td class='topentrantcell'>$seed | <a href=$link class='style1' rel='nofollow'><b>$entrant</b></a> | $votes</td>"
                        : "<td class='topentrantcell'>$seed | <a href=$link class='style1' rel='nofollow'><b>$entrant</b></a><div align='right'>$votes</div></td>";
                } else {
                    echo "<td class='topentrantcell' align='center'>$entrant</td>";
                }
            } elseif (($i - pow(2, $j) + 1) % (pow(2, $j + 1)) == 0) {
                if ($wm != -1) {
                    echo $outputstyle == 'excel'
                        ? "<td class='bottomentrantcell'>$seed | <a href=$link class='style2' rel='nofollow'><b>$entrant</b></a> | $votes</td>"
                        : "<td class='bottomentrantcell'>$seed | <a href=$link class='style2' rel='nofollow'><b>$entrant</b></a><div align='right'>$votes</div></td>";
                } else {
                    echo "<td class='bottomentrantcell' align='center'>$entrant</td>";
                }
            } else {
                $placed = 0;
                for ($k = 0; $k <= (pow(2, $j + 1) - 2); $k++) {
                    if (($i - pow(2, $j) - $k) % pow(2, $j + 2) == 0 && $j < $numrounds) {
                        echo "<td class='betweenopponentscell'>&nbsp;</td>";
                        $placed = 1;
                    }
                }
                if ($placed == 0) {
                    echo '<td>&nbsp;</td>';
                }
            }
        }
        echo '</tr>';
    }
    echo '</table>';
}

// ---------------------------------------------------------------------------
// displaymatches — reads matches
// ---------------------------------------------------------------------------
function displaymatches($contest, $node): void
{
    static $SORTABLE = ['pollid', 'date', 'round', 'division', 'matchnum', 'seed1', 'entrant1', 'votes1',
        'percent1', 'seed2', 'entrant2', 'votes2', 'percent2', 'totalvotes', 'votedifference',
        'percentdifference', 'winneroracleaverage', 'bop_winner_picks', 'bop_loser_picks',
        'prediction1', 'prediction1percent', 'prediction2', 'prediction2percent', 'notes'];

    $fields = (string)($_GET['fields'] ?? '');
    if (!in_array($fields, ['', '1', '2', 'all'], true)) {
        $fields = '';
    }   // whitelist: kills reflected-XSS via ?fields=
    $sort = $_GET['sort'] ?? 'pollid';
    $type = strtoupper((string)($_GET['type'] ?? 'DESC'));
    if (!in_array($sort, $SORTABLE, true)) {
        $sort = 'pollid';
    }
    if ($type !== 'ASC' && $type !== 'DESC') {
        $type = 'DESC';
    }
    $node = (int)$node;
    // Faithful to live (which echoes $_SERVER['REQUEST_URI'] raw, bare '&'), but
    // strip the chars that would allow attribute/tag breakout in the href.
    $reqUri = str_replace(['"', "'", '<', '>', ' '], '', $_SERVER['REQUEST_URI'] ?? "/node/$node");

    echo "<p>Click on a field's title to sort by that field.</p>";
    echo "Basic Information - <a href='/node/$node?sort=$sort&type=$type' title='Basic Fields'>Show Basic Fields Only</a><br />";
    echo "Additonal Information - <a href='/node/$node?fields=1&sort=$sort&type=$type' title='Add Date, Round, and Division fields'>Click Here to add Date, Round, and Division fields</a><br />";
    echo "Expectations Information - <a href='/node/$node?fields=2&sort=$sort&type=$type' title='Add Expectations fields'>Click Here to add Consensus Oracle Challenge Predictions, Board Odds Project Picks, and overall bracket picks</a><br />";
    echo "All Fields - <a href='/node/$node?fields=all&sort=$sort&type=$type' title='Show all fields'>Click Here to show all fields</a><br /><br />";

    $sel = 'SELECT *, (votes1+votes2) AS totalvotes, abs(votes1-votes2) AS votedifference, abs(percent1-percent2) AS percentdifference FROM matches';
    if ($contest === 'all') {
        $rows = gfq("$sel ORDER BY `$sort` $type");
    } else {
        $rows = gfq("$sel WHERE `contest` = ? ORDER BY `$sort` $type", [$contest]);
    }
    $num      = count($rows);
    $typelink = ($type === 'ASC') ? 'DESC' : 'ASC';
    $rank     = 0;

    for ($i = 0; $i < $num; $i++) {
        if ($i == 0) {
            echo "<div class='tscroll'><table border='1' width='100%'><tr>";
            echo '<th>Rank</th>';
            echo "<th><a href='/node/$node?fields=$fields&sort=pollid&type=$typelink'>Poll ID</a></th>";
            if ($fields == 1 || $fields == 'all') {
                echo "<th><a href='/node/$node?fields=$fields&sort=date&type=$typelink'>Date</a></th>";
                echo "<th><a href='/node/$node?fields=$fields&sort=round&type=$typelink'>Round</a></th>";
                echo "<th><a href='/node/$node?fields=$fields&sort=division&type=$typelink'>Division</a></th>";
            }
            echo "<th><a href='/node/$node?fields=$fields&sort=matchnum&type=$typelink'>Match #</a></th>";
            echo "<th><a href='/node/$node?fields=$fields&sort=seed1&type=$typelink'>Winner's Seed</a></th>";
            echo "<th><a href='/node/$node?fields=$fields&sort=entrant1&type=$typelink'>Winner</a></th>";
            echo "<th><a href='/node/$node?fields=$fields&sort=votes1&type=$typelink'>Winner's Votes</a></th>";
            echo "<th><a href='/node/$node?fields=$fields&sort=percent1&type=$typelink'>Winner's Percent</a></th>";
            echo "<th><a href='/node/$node?fields=$fields&sort=seed2&type=$typelink'>Loser's Seed</a></th>";
            echo "<th><a href='/node/$node?fields=$fields&sort=entrant2&type=$typelink'>Loser</a></th>";
            echo "<th><a href='/node/$node?fields=$fields&sort=votes2&type=$typelink'>Loser's Votes</a></th>";
            echo "<th><a href='/node/$node?fields=$fields&sort=percent2&type=$typelink'>Loser's Percent</a></th>";
            echo "<th><a href='/node/$node?fields=$fields&sort=totalvotes&type=$typelink'>Total Votes</a></th>";
            echo "<th><a href='/node/$node?fields=$fields&sort=votedifference&type=$typelink'>Vote Difference</a></th>";
            echo "<th><a href='/node/$node?fields=$fields&sort=percentdifference&type=$typelink'>Percent Difference</a></th>";
            if ($fields == 2 || $fields == 'all') {
                echo "<th><a href='/node/$node?fields=$fields&sort=winneroracleaverage&type=$typelink'>Oracle Prediction for Winner</a></th>";
                echo "<th><a href='/node/$node?fields=$fields&sort=bop_winner_picks&type=$typelink'>BOP Picks for Winner</a></th>";
                echo "<th><a href='/node/$node?fields=$fields&sort=bop_loser_picks&type=$typelink'>BOP Picks for Loser</a></th>";
                echo "<th><a href='/node/$node?fields=$fields&sort=prediction1&type=$typelink'>Bracket Predictions for Winner</a></th>";
                echo "<th><a href='/node/$node?fields=$fields&sort=prediction1percent&type=$typelink'>Bracket Prediction %</a> <a href='$reqUri#notes'>(*)</a></th>";
                echo "<th><a href='/node/$node?fields=$fields&sort=prediction2&type=$typelink'>Bracket Predictions for Loser</a></th>";
                echo "<th><a href='/node/$node?fields=$fields&sort=prediction2percent&type=$typelink'>Bracket Prediction %</a> <a href='$reqUri#notes'>(*)</a></th>";
            }
            echo "<th><a href='/node/$node?fields=$fields&sort=notes&type=$typelink'>Notes</a></th>";
            echo "</tr>\n";
        }
        $r = $rows[$i];
        $rank++;
        $seed1 = ($r['seed1'] == -1) ? 'N/A' : $r['seed1'];
        $seed2 = ($r['seed2'] == -1) ? 'N/A' : $r['seed2'];
        $woa   = gf_pct($r['winneroracleaverage']);
        if ($r['winneroracleaverage'] == 0 || $woa === '-100.00%') {
            $woa = 'N/A';
        }
        $bwp = ($r['bop_winner_picks'] == -1) ? 'N/A' : $r['bop_winner_picks'];
        $blp = ($r['bop_loser_picks'] == -1) ? 'N/A' : $r['bop_loser_picks'];
        $p1  = ($r['prediction1'] == -1) ? 'N/A' : $r['prediction1'];
        $p1p = ($r['prediction1percent'] == -1) ? 'N/A' : gf_pct($r['prediction1percent']);
        $p2  = ($r['prediction2'] == -1) ? 'N/A' : $r['prediction2'];
        $p2p = ($r['prediction2percent'] == -1) ? 'N/A' : gf_pct($r['prediction2percent']);
        $pc1 = gf_pct($r['percent1']);
        $pc2 = gf_pct($r['percent2']);
        $pcd = gf_pct($r['percentdifference']);

        echo '<tr>';
        echo "<td>$rank</td>";
        echo "<td><a href='https://gamefaqs.gamespot.com/poll/{$r['pollid']}-' title='Go to poll {$r['pollid']}' rel='nofollow'>{$r['pollid']}</a></td>";
        if ($fields == 1 || $fields == 'all') {
            echo "<td>{$r['date']}</td><td>{$r['round']}</td><td>{$r['division']}</td>";
        }
        echo "<td>{$r['matchnum']}</td>";
        echo "<td>$seed1</td><td>{$r['entrant1']}</td><td>{$r['votes1']}</td><td>$pc1</td>";
        echo "<td>$seed2</td><td>{$r['entrant2']}</td><td>{$r['votes2']}</td><td>$pc2</td>";
        echo "<td>{$r['totalvotes']}</td><td>{$r['votedifference']}</td><td>$pcd</td>";
        if ($fields == 2 || $fields == 'all') {
            echo "<td>$woa</td><td>$bwp</td><td>$blp</td><td>$p1</td><td>$p1p</td><td>$p2</td><td>$p2p</td>";
        }
        echo "<td><a href='$reqUri#notes'>{$r['notes']}</a></td>";
        echo '</tr>';
        if ($i == ($num - 1)) {
            echo '</table></div>';
        }
    }

    echo "<table><tr><td><p><br><br><a name='notes'>NOTES:</a><br>";
    echo '* - Bracket Prediction numbers are estimates based on the numbers found on the &quot;Correct Picks by Battle&quot; percentages found on the Contest Statistics page, and the number of entries for each contest. These estimates could be off by +/- 10 brackets. Based on 16,764 brackets for SC2K2, 41,059 brackets in Summer 2K3, 40,940 in Spring 2K4, 33,221 in Summer 2K4, 24,748 in Spring 2K5, 33,793 in Summer 2K5, 46,198 in Summer 2K6, 42,646 in Fall 2K6.<br>';
    echo '1 - Original site layout<br>2 - Original site layout, sponsored poll displacing contest poll<br>';
    echo '3 - Xenogears vs. Pokemon match: repeated due to voting bug<br>';
    echo '4 - Kingdom Hearts vs. Soul Calibur match: Site layout switched around noon during this match<br>';
    echo '5 - Vice City vs. Knights of the Old Republic match: two-day match<br>';
    echo "6 - \tMatch was 15 minutes short - delayed start<br>";
    echo '7 - Match was roughly 30 minutes short - site downtime from after 10:05pm to before 10:35pm EST<br>';
    echo '8 - Match was 25 hours due to Dalight Savings Time</p></td></tr></table>';
}

// ---------------------------------------------------------------------------
// listmatches — reads updates; graph links -> /graph/{matchnum}
// ---------------------------------------------------------------------------
function listmatches($contest, $num_entrants = 2): void
{
    // One row per matchnum. entrant1..4 are constant within a matchnum, so MIN() just
    // returns the value while staying valid under ONLY_FULL_GROUP_BY (MySQL 8 default).
    $cols = 'MIN(entrant1) AS entrant1, MIN(entrant2) AS entrant2, MIN(entrant3) AS entrant3, MIN(entrant4) AS entrant4';
    if ($contest === 'all') {
        $rows = gfq("SELECT `matchnum`, $cols FROM updates WHERE `contest` IN ('Spring 2K4','SC2K4','Spring 2K5','SC2K5') GROUP BY `matchnum`");
    } else {
        $rows = gfq("SELECT `matchnum`, $cols FROM updates WHERE `contest` = ? GROUP BY `matchnum` ORDER BY `matchnum` DESC", [$contest]);
    }
    echo "<table style='font-size: small;'>";
    echo '<tr><th>PollID</th><th>Graph</th>';
    for ($i = 1; $i <= $num_entrants; $i++) {
        echo "<th>Entrant $i</th>";
    }
    echo "</tr>\n";
    foreach ($rows as $row) {
        $m = (int)$row['matchnum'];
        echo '<tr>';
        echo "<td><a href='/node/22?matchnum=$m&num=$num_entrants' title='Poll Updates for poll $m'>$m</a></td>";
        echo "<td><a href='/graph/$m?type=2&amp;seconds=60'>View</a></td>";
        for ($i = 1; $i <= $num_entrants; $i++) {
            echo '<td>' . htmlspecialchars((string)($row["entrant$i"] ?? '')) . '</td>';
        }
        echo "</tr>\n";
    }
    echo '</table>';
}

// ---------------------------------------------------------------------------
// updates.contest string  ->  contest taxonomy tid.
// The contest string only exists as the hard-coded arg inside each listmatches
// node, so this bridge is explicit. Verified 1:1 against DISTINCT updates.contest
// (18 values, no blanks, no matchnum spanning two contests).
// ---------------------------------------------------------------------------
function contest_tid_for(string $contest): ?int
{
    static $map = [
        'Summer 2K3' => 2,  'Spring 2K4' => 3,  'SC2K4' => 4,  'Spring 2K5' => 5,
        'Summer 2K5' => 6,  'BSE 2K6' => 7,  'CB 2K6' => 8,  'CB VI' => 9,
        'CB VII'     => 10, 'BGE 2K9' => 11, 'CB VIII' => 12, 'GOTD' => 13,
        'Rivalry'    => 14, 'CB IX' => 15, 'BGE 2K15' => 16, 'Best Year' => 17,
        'CB X'       => 18, 'GOTD 2' => 19,
    ];

    return $map[$contest] ?? null;
}

/** tid -> its "Poll Updates" (listmatches) node id, read from the manifest. */
function contest_pollupdates_nid(int $tid): ?int
{
    static $byTid;
    if ($byTid === null) {
        $byTid = [];
        foreach (manifest() as $nid => $e) {
            if (($e['type'] ?? '') === 'fn'
                && in_array('listmatches', $e['fns'] ?? [], true)
                && (int)($e['tid'] ?? 0) > 0) {
                $byTid[(int)$e['tid']] = (int)$nid;
            }
        }
    }

    return $byTid[$tid] ?? null;
}

// ---------------------------------------------------------------------------
// displayupdates — reads updates for one match; the sortable page (node 22)
// ---------------------------------------------------------------------------
function displayupdates($matchnum, $sort = 'totalvotes', $type = 'DESC', $num_entrants = 2): void
{
    static $SORTABLE = ['time', 'votes1', 'votes2', 'votes3', 'votes4', 'percent1', 'percent2', 'percent3', 'percent4', 'totalvotes', 'lead'];
    $matchnum        = (string)$matchnum;
    $type            = strtoupper((string)$type);
    if (!in_array($sort, $SORTABLE, true)) {
        $sort = 'totalvotes';
    }
    if ($type !== 'ASC' && $type !== 'DESC') {
        $type = 'DESC';
    }
    if ($matchnum == '2084') {
        $sort = 'time';
    }                 // Kefka/Vercetti fix (from original)
    $num_entrants = max(2, min(4, (int)$num_entrants));
    $show_updates = ($sort === 'time' && $type === 'ASC');

    if ($num_entrants == 2) {
        $sql = "SELECT *, votes1/(votes1+votes2) AS percent1, votes2/(votes1+votes2) AS percent2,
                (votes1+votes2) AS totalvotes, ABS(votes1-votes2) AS `lead`
                FROM updates WHERE `matchnum` = ? ORDER BY `$sort` $type";
    } else {
        $pcts = [];
        for ($i = 1; $i <= $num_entrants; $i++) {
            $pcts[] = "votes$i/(votes1+votes2+votes3+votes4) AS percent$i";
        }
        $sql = 'SELECT *, ' . implode(', ', $pcts) . ", (votes1+votes2+votes3+votes4) AS totalvotes
                FROM updates WHERE `matchnum` = ? ORDER BY `$sort` $type";
    }
    $result_array = gfq($sql, [$matchnum]);
    $numrows      = count($result_array);
    $output       = '';

    if ($numrows == 0) {
        echo 'No data for pollid ' . htmlspecialchars($matchnum);

        return;
    }

    // nav row: back to the contest's full poll-updates table + this match's graph
    $nav = [];
    $bc  = (string)($result_array[0]['contest'] ?? '');
    if ($bc !== '' && ($bt = contest_tid_for($bc)) !== null && ($bn = contest_pollupdates_nid($bt)) !== null) {
        $bl    = contests()[(string)$bt]['desc'] ?? $bc;
        $nav[] = "&#171; <a href='/node/$bn'>All " . htmlspecialchars($bl) . ' poll updates</a>';
    }
    $nav[] = "<a href='/graph/" . (int)$matchnum . "?type=2&amp;seconds=60'>Poll update graph</a>";
    $output .= "<p style='font-size: 14px;'>" . implode(' &nbsp;&#183;&nbsp; ', $nav) . "</p>\n";

    if ($num_entrants > 2) {
        $latest_row  = 0;
        $latest_time = 0;
        for ($i = 0; $i < $numrows; $i++) {
            $t = mysql2timestamp((string)$result_array[$i]['time']);
            if ($t > $latest_time) {
                $latest_time = $t;
                $latest_row  = $i;
            }
        }
        $x_array = [];
        for ($i = 1; $i <= $num_entrants; $i++) {
            $x_array[$result_array[$latest_row]['entrant' . $i]] = $result_array[$latest_row]['votes' . $i];
        }
        arsort($x_array);
        $strongest = max($x_array);
        $output .= "<table id=\"x-table\" border=1>\n<tr><td colspan=2>Latest X-Stats</td></tr>\n<tr><th>Name</th><th>Value</th></tr>\n";
        foreach ($x_array as $name => $votes) {
            if ($name != '') {
                $output .= "<tr><td>$name</td><td>" . sprintf('%0.2f', round($votes / ($strongest + $votes) * 100, 2)) . "%</td></tr>\n";
            }
        }
        $output .= "</table>\n<br />\n";
    }

    $timestamp = mysql2timestamp((string)$result_array[(int)floor($numrows / 2)]['time']);
    $d         = getdate($timestamp);
    $output .= "<br />\n<div style='font-size: 18px;'>&#187; Click on the <strong>TIME</strong> field in order to <strong>see the vote and percentage totals for each entrant by update</strong>.</div>\n<br />\n"
             . '<p>Use &lt;control&gt;+&lt;mouse scroll button&gt; to change the font size.</p>';
    $poll_link = "<a href=\"https://gamefaqs.gamespot.com/poll/$matchnum-\">Link</a>";
    $output .= "<div style='font-size: 14px;'><p>This match took place on <b>{$d['weekday']}</b>, {$d['month']} {$d['mday']}, {$d['year']}. $poll_link.</p></div>\n";

    $th           = " style='white-space: normal;'";
    $linksorttype = ($type == 'DESC') ? 'ASC' : 'DESC';
    $r0           = $result_array[0];
    $e1           = $r0['entrant1'];
    $e2           = $r0['entrant2'];
    $e3           = $r0['entrant3'] ?? '';
    $e4           = $r0['entrant4'] ?? '';
    $base         = "/node/22?matchnum=$matchnum";
    $sfx          = "&type=$linksorttype&num=$num_entrants";

    $output .= "<div class='tscroll'><table border='1' style='font-size: smaller;'><tr>";
    $output .= "<th $th><a href='$base&sort=time$sfx' title='Sort $type by Time'>TIME</th>";
    $output .= "<th $th><a href='$base&sort=percent1$sfx' title='Sort $type by {$e1}&#039;s percentage'>{$e1}'s %</a></th>";
    $output .= "<th $th><a href='$base&sort=percent2$sfx' title='Sort $type by {$e2}&#039;s percentage'>{$e2}'s %</a></th>";
    if ($num_entrants > 2) {
        $output .= "<th $th><a href='$base&sort=percent3$sfx' title='Sort $type by {$e3}&#039;s percentage'>{$e3}'s %</a></th>";
    }
    if ($num_entrants > 3) {
        $output .= "<th $th><a href='$base&sort=percent4$sfx' title='Sort $type by {$e4}&#039;s percentage'>{$e4}'s %</a></th>";
    }
    if ($num_entrants == 2) {
        $output .= "<th><a href='$base&sort=lead$sfx' title='Sort $type by the amount of the Lead'>Lead</a></th>";
    } else {
        $output .= '<th>Lead 1</th><th>Lead 2</th>';
        if ($num_entrants > 3) {
            $output .= '<th>Lead 3</th>';
        }
    }
    if ($show_updates) {
        $output .= '<th>Update % A</th><th>Update % B</th>';
        if ($num_entrants > 2) {
            $output .= '<th>Update % C</th>';
        }
        if ($num_entrants > 3) {
            $output .= '<th>Update % D</th>';
        }
        $output .= '<th>Update Votes A</th><th>Update Votes B</th>';
        if ($num_entrants > 2) {
            $output .= '<th>Update Votes C</th>';
        }
        if ($num_entrants > 3) {
            $output .= '<th>Update Votes D</th>';
        }
    }
    $output .= "<th $th><a href='$base&sort=votes1$sfx' title='Sort $type by {$e1}&#039;s votes'>{$e1}</a></th>";
    $output .= "<th $th><a href='$base&sort=votes2$sfx' title='Sort $type by {$e2}&#039;s votes'>{$e2}</a></th>";
    if ($num_entrants > 2) {
        $output .= "<th $th><a href='$base&sort=votes3$sfx' title='Sort $type by {$e3}&#039;s votes'>{$e3}</a></th>";
    }
    if ($num_entrants > 3) {
        $output .= "<th $th><a href='$base&sort=votes4$sfx' title='Sort $type by {$e4}&#039;s votes'>{$e4}</a></th>";
    }
    $output .= "<th><a href='$base&sort=totalvotes$sfx' title='Sort $type by Total Votes'>Total Votes</a></th>";
    $output .= "</tr>\n";

    $lastvotes1 = $lastvotes2 = $lastvotes3 = $lastvotes4 = 0;
    for ($i = 0; $i < $numrows; $i++) {
        $row = $result_array[$i];
        $v1  = (int)$row['votes1'];
        $v2  = (int)$row['votes2'];
        $v3  = $num_entrants > 2 ? (int)$row['votes3'] : 0;
        $v4  = $num_entrants > 3 ? (int)$row['votes4'] : 0;
        $tv  = $v1 + $v2 + $v3 + $v4;
        if ($tv > 0) {
            $pc1 = number_format($v1 / $tv * 100, 2) . '%';
            $pc2 = number_format($v2 / $tv * 100, 2) . '%';
            $pc3 = number_format($v3 / $tv * 100, 2) . '%';
            $pc4 = number_format($v4 / $tv * 100, 2) . '%';
        } else {
            $pc1 = $pc2 = $pc3 = $pc4 = '0%';
        }

        if ($num_entrants == 2) {
            $lead = $row['lead'];
        } else {
            $va = [$v1, $v2, $v3, $v4];
            rsort($va, SORT_NUMERIC);
            $lead1 = $va[0] - $va[1];
            $lead2 = $va[1] - $va[2];
            if ($num_entrants > 3) {
                $lead3 = $va[2] - $va[3];
            }
        }

        if ($show_updates) {
            if ($i == 0) {
                $lu1 = $v1;
                $lu2 = $v2;
                $lu3 = $v3;
                $lu4 = $v4;
            } else {
                $lu1 = $v1 - $lastvotes1;
                $lu2 = $v2 - $lastvotes2;
                $lu3 = $v3 - $lastvotes3;
                $lu4 = $v4 - $lastvotes4;
            }
            $tlu = $lu1 + $lu2 + $lu3 + $lu4;
            if ($tlu > 0) {
                $lp1 = number_format($lu1 / $tlu * 100, 2) . '%';
                $lp2 = number_format($lu2 / $tlu * 100, 2) . '%';
                $lp3 = number_format($lu3 / $tlu * 100, 2) . '%';
                $lp4 = number_format($lu4 / $tlu * 100, 2) . '%';
            } else {
                $lp1 = $lp2 = $lp3 = $lp4 = number_format(100 / $num_entrants, 2) . '%';
            }
            $lastvotes1 = $v1;
            $lastvotes2 = $v2;
            $lastvotes3 = $v3;
            $lastvotes4 = $v4;
        }

        $output .= '<tr>';
        $output .= "<td>{$row['time']}</td>";
        $output .= "<td><div style='text-align: right;'>$pc1</div></td>";
        $output .= "<td><div style='text-align: right;'>$pc2</div></td>";
        if ($num_entrants > 2) {
            $output .= "<td><div style='text-align: right;'>$pc3</div></td>";
        }
        if ($num_entrants > 3) {
            $output .= "<td><div style='text-align: right;'>$pc4</div></td>";
        }
        if ($num_entrants == 2) {
            $output .= "<td><div style='text-align: right;'>$lead</div></td>";
        } else {
            $output .= "<td><div style='text-align: right;'>$lead1</div></td><td><div style='text-align: right;'>$lead2</div></td>";
            if ($num_entrants > 3) {
                $output .= "<td><div style='text-align: right;'>$lead3</div></td>";
            }
        }
        if ($show_updates) {
            $output .= "<td><div style='text-align: right;'><b>$lp1</b></div></td><td><div style='text-align: right;'><b>$lp2</b></div></td>";
            if ($num_entrants > 2) {
                $output .= "<td><div style='text-align: right;'><b>$lp3</b></div></td>";
            }
            if ($num_entrants > 3) {
                $output .= "<td><div style='text-align: right;'><b>$lp4</b></div></td>";
            }
            $output .= "<td><div style='text-align: right;'>$lu1</div></td><td><div style='text-align: right;'>$lu2</div></td>";
            if ($num_entrants > 2) {
                $output .= "<td><div style='text-align: right;'>$lu3</div></td>";
            }
            if ($num_entrants > 3) {
                $output .= "<td><div style='text-align: right;'>$lu4</div></td>";
            }
        }
        $output .= "<td><div style='text-align: right;'>$v1</div></td><td><div style='text-align: right;'>$v2</div></td>";
        if ($num_entrants > 2) {
            $output .= "<td><div style='text-align: right;'>$v3</div></td>";
        }
        if ($num_entrants > 3) {
            $output .= "<td><div style='text-align: right;'>$v4</div></td>";
        }
        $output .= "<td><div style='text-align: right;'>$tv</div></td>";
        $output .= "</tr>\n";
    }
    $output .= '</table></div>';
    echo $output;
}

// ---------------------------------------------------------------------------
// all_match_results — /node/100. One cross-contest results table.
//   2002-2006  from `matches`  (curated, always 2-entrant; pollid 940-2566)
//   2007-2020  from `updates`  (last row per matchnum; matchnum > 2566)
// The two id ranges don't overlap. Sorting is server-side (?sort=&dir=),
// column-whitelisted, same as the rest of the site (no JS).
// ---------------------------------------------------------------------------

/** matchnum => reason. Omitted from the table; listed beneath it. */
const AMR_SKIP = [
    2562 => 'CB 2006 "The Field" qualifier poll — a pooled "Field" entry vs one challenger, not a 1-v-1',
    2563 => 'CB 2006 "The Field" qualifier poll',
    2564 => 'CB 2006 "The Field" qualifier poll',
    2565 => 'CB 2006 "The Field" qualifier poll',
];

/** matchnum => footnote. Shown in the table, marked with a dagger. */
const AMR_FLAG = [
    1618 => 'poll re-run after a voting bug; one result row shown',
    1626 => 'GameFAQs layout changed partway through the match',
    1631 => 'two-day match',
    2437 => 'match ran ~15 min short (delayed start)',
    2455 => 'match ran ~30 min short (site downtime)',
    2546 => '25-hour match (Daylight Saving switch)',
    5205 => 'joke-candidate vote purge late in the match; the final total shown is the official one',
    5207 => 'joke-candidate vote purge late in the match; the final total shown is the official one',
    5267 => 'all-star exhibition match — run alongside the bracket, not part of it',
];
// Note: matchnum 5266 (CB IX grand final) had a spurious post-close `updates` row
// that dropped its 3rd entrant; that row was deleted from `updates` (see cutover
// notes) so the natural last row is now the correct 3-way result.

const AMR_CONTEST = [
    'SC2K2'      => ['Summer 2002 Character Contest', 2002],
    'SC2K3'      => ['Summer 2003 Character Contest', 2003], 'Summer 2K3' => ['Summer 2003 Character Contest', 2003],
    'Spring 2K4' => ['Spring 2004 Game Contest', 2004], 'SC2K4' => ['Summer 2004 Character Contest', 2004],
    'Spring 2K5' => ['Spring 2005 Character Contest', 2005], 'SC2K5' => ['Summer 2005 Character Contest', 2005],
    'Summer 2K5' => ['Summer 2005 Character Contest', 2005],
    'BSE2K6'     => ['Best Series Ever 2006', 2006], 'BSE 2K6' => ['Best Series Ever 2006', 2006],
    'CB2K6'      => ['Character Battle 2006', 2006], 'CB 2K6' => ['Character Battle 2006', 2006],
    'CB VI'      => ['Character Battle VI (2007)', 2007], 'CB VII' => ['Character Battle VII (2008)', 2008],
    'BGE 2K9'    => ['Best. Game. Ever. (2009)', 2009], 'CB VIII' => ['Character Battle VIII (2010)', 2010],
    'GOTD'       => ['Game of the Decade (2010)', 2010], 'Rivalry' => ['Rivalry Rumble (2011)', 2011],
    'CB IX'      => ['Character Battle IX (2013)', 2013], 'BGE 2K15' => ['Best Game Ever (2015)', 2015],
    'Best Year'  => ['Best Year in Gaming (2017)', 2017], 'CB X' => ['Character Battle X (2018)', 2018],
    'GOTD 2'     => ['Game of the Decade 2 (2020)', 2020],
];
const AMR_PRE2007 = ['Summer 2K3', 'Spring 2K4', 'SC2K4', 'Spring 2K5', 'Summer 2K5', 'BSE 2K6', 'CB 2K6'];

function amr_rows(): array
{
    $out = [];

    foreach (gfq('SELECT pollid,contest,date,entrant1,votes1,entrant2,votes2 FROM matches') as $m) {
        $mn = (int)$m['pollid'];
        if (isset(AMR_SKIP[$mn])) {
            continue;
        }
        $out[$mn] = amr_mk($mn, (string)$m['contest'], (string)$m['date'], [
            ['name' => (string)$m['entrant1'], 'votes' => (int)$m['votes1']],
            ['name' => (string)$m['entrant2'], 'votes' => (int)$m['votes2']],
        ]);
    }

    $sql = 'SELECT u.matchnum,u.contest,u.time,u.entrant1,u.votes1,u.entrant2,u.votes2,
                   u.entrant3,u.votes3,u.entrant4,u.votes4
            FROM updates u
            JOIN (SELECT matchnum,MAX(time) mt FROM updates WHERE matchnum > 2566 GROUP BY matchnum) x
              ON x.matchnum=u.matchnum AND x.mt=u.time
            WHERE u.matchnum > 2566';
    foreach (gfq($sql) as $u) {
        $mn = (int)$u['matchnum'];
        if (isset(AMR_SKIP[$mn]) || in_array((string)$u['contest'], AMR_PRE2007, true)) {
            continue;
        }
        $ents = [];
        for ($i = 1; $i <= 4; $i++) {
            $nm = trim((string)$u["entrant$i"]);
            if ($nm === '') {
                continue;
            }
            $ents[] = ['name' => $nm, 'votes' => (int)$u["votes$i"]];
        }
        if (count($ents) < 2 || array_sum(array_column($ents, 'votes')) === 0) {
            continue;
        }  // degenerate final row
        $out[$mn] = amr_mk($mn, (string)$u['contest'], substr((string)$u['time'], 0, 10), $ents);
    }

    return array_values($out);
}

/** Same-character spelling variants across contest eras -> one canonical name (entrant filter). */
const AMR_ALIAS = [
    'Cloud'              => 'Cloud Strife',
    'Samus'              => 'Samus Aran',
    'Aeris'              => 'Aerith Gainsborough',
    'Aeris Gainsborough' => 'Aerith Gainsborough',
    'Cecil'              => 'Cecil Harvey',
    'Terra'              => 'Terra Branford',
    'Tifa Lockheart'     => 'Tifa Lockhart',    // GameFAQs mostly recorded "Lockheart"; canonical here is her correct name
];
function amr_canon(string $name): string
{
    return AMR_ALIAS[$name] ?? $name;
}

function amr_mk(int $poll, string $ccode, string $date, array $ents): array
{
    usort($ents, fn ($a, $b) => $b['votes'] <=> $a['votes']);          // winner first
    $total = array_sum(array_column($ents, 'votes'));
    foreach ($ents as &$e) {
        $e['pct'] = $total > 0 ? $e['votes'] / $total * 100 : 0.0;
    }
    unset($e);
    [$cname, $cyear] = AMR_CONTEST[$ccode] ?? [$ccode, 9999];

    return [
        'poll'   => $poll, 'cname' => $cname, 'cyear' => $cyear, 'date' => $date,
        'ne'     => count($ents), 'ents' => $ents, 'total' => $total,
        'canon'  => array_map(fn ($e) => amr_canon($e['name']), $ents),
        'margin' => count($ents) >= 2 ? $ents[0]['pct'] - $ents[1]['pct'] : 100.0,
    ];
}

function all_match_results(): void
{
    $S = [
        'poll'    => fn ($a, $b) => $a['poll'] <=> $b['poll'],
        'contest' => fn ($a, $b) => [$a['cyear'], $a['cname'], $a['poll']] <=> [$b['cyear'], $b['cname'], $b['poll']],
        'date'    => fn ($a, $b) => [$a['date'], $a['poll']] <=> [$b['date'], $b['poll']],
        'total'   => fn ($a, $b) => $a['total'] <=> $b['total'],
        'margin'  => fn ($a, $b) => $a['margin'] <=> $b['margin'],
        'winner'  => fn ($a, $b) => strcasecmp($a['ents'][0]['name'] ?? '', $b['ents'][0]['name'] ?? ''),
        'ne'      => fn ($a, $b) => $a['ne'] <=> $b['ne'],
    ];
    $sort = (string)($_GET['sort'] ?? 'poll');
    if (!isset($S[$sort])) {
        $sort = 'poll';
    }
    $dir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

    $fEnt  = trim((string)($_GET['entrant'] ?? ''));
    $fCon  = trim((string)($_GET['contest'] ?? ''));
    $fEntC = amr_canon($fEnt);

    $rows     = amr_rows();
    $allCount = count($rows);
    if ($fEnt !== '') {
        $rows = array_values(array_filter($rows, fn ($r) => in_array($fEntC, $r['canon'], true)));
    }
    if ($fCon !== '') {
        $rows = array_values(array_filter($rows, fn ($r) => $r['cname'] === $fCon));
    }

    usort($rows, $S[$sort]);
    if ($dir === 'desc') {
        $rows = array_reverse($rows);
    }

    $nM   = 0;
    $byNe = [2 => 0, 3 => 0, 4 => 0];
    foreach ($rows as $r) {
        if ($r['poll'] <= 2566) {
            $nM++;
        } $byNe[$r['ne']]++;
    }
    $nU = count($rows) - $nM;

    // /node/100 URL = current params + overrides, with defaults (sort=poll, dir=asc) and blanks dropped
    $cur = ['sort' => $sort, 'dir' => $dir, 'entrant' => $fEnt, 'contest' => $fCon];
    $url = function (array $ov) use ($cur) {
        $p = array_merge($cur, $ov);
        $p = array_filter($p, fn ($v) => $v !== '' && $v !== null);
        if (($p['sort'] ?? 'poll') === 'poll') {
            unset($p['sort']);
        }
        if (($p['dir'] ?? 'asc') === 'asc') {
            unset($p['dir']);
        }

        return '/node/100' . ($p ? '?' . http_build_query($p) : '');
    };
    $u = fn (array $ov) => htmlspecialchars($url($ov), ENT_QUOTES);

    $arrow = fn (string $k) => $sort === $k ? ($dir === 'asc' ? ' &#9650;' : ' &#9660;') : '';
    $th    = fn (string $k, string $lbl) => "<th><a href='" . $u(['sort' => $k, 'dir' => ($sort === $k && $dir === 'asc') ? 'desc' : 'asc'])
        . "'>" . htmlspecialchars($lbl) . $arrow($k) . '</a></th>';
    $ecell = function (?array $en) use ($u) {
        if (!$en) {
            return "<td class='amr-x'></td>";
        }

        return '<td><a href="' . $u(['entrant' => amr_canon($en['name'])]) . '">'
             . htmlspecialchars($en['name'], ENT_QUOTES) . '</a>'
             . "<br><span class='amr-sub'>" . number_format($en['votes']) . ' &middot; '
             . number_format($en['pct'], 2) . '%</span></td>';
    };

    ob_start(); ?>
<p>Final vote counts for every contest match. Entrants are ordered by final votes, so
<strong>A is the winner</strong>. Click a column heading to sort, or an entrant / contest to filter.</p>

<p class="amr-views"><strong>Quick views:</strong>
 <a href="<?= $u(['sort' => 'poll', 'dir' => 'asc']) ?>">chronological</a> &middot;
 <a href="<?= $u(['sort' => 'contest', 'dir' => 'asc']) ?>">by contest</a> &middot;
 <a href="<?= $u(['sort' => 'margin', 'dir' => 'asc']) ?>">closest matches</a> &middot;
 <a href="<?= $u(['sort' => 'margin', 'dir' => 'desc']) ?>">biggest blowouts</a> &middot;
 <a href="<?= $u(['sort' => 'total', 'dir' => 'desc']) ?>">highest turnout</a> &middot;
 <a href="<?= $u(['sort' => 'ne', 'dir' => 'desc']) ?>">multi-entrant first</a></p>

<?php if ($fEnt !== '' || $fCon !== ''): ?>
<p class="amr-filter"><strong>Filtered:</strong>
<?php if ($fEnt !== ''): ?>
 entrant = <strong><?= htmlspecialchars($fEntC) ?></strong>
 <a href="<?= $u(['entrant' => '']) ?>" title="remove this filter">[&times;]</a>
<?php endif; ?>
<?php if ($fCon !== ''): ?>
 <?= $fEnt !== '' ? '&middot;' : '' ?> contest = <strong><?= htmlspecialchars($fCon) ?></strong>
 <a href="<?= $u(['contest' => '']) ?>" title="remove this filter">[&times;]</a>
<?php endif; ?>
 &nbsp; &mdash; <?= number_format(count($rows)) ?> of <?= number_format($allCount) ?> matches
 &nbsp; <a href="/node/100">show all</a></p>
<?php endif; ?>

<p class="amr-meta"><?= number_format(count($rows)) ?> matches
 &middot; 2-entrant <?= number_format($byNe[2]) ?>
 &middot; 3-entrant <?= number_format($byNe[3]) ?>
 &middot; 4-entrant <?= number_format($byNe[4]) ?>
<?php if ($fEnt === '' && $fCon === ''): ?>
 &middot; <?= count(AMR_SKIP) ?> matches omitted (listed below)
<?php endif; ?></p>

<div class="amr-wrap"><table class="amr">
<thead><tr>
 <th>#</th>
 <?= $th('poll', 'Poll') ?>
 <?= $th('contest', 'Contest') ?>
 <?= $th('date', 'Date') ?>
 <?= $th('ne', 'n') ?>
 <?= $th('winner', 'A — winner') ?>
 <th>B</th><th>C</th><th>D</th>
 <?= $th('total', 'Total') ?>
 <?= $th('margin', 'Margin') ?>
</tr></thead>
<tbody>
<?php if (!$rows): ?>
<tr><td colspan="11">No matches for this filter.</td></tr>
<?php endif; ?>
<?php $i = 0;
    foreach ($rows as $r): $i++;
        $fl = AMR_FLAG[$r['poll']] ?? null; ?>
<tr>
 <td class="amr-n"><?= $i ?></td>
 <td class="amr-n"><a href="https://gamefaqs.gamespot.com/poll/<?= $r['poll'] ?>-" rel="nofollow"><?= $r['poll'] ?></a><?php
           if ($fl): ?> <span class="amr-flag" title="<?= htmlspecialchars($fl, ENT_QUOTES) ?>">&dagger;</span><?php endif; ?></td>
 <td><a href="<?= $u(['contest' => $r['cname']]) ?>"><?= htmlspecialchars($r['cname']) ?></a></td>
 <td class="amr-n"><?= htmlspecialchars($r['date']) ?></td>
 <td class="amr-n"><?= $r['ne'] ?></td>
 <?php
           echo str_replace('<td>', '<td class="amr-win">', $ecell($r['ents'][0] ?? null));
        echo $ecell($r['ents'][1] ?? null);
        echo $ecell($r['ents'][2] ?? null);
        echo $ecell($r['ents'][3] ?? null);
        ?>
 <td class="amr-n"><?= number_format($r['total']) ?></td>
 <td class="amr-n"><?= number_format($r['margin'], 2) ?>%</td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>

<div class="amr-notes">
<h3>Name aliases</h3>
<p>These spelling variants are treated as one entrant when you filter by name:</p>
<ul>
<?php
$aliasGroups = [];
    foreach (AMR_ALIAS as $aliasVariant => $aliasCanon) {
        $aliasGroups[$aliasCanon][] = $aliasVariant;
    }
    foreach ($aliasGroups as $aliasCanon => $aliasVariants): ?>
 <li><strong><?= htmlspecialchars($aliasCanon) ?></strong> &mdash; also <?= htmlspecialchars(implode(', ', $aliasVariants)) ?></li>
<?php endforeach; ?>
</ul>
<h3>Omitted matches</h3>
<ul>
<?php foreach (AMR_SKIP as $mn => $why): ?>
 <li>Poll <?= $mn ?> &mdash; <?= htmlspecialchars($why) ?></li>
<?php endforeach; ?>
</ul>
<h3>&dagger; Flagged matches (shown, with a note)</h3>
<ul>
<?php foreach (AMR_FLAG as $mn => $why): ?>
 <li>Poll <a href="https://gamefaqs.gamespot.com/poll/<?= $mn ?>-" rel="nofollow"><?= $mn ?></a>
     &mdash; <?= htmlspecialchars($why) ?></li>
<?php endforeach; ?>
</ul>
<p class="amr-help"><b>Margin</b> is the winner&rsquo;s percentage minus the runner-up&rsquo;s.
For 3- and 4-entrant matches it can look small even when the winner was never in danger,
because the vote splits more ways &mdash; the <b>n</b> column shows how many entrants a match had.
&ldquo;Highest turnout&rdquo; leans toward the 2007 Character Battle, which ran four entrants per poll.</p>
</div>
<?php
        echo ob_get_clean();
}
