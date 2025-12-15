<?php

namespace PhpPgAdmin\Database\Actions;

use ADORecordSet;
use PhpPgAdmin\Database\AbstractActions;

class RowActions extends AbstractActions
{
	public $totalRowsFound = 0;
	public $lastQueryLimit = 0;
	public $lastQueryOffset = 0;

	/**
	 * Get the fields for uniquely identifying a row in a table
	 * @param string $table The table for which to retrieve the identifier
	 * @return array|int An array mapping attribute number to attribute name,
	 * empty for no identifiers.
	 * -1 on error
	 */
	public function getRowIdentifier($table) {
		$oldtable = $table;
		$c_schema = $this->connection->_schema;
		$this->connection->clean($c_schema);
		$this->connection->clean($table);

		$status = $this->connection->beginTransaction();
		if ($status != 0) return -1;

		// Get the first primary or unique index (sorting primary keys first) that
		// is NOT a partial index.
		$sql = "
			SELECT indrelid, indkey
			FROM pg_catalog.pg_index
			WHERE indisunique AND indrelid=(
				SELECT oid FROM pg_catalog.pg_class
				WHERE relname='{$table}' AND relnamespace=(
					SELECT oid FROM pg_catalog.pg_namespace
					WHERE nspname='{$c_schema}'
				)
			) AND indpred IS NULL AND indexprs IS NULL
			ORDER BY indisprimary DESC LIMIT 1";
		$rs = $this->connection->selectSet($sql);

		// If none, check for an OID column.  Even though OIDs can be duplicated,
		// the edit and delete row functions check that they're only modifying a
		// single row.  Otherwise, return empty array.
		if ($rs->recordCount() == 0) {
			// Check for OID column
			$temp = array();
			if ($this->connection->hasObjectID($table)) {
				$temp = array('oid');
			}
			$this->connection->endTransaction();
			return $temp;
		} // Otherwise find the names of the keys
		else {
			$attnames = (new TableActions($this->connection))
				->getAttributeNames(
					$oldtable, explode(' ', $rs->fields['indkey']));
			if (!is_array($attnames)) {
				$this->connection->rollbackTransaction();
				return -1;
			} else {
				$this->connection->endTransaction();
				return $attnames;
			}
		}
	}

	/**
	 * Returns a recordset of all columns in a query.  Supports paging.
	 * @param string $type Either 'QUERY' if it is an SQL query,
	 * or 'TABLE' if it is a table identifier,
	 * or 'SELECT" if it's a select query
	 * @param ?string $table The base table of the query.  NULL for no table.
	 * @param ?string $query The query that is being executed.  NULL for no query.
	 * @param ?array $orderby The columns to order by, for example 'id'=>'asc
	 * @param int $page The page of the relation to retrieve
	 * @param int $page_size The number of rows per page
	 * @param int &$max_pages (return-by-ref) The max number of pages in the relation
	 * @return ADORecordSet|int A recordset on success
	 * @return -1 transaction error
	 * @return -2 counting error
	 * @return -3 page or page_size invalid
	 * @return -4 unknown type
	 * @return -5 failed setting transaction read only
	 */
	public function browseQuery(
		$type, $table, $query, $orderby, $page, $page_size, &$max_pages
	) {
		// Check that we're not going to divide by zero
		if (!is_numeric($page_size) || $page_size != (int)$page_size || $page_size <= 0) return -3;

		// If $type is TABLE, then generate the query
		if (empty($query) && $type == "TABLE") {
			$query = $this->connection->getSelectSQL($table, array(), array(), array(), $orderby);
		}
		if (empty($query)) {
			return -4;
		}

		// Trim query
		$query = trim($query);

		// Trim off trailing semi-colon if there is one
		$query = rtrim($query, ';');

		// Generate count query
		$count = "SELECT COUNT(*) AS total FROM ({$query}) AS sub";

		// Open a transaction
		$status = $this->connection->beginTransaction();
		if ($status != 0) return -1;

		// If backend supports read only queries, then specify read only mode
		// to avoid side effects from repeating queries that do writes.
		if ($this->connection->hasReadOnlyQueries()) {
			$status = $this->connection->execute("SET TRANSACTION READ ONLY");
			if ($status != 0) {
				$this->connection->rollbackTransaction();
				return -5;
			}
		}


		// Count the number of rows
		$total = (int)$this->connection->selectField($count, 'total');
		if ($total < 0) {
			$this->connection->rollbackTransaction();
			return -2;
		}
		$this->totalRowsFound = $total;

		// Calculate max pages
		$max_pages = ceil($total / $page_size);

		// Check that page is less than or equal to max pages
		if (!is_numeric($page) || $page != (int)$page || $page > $max_pages || $page < 1) {
			$this->connection->rollbackTransaction();
			return -3;
		}

		// Set fetch mode to NUM so that duplicate field names are properly returned
		// for non-table queries.  Since the SELECT feature only allows selecting one
		// table, duplicate fields shouldn't appear.
		if ($type == 'QUERY') $this->connection->conn->setFetchMode(ADODB_FETCH_NUM);

		// Add ORDER BY fields
		if (!empty($orderby)) {
			$order_by = " ORDER BY ";
			$sep = "";
			foreach ($orderby as $field => $dir) {
				$order_by .= $sep . $this->connection->escapeIdentifier($field) . ' ' . $dir;
				$sep = ", ";
			}
		} else $order_by = "";

		$this->lastQueryLimit = $page_size;
		$this->lastQueryOffset = ($page - 1) * $page_size;
		// Actually retrieve the rows, with offset and limit
		$rs = $this->connection->selectSet("SELECT * FROM ({$query}) AS sub {$order_by} LIMIT {$page_size} OFFSET {$this->lastQueryOffset}");
		$status = $this->connection->endTransaction();
		if ($status != 0) {
			$this->connection->rollbackTransaction();
			return -1;
		}

		return $rs;
	}


