<?php
echo "<p style='background:#f5f5f5;border:1px solid #ccc;padding:.5em .8em;margin:0 0 1em;'>"
   . "This detailed table &mdash; with seeds, rounds, and Oracle / Board Odds Project "
   . "predictions &mdash; covers the <strong>2002&ndash;2006</strong> contests only and is no "
   . "longer maintained. For a plain results table (entrants, votes, percentages) covering "
   . "every contest through 2020, see <a href='/node/100'>All Match Results</a>."
   . "</p>";

$contest = "all";
$node = "19";
echo displaymatches($contest, $node);
?>
