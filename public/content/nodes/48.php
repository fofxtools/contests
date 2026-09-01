<h2>Generalized X-stat calculator</h2>
<p>You can input character strength values below, and you will receive an estimate of how they would perform in a match against each other. This estimate is created using King Morgoth's <a href="http://www.oraclechallenge.com/boards/detail.php?board=8&topic=37564294&message=23063">conditional probability model</a>.</p>

<?php
$input_values = trim($_GET['input_values'] ?? '');
if ($input_values != '') {
    global $timer_array;
    scriptTimer('Stats', 'start');
    $line_array = explode("\n", $input_values);
    # Maximum of 12 characters
    $line_array     = array_slice($line_array, 0, 12);
    $name_array     = [];
    $value_array    = [];
    $default_values = '';
    for ($i = 0; $i < count($line_array); $i++) {
        # Line should already be urldecode()ed automatically
        $this_line_array = explode('=', $line_array[$i]);
        if (count($this_line_array) == 2) {
            $name_array[$i]  = $this_line_array[0];
            $value_array[$i] = $this_line_array[1];
            $default_values .= $name_array[$i] . '=' . $value_array[$i];
        } else {
            $char            = $i + 1;
            $name_array[$i]  = 'Character ' . $char;
            $value_array[$i] = $this_line_array[0];
            $default_values .= $value_array[$i];
        }
    }
    # Assume all values are percentages, and have to be greater than 0% and less than 100%
    /*for($i=0; $i<count($value_array); $i++)
    {
        $value_array[$i] = min(1, max(0, $value_array[$i]/100));
    }*/
    $table = get_extrapolated_table($value_array, $name_array);
    echo "<h3>Estimated Results</h3>\n$table";
    scriptTimer('Stats', 'end');
    $execution_time = $timer_array['Stats']['Elapsed'];
    echo '<br />Took ' . round($execution_time, 3) . " seconds.<br />\n";
} else {
    $default_values = "50\n40\n30\n20";
}
?>

<br />
<form action="" method="GET" name="form">
Enter strength values separated by newline (example values have been pre-filled)
<br />
All values are assumed to be percentages. They must be between 0 and 100.
<br />
Maximum of 12 characters.
<br />
<textarea name="input_values" cols=20 rows=9><?php echo htmlspecialchars((string)$default_values, ENT_QUOTES); ?></textarea>
<br />
<input type=submit value="Submit">
</form>

<br />
You can also optionally pass names, such as "Link=50". e.g., <a href="/node/48?input_values=Link%3D50%0D%0ACloud%3D46%0D%0ASephiroth%3D43.5%0D%0AMario%3D40%0D%0ASamus%3D40%0D%0ASnake%3D39%0D%0ASonic%3D35%0D%0AMega+Man%3D35%0D%0ACrono%3D35">Link vs. the Noble Nine</a> (assuming no SFF)