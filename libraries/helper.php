<?php

use PhpPgAdmin\Core\AppContainer;

function pg_escape_id($id = ''): string
{
	$pg = AppContainer::getPostgres();
	return pg_escape_identifier($pg->conn->_connectionID, $id);
}

function htmlspecialchars_nc(
	$string,
	$flags = ENT_QUOTES | ENT_SUBSTITUTE,
	$encoding = 'UTF-8',
	$double_encode = true
) {
	if ($string === null) {
		return '';
	}
	return htmlspecialchars($string, $flags, $encoding, $double_encode);
}

/**
 * Format a string according to a template and values from an array or object
 * Field names in the template are enclosed in {}, i.e., {name} reads $data['name'] or $data->{'name'}
 * To the right of the field name, a sprintf-like format string can be defined, starting with :
 * Example: {amount:06.2f} formats $data['amount'] as a decimal number in the format 0000.00
 * ? can be used to specify an optional default value, which can also be empty if the field is not set
 * Example: Hello {person}, you have {currency?$} {amount:0.2f} credit
 * Format: '{' name [':' fmt] ['?' [default]] '}'
 * @param string $template
 * @param array|object $data
 * @return string
 */
function format_string($template, $data)
{
	$isObject = is_object($data);
	$pattern = '/(?<left>[^{]*)\{(?<name>\w+)(:(?<pad>\'.|0| )?(?<justify>-)?(?<minlen>\d+)?(\.(?<prec>\d+))?(?<type>[a-zA-Z]))?(?<optional>\?.*)?\}(?<right>.*)/';
	while (preg_match($pattern, $template, $match)) {
		$fieldName = $match['name'];
		$fieldExists = $isObject ? isset($data->{$fieldName}) : isset($data[$fieldName]);
		if (!$fieldExists) {
			if (isset($match['optional'])) {
				$template = $match['left'] . substr($match['optional'], 1) . $match['right'];
				continue;
			} else {
				$template = $match['left'] . '[?' . $match['name'] . ']' . $match['right'];
				continue;
			}
		} else {
			$param = $isObject ? $data->{$fieldName} : $data[$fieldName];
		}
		if (strlen($padding = $match['pad'])) {
			if ($padding[0] == '\'') {
				$padding = $padding[1];
			}
		} else {
			$padding = ' ';
		}
		$precision = $match['prec'] ? intval($match['prec']) : null;
		switch ($match['type']) {
			case 'b':
				$subst = base_convert($param, 10, 2);
				break;
			case 'c':
				$subst = chr($param);
				break;
			case 'd':
				$subst = (string) (int) $param;
				break;
			case 'f':
			case 'F':
				if ($precision !== null) {
					$subst = number_format((float) $param, $precision);
				} else {
					$subst = (string) (float) $param;
				}
				break;
			case 'o':
				$subst = base_convert($param, 10, 8);
				break;
			case 'p':
				$subst = (string) (round((float) $param, $precision) * 100);
				break;
			case 's':
			default:
				$subst = (string) $param;
				break;
			case 'u':
				$subst = (string) abs((int) $param);
				break;
			case 'x':
				$subst = strtolower(base_convert($param, 10, 16));
				break;
			case 'X':
				$subst = base_convert($param, 10, 16);
				break;
		}
		$minLength = (int) $match['minlen'];
		if ($match['justify'] != '-') {
			// justify right
			if (strlen($subst) < $minLength) {
				$subst = str_repeat($padding, $minLength - strlen($subst)) . $subst;
			}
		} else {
			// justify left
			if (strlen($subst) < $minLength) {
				$subst .= str_repeat($padding, $minLength - strlen($subst));
			}
		}
		$template = $match['left'] . $subst . $match['right'];
	}
	return $template;
}

/**
 * SQL query extractor with multibyte string support
 * @param string $sql
 * @return string[]
 */
function extractSqlQueries($sql)
{
	$queries = [];
	$current = "";
	$inString = false;
	$stringChar = null;
	$inLineComment = false;
	$inBlockComment = false;

	$len = mb_strlen($sql);

	for ($i = 0; $i < $len; $i++) {
		$c = mb_substr($sql, $i, 1);
		$n = ($i + 1 < $len) ? mb_substr($sql, $i + 1, 1) : null;

		// Line comment --
		if (!$inString && !$inBlockComment && $c === "-" && $n === "-") {
			$inLineComment = true;
		}

		// End line comment
		if ($inLineComment && $c === "\n") {
			$inLineComment = false;
		}

		// Block comment /*
		if (!$inString && !$inLineComment && $c === "/" && $n === "*") {
			$inBlockComment = true;
		}

		// End block comment */
		if ($inBlockComment && $c === "*" && $n === "/") {
			$inBlockComment = false;
			$i++;
			continue;
		}

		// Strings '...' or "..."
		if (!$inLineComment && !$inBlockComment) {
			if (!$inString && ($c === "'" || $c === '"')) {
				$inString = true;
				$stringChar = $c;
			} elseif ($inString && $c === $stringChar) {
				$inString = false;
			}
		}

		// Semicolon ends query
		if (!$inString && !$inLineComment && !$inBlockComment && $c === ";") {
			$trimmed = trim($current);
			if ($trimmed !== "") {
				$queries[] = $trimmed;
			}
			$current = "";
			continue;
		}

		$current .= $c;
	}

	$trimmed = trim($current);
	if ($trimmed !== "") {
		$queries[] = $trimmed;
	}

	return $queries;
}

/**
 * Check if SQL contains only read-only queries
 * @param string $sql
 * @return bool
 */
function isSqlReadQuery($sql)
{
	$statements = extractSqlQueries($sql);
	if (count($statements) === 0) {
		return false;
	}

	foreach ($statements as $stmt) {
		$upper = strtoupper($stmt);

		if (strlen($upper) < 7) {
			return false;
		}

		$isRead = substr_compare($upper, "SELECT", 0, 6) === 0 ||
			substr_compare($upper, "WITH", 0, 4) === 0 ||
			substr_compare($upper, "SET", 0, 3) === 0 ||
			substr_compare($upper, "SHOW", 0, 4) === 0;

		if ($isRead) {
			continue;
		}

		$isRead = substr_compare($upper, "EXPLAIN", 0, 7) === 0;
		if ($isRead) {
			$rest = trim(substr($upper, 7));
			$isRead = substr_compare($rest, "SELECT", 0, 6) === 0 ||
				substr_compare($rest, "WITH", 0, 4) === 0;
			if ($isRead) {
				continue;
			}
		}

		return false;
	}

	return true;
}
