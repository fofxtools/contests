<p>This is a generalized x-stat estimator that assumes creativename's naive linear model, as opposed to the more complicated and computationally intensive non-linear conditional probability model proposed by King Morgoth. The basis is the assumption that strength ratios are retained.</p>
<p>The formula is:<br />
<!--LaTeX formula:
\%_i = \frac{\frac{X_i}{1-X_i}}{\sum_{i}^{n}\frac{X_i}{1-X_i}}
-->
<img src="/assets/formula.gif" alt="\%_i = \frac{\frac{X_i}{1-X_i}}{\sum_{i}^{n}\frac{X_i}{1-X_i}}" /><br />
Where <em>X.i</em> are the input values for <em>n</em> inputs, scaled to x-stat values with the strongest in the pack set at 50%.
</p>

<?php
$input_values = trim($_GET['input_values'] ?? '');
if($input_values!='')
{
	$form_values = $input_values;
	$line_array = explode("\n", $input_values);
	$name_array = array();
	$value_array = array();
	for($i=0; $i<count($line_array); $i++)
	{
		# Line should already be urldecode()ed automatically
		$this_line_array = explode("=", $line_array[$i]);
		if(count($this_line_array)==2)
		{
			$name_array[$i] = $this_line_array[0];
			$value_array[$i] = (float) $this_line_array[1];
		}
		else
		{
			$entrant = $i+1;
			$name_array[$i] = "Entrant ".$entrant;
			$value_array[$i] = (float) $this_line_array[0];
		}
	}
	$max = max($value_array);
	for($i=0; $i<count($value_array); $i++)
	{
		$diff = $value_array[$i]-$max;
		if($diff>0) $max = $value_array[$i];
	}
	for($i=0; $i<count($value_array); $i++)
	{
		#	Convert to an x-stat
		$value_array[$i] = $value_array[$i] / $max / 2;
	}
	for($i=0; $i<count($value_array); $i++)
	{
		#	Convert to a ratio
		$value_array[$i] = $value_array[$i] / (1 - $value_array[$i]);
	}
	$sum = array_sum($value_array);
	echo "<h3>Estimated Results</h3>\n";
	echo "<table>";
	if(count($name_array)>0) echo "<th>Entrant</th>";
	echo "<th>Percentage</th>\n";
	for($i=0; $i<count($value_array); $i++)
	{
		#	Convert to the percentage
		$value_array[$i] /= $sum;
		echo "<tr>";
		if(count($name_array)>0) echo "<td>".htmlspecialchars((string)$name_array[$i], ENT_QUOTES)."</td>";
		echo "<td>".sprintf("%0.3f", round($value_array[$i]*100,3))."%</td>";
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
Enter strength values separated by newline (example values have been pre-filled)
<br />
All values are assumed to be percentages. They must be between 0 and 100.
<br />
You can also optionally pass names, for example: <a href="/node/53?input_values=Link%3D50%0D%0ACloud%3D47.5%0D%0ASnake%3D40" title="Link vs. Cloud vs. Snake">Link vs. Cloud vs. Snake</a>
<br />
<textarea name="input_values" cols=20 rows=9>
<?php echo htmlspecialchars((string)$form_values, ENT_QUOTES); ?>
</textarea>
<br />
<input type=submit value="Submit">
</form>