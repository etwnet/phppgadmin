<?php
include_once('./libraries/bootstrap.php');
/** @var Postgres $data */
/** @var Misc $misc */
/** @var array $lang */

if (isset($_POST['offset']))
	$offset = " OFFSET {$_POST['offset']}";
else {
	$_POST['offset'] = 0;
	$offset = " OFFSET 0";
}

$keynames = [];
foreach ($_POST['keynames'] as $k => $v) {
	$keynames[$k] = html_entity_decode($v, ENT_QUOTES);
}
$f_keynames = [];
foreach ($_POST['f_keynames'] as $k => $v) {
	$f_keynames[$k] = html_entity_decode($v, ENT_QUOTES);
}

$keyspos = array_combine($f_keynames, $keynames);

$f_schema = html_entity_decode($_POST['f_schema'], ENT_QUOTES);
$data->fieldClean($f_schema);
$f_table = html_entity_decode($_POST['f_table'], ENT_QUOTES);
$data->fieldClean($f_table);
$f_attname = $f_keynames[$_POST['fattpos'][0]];
$data->fieldClean($f_attname);

$q = "SELECT *
		FROM \"{$f_schema}\".\"{$f_table}\"
		WHERE \"{$f_attname}\"::text LIKE '{$_POST['fvalue']}%'
		ORDER BY \"{$f_attname}\" LIMIT 12 {$offset};";

$res = $data->selectSet($q);

if (!$res->EOF) {
	echo "<table class=\"ac_values\">";
	echo '<tr>';
	foreach (array_keys($res->fields) as $h) {
		echo '<th>';

		if (in_array($h, $f_keynames))
			echo '<img src="' . $misc->icon('ForeignKey') . '" alt="[referenced key]" />';

		echo htmlentities($h, ENT_QUOTES, 'UTF-8'), '</th>';

	}
	echo "</tr>\n";
	$i = 0;
	while ((!$res->EOF) && ($i < 11)) {
		$j = 0;
		echo "<tr class=\"acline\">";
		foreach ($res->fields as $n => $v) {
			$finfo = $res->fetchField($j++);
			if (in_array($n, $f_keynames)) {
				$field_name = htmlspecialchars($keyspos[$n]);
				echo "<td><a href=\"javascript:void(0)\" class=\"fkval\" name=\"{$field_name}\">",
				$misc->printVal($v, $finfo->type, array('clip' => 'collapsed')),
				"</a></td>";
			}
			else {
				echo "<td><a href=\"javascript:void(0)\">",
				$misc->printVal($v, $finfo->type, array('clip' => 'collapsed')),
				"</a></td>";
			}
		}
		echo "</tr>\n";
		$i++;
		$res->moveNext();
	}
	echo "</table>\n";

	$page_tests = '';

	$js = "<script type=\"text/javascript\">\n";

	if ($_POST['offset']) {
		echo "<a href=\"javascript:void(0)\" id=\"fkprev\">&lt;&lt; Prev</a>";
		$js .= "fkl_hasprev=true;\n";
	} else
		$js .= "fkl_hasprev=false;\n";

	if ($res->recordCount() == 12) {
		$js .= "fkl_hasnext=true;\n";
		echo "&nbsp;&nbsp;&nbsp;<a href=\"javascript:void(0)\" id=\"fknext\">Next &gt;&gt;</a>";
	} else
		$js .= "fkl_hasnext=false;\n";

	echo $js . "</script>";
} else {
	printf("<p>{$lang['strnofkref']}</p>", "\"{$_POST['f_schema']}\".\"{$_POST['f_table']}\".\"{$f_keynames[$_POST['fattpos']]}\"");

	if ($_POST['offset'])
		echo "<a href=\"javascript:void(0)\" class=\"fkprev\">Prev &lt;&lt;</a>";
}

