<p>Copy&Paste poll results from Internet Explorer, or input by hand, to calculate the x-stats for that poll.</p>

<?php
$input_values = trim($_GET['input_values'] ?? '');
if($input_values!='')
{
	$form_values = $input_values;
	$line_array = explode("\n", $input_values);
	$strongest = (float) ($_GET['strongest'] ?? '50.00');
	$entrants = array();
	$percents = array();
	$votes = array();
	for($i=0; $i<count($line_array); $i++) {
		preg_match("#(.*?)\s+(\d+\.\d+)%\s+(\d+)#", $line_array[$i], $matches);
		#	If matches has 4 elements, the split worked. Otherwise assume the line contains the votes/percentage.
		if(count($matches)==4)
		{

			$entrants[] = $matches[1];
			$percents[] = $matches[2];
			$votes[] = $matches[3];
		}
		else
		{
			$votes[] = (float) $line_array[$i];
		}
	}
	$max = max($votes);
	$scalar = $strongest/.5;
	echo "<table><tr>";
	if(count($entrants)) echo "<th>Entrant</th>";
	echo "<th>Value</th>";
	echo "</tr>\n";
	for($i=0; $i<count($votes); $i++) {
		echo "<tr>";
		if(count($entrants)) echo "<td>".htmlspecialchars((string)$entrants[$i], ENT_QUOTES)."</td>";
		echo "<td>".number_format(round($votes[$i]/($max+$votes[$i])*$scalar,2),2)."%</td>";
		echo "</tr>\n";
	}
	echo "</table>";
}
else
{
	$form_values = "50\n40\n30\n20";
}
?>

<br />
<form action="" method="GET" name="form">
Enter result values separated by newline (example values have been pre-filled)
<br />
<textarea name="input_values" cols=20 rows=9>
<?php echo htmlspecialchars((string)$form_values, ENT_QUOTES); ?>
</textarea>
<br />
Peg strongest at:<input type="text" name="strongest" value="50.00" size="2">%<br />
<input type="submit" value="Submit">
</form>