	/**
	 * Returns a recordset of all columns in a table
	 * @param string $table The name of a table
	 * @param array $key The associative array holding the key to retrieve
	 * @return ADORecordSet A recordset
	 */
	public function browseRow($table, $key) {
		$f_schema = $this->connection->_schema;
		$this->connection->fieldClean($f_schema);
		$this->connection->fieldClean($table);

		$sql = "SELECT * FROM \"{$f_schema}\".\"{$table}\"";
		if (is_array($key) && sizeof($key) > 0) {
			$sql .= " WHERE true";
			foreach ($key as $k => $v) {
				$this->connection->fieldClean($k);
				$this->connection->clean($v);
				$sql .= " AND \"{$k}\"='{$v}'";
			}
		}

		return $this->connection->selectSet($sql);
	}

	/**
	 * Adds a new row to a table
	 * @param string $table The table in which to insert
	 * @param array $fields Array of given field in values
	 * @param array $values Array of new values for the row
	 * @param array $nulls An array mapping column => something if it is to be null
	 * @param array $functions An array of sql functions
	 * @param array $expr An array expression indicators
	 * @param array $types An array of field types
	 * @return int 0 success
	 * @return int -1 invalid parameters
	 */
	public function insertRow($table, $fields, $values, $nulls, $functions, $expr, $types) {

		if (!is_array($fields) || !is_array($values) || !is_array($nulls)
			|| !is_array($functions) || !is_array($expr) || !is_array($types)
			|| count($fields) != count($values) || count($types) != count($values)
		) return -1;

		// Build clause
		if (count($values) > 0) {
			// Escape all field names
			$fields = array_map(array('Postgres', 'fieldClean'), $fields);
			$f_schema = $this->connection->_schema;
			$this->connection->fieldClean($table);
			$this->connection->fieldClean($f_schema);

			$sql = "INSERT INTO \"{$f_schema}\".\"{$table}\" (\"" . implode('","', $fields) . "\") VALUES (";
			$sep = '';
			foreach ($values as $key => $value) {
				$sql .= $sep;
				// Handle NULL values
				if (isset($nulls[$key])) {
					$sql .= 'NULL';
				} else {
					$sql .= ',';
					$sql .= $this->formatValue($types[$key], $functions[$key] ?? null, isset($expr[$key]), $value);
				}
				$sep = ',';
			}
			$sql .= ")";

			return $this->connection->execute($sql);
		}

		return -1;
	}

	/**
	 * Updates a row in a table
	 * @param string $table The table in which to update
	 * @param array $values An array mapping new values for the row
	 * @param array $nulls An array mapping column => something if it is to be null
	 * @param array $functions An array of sql functions
	 * @param array $expr An array expression indicators
	 * @param array $types An array of field types
	 * @param array $keyarr An array mapping column => value to update
	 * @return int 0 success
	 * @return int -1 invalid parameters
	 */
	public function editRow($table, $values, $nulls, $functions, $expr, $types, $keyarr) {

		if (!is_array($values) || !is_array($nulls)
			|| !is_array($functions) || !is_array($expr) || !is_array($types)
			|| count($types) != count($values)
		) return -1;

		$f_schema = $this->connection->_schema;
		$this->connection->fieldClean($f_schema);
		$this->connection->fieldClean($table);

		// Build clause
		if (count($values) > 0) {

			$sql = "UPDATE \"{$f_schema}\".\"{$table}\" SET ";
			$sep = "";
			foreach ($values as $key => $value) {
				$sql .= $sep;
				$this->connection->fieldClean($key);
				$sql .= "\"{$key}\"=";

				// Handle NULL values
				if (isset($nulls[$key])) $sql .= 'NULL';
				else $sql .= $this->formatValue($types[$key], $functions[$key] ?? null, isset($expr[$key]), $value);

				$sep = ", ";
			}
			$first = true;
			foreach ($keyarr as $k => $v) {
				$this->connection->fieldClean($k);
				$this->connection->clean($v);
				if ($first) {
					$sql .= " WHERE \"{$k}\"='{$v}'";
					$first = false;
				} else {
					$sql .= " AND \"{$k}\"='{$v}'";
				}
			}
		}

		// Begin transaction.  We do this so that we can ensure only one row is
		// edited
		$status = $this->connection->beginTransaction();
		if ($status != 0) {
			$this->connection->rollbackTransaction();
			return -1;
		}

		$status = $this->connection->execute($sql);
		if ($status != 0) { // update failed
			$this->connection->rollbackTransaction();
			return -1;
		} elseif ($this->connection->conn->Affected_Rows() != 1) {
			// more than one row would be updated
			$this->connection->rollbackTransaction();
			return -2;
		}

		// End transaction
		return $this->connection->endTransaction();
	}

	/**
	 * Delete a row from a table
	 * @param string $table The table from which to delete
	 * @param array $key An array mapping column => value to delete
	 * @return int 0 success
	 */
	public function deleteRow($table, $key, $schema = null) {
		if (!is_array($key)) return -1;
		// Begin transaction.  We do this so that we can ensure only one row is
		// deleted
		$status = $this->connection->beginTransaction();
		if ($status != 0) {
			$this->connection->rollbackTransaction();
			return -1;
		}

		if (empty($schema)) $schema = $this->connection->_schema;

		$status = $this->connection->delete($table, $key, $schema);
		if ($status != 0 || $this->connection->conn->Affected_Rows() != 1) {
			$this->connection->rollbackTransaction();
			return -2;
		}

		// End transaction
		return $this->connection->endTransaction();
	}

}
