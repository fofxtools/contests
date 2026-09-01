To use this page, go to the <a href="http://www.gamefaqs.com/features/cb9_leaderboard">Leaderboard</a> and hit CNTRL-U to view the page source. Then copy that page source to the form below and hit submit.
<br /><br />
<form action="/node/65" method="POST" name="form">
<textarea name="source" cols=100 rows=10>
</textarea>
<br />
<input type=submit value="Submit">
</form>
<br />
<?php

$string = $_POST['source'] ?? '';
if ($string) {
    $scores  = [];
    $matches = [];
    $data    = preg_match('/Expert Battle Score.*?data: \[\[(.*?)\], \]\r\n/is', $string, $matches);
    if (!$data) {
        echo '<p><em>Could not find an Expert Battle Score data block in that page source.</em></p>';

        return;
    }
    $points = explode('], [', $matches[1]);
    $below  = 0;
    for ($i = 0; $i < count($points); $i++) {
        $item                = preg_match('/"(.*?)", (\d+)/i', $points[$i], $matches);
        $scores[$i]['score'] = stripslashes($matches[1]);
        $scores[$i]['count'] = $matches[2];
        $scores[$i]['below'] = $below;
        $below               = $below + $matches[2];
    }
    $scores[count($scores) - 1]['rank'] = 1;
    for ($i = count($scores) - 2; $i >= 0; $i--) {
        $scores[$i]['rank'] = $scores[$i + 1]['rank'] + $scores[$i + 1]['count'];
    }
    echo "<table><tr><th>Rank</th><th>Points</th><th>Count</th><th>Users Below</th><th>Percentile</th></tr>\n";
    for ($i = count($scores) - 1; $i >= 0; $i--) {
        $percentile = round($scores[$i]['below'] / $below, 5) * 100;
        $rank       = $below - $scores[$i]['below'];
        echo '<tr><td>' . $scores[$i]['rank'] . '</td><td>' . htmlspecialchars((string)$scores[$i]['score'], ENT_QUOTES) . '</td><td>' . $scores[$i]['count'] . '</td><td>' . $scores[$i]['below'] . '</td><td>' . $percentile . "%</td></tr>\n";
    }
    echo '</table>';
}
?>