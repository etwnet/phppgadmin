<?php

namespace PhpPgAdmin\Gui;

use PhpPgAdmin\Core\AbstractContext;

/**
 * Class _FieldRenderer
 * @package PhpPgAdmin\Gui
 * @deprecated
 */
class _FieldRenderer extends AbstractContext {

	/**
	 * Outputs the HTML code for a particular field
	 * @param string $name The name to give the field
	 * @param string $value The value of the field.  Note this could be 'numeric(7,2)' sort of thing...
	 * @param string $type The database type of the field
	 * @param array $extras An array of attributes name as key and attributes' values as value
	 * @deprecated User FormRenderer
	 */
	function printField($name, $value, $type, $extras = []) {
		global $lang;

		if (!isset($value)) {
			$value = '';
		}

		$base_type = strstr($type, ' ', true) ?: substr($type, 0, 9);

		// Determine actions string
		if (!empty($extras['class'])) {
			$extras['class'] = $extras['class'] . ' ' . htmlspecialchars($base_type);
		} else {
			$extras['class'] = htmlspecialchars($base_type);
		}
		$extra_str = '';
		foreach ($extras as $k => $v) {
			$extra_str .= " {$k}=\"" . htmlspecialchars($v ?? '') . "\"";
		}
		$extra_str .= " data-type=\"" . htmlspecialchars($type) . "\"";

		//var_dump($type);

		switch ($base_type) {
		case 'bool':
		case 'boolean':
			if ($value == '') $value = null;
			elseif ($value == 'true') $value = 't';
			elseif ($value == 'false') $value = 'f';

			// If value is null, 't' or 'f'...
			if ($value === null || $value == 't' || $value == 'f') {
				/*
				echo "<select name=\"", htmlspecialchars($name), "\"{$extra_str}>\n";
				echo "<option value=\"\"", ($value === null) ? ' selected="selected"' : '', "></option>\n";
				echo "<option value=\"t\"", ($value == 't') ? ' selected="selected"' : '', ">{$lang['strtrue']}</option>\n";
				echo "<option value=\"f\"", ($value == 'f') ? ' selected="selected"' : '', ">{$lang['strfalse']}</option>\n";
				echo "</select>\n";
				*/
				$input_name = htmlspecialchars($name);
				echo "<label><input type=\"radio\" name=\"$input_name\" value=\"\"", ($value == null) ? " checked" : "", "> {$lang['strnull']}</label>&nbsp;&nbsp;&nbsp;";
				echo "<label><input type=\"radio\" name=\"$input_name\" value=\"t\"", ($value == 't') ? " checked" : "", "> {$lang['strtrue']}</label>&nbsp;&nbsp;&nbsp;";
				echo "<label><input type=\"radio\" name=\"$input_name\" value=\"f\"", ($value == 'f') ? " checked" : "", "> {$lang['strfalse']}</label>";
			} else {
				echo "<input name=\"", htmlspecialchars($name), "\" value=\"", htmlspecialchars($value ?? ''), "\" size=\"35\"{$extra_str} />\n";
			}
			break;
		case 'bytea':
		case 'bytea[]':
			if (!is_null($value)) {
				$value = $this->postgres()->escapeBytea($value);
			}
		case 'text':
		case 'text[]':
		case 'json':
		case 'jsonb':
		case 'xml':
		case 'xml[]':
			$n = substr_count($value ?? '', "\n");
			$n = $n < 5 ? 5 : $n;
			$n = $n > 20 ? 20 : $n;
			echo "<textarea name=\"", htmlspecialchars($name), "\" rows=\"{$n}\" cols=\"75\"{$extra_str}>\n";
			echo htmlspecialchars($value ?? '');
			echo "</textarea>\n";
			break;
		case 'character':
		case 'character[]':
			$n = substr_count($value, "\n");
			$n = $n < 5 ? 5 : $n;
			$n = $n > 20 ? 20 : $n;
			echo "<textarea name=\"", htmlspecialchars($name), "\" rows=\"{$n}\" cols=\"35\"{$extra_str}>\n";
			echo htmlspecialchars($value ?? '');
			echo "</textarea>\n";
			break;
		default:
			echo "<input name=\"", htmlspecialchars($name), "\" value=\"", htmlspecialchars($value ?? ''), "\" size=\"35\"{$extra_str} />\n";
			break;
		}
	}

}
