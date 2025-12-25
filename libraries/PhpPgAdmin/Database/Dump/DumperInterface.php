<?php

namespace PhpPgAdmin\Database\Dump;

/**
 * Interface for all dumper classes.
 */
interface DumperInterface
{
    /**
     * Performs the traditional dump - outputs complete SQL structure + data.
     * Used for full database/schema/table exports with complete control.
     * Output is written to output stream (if set) or echoed directly.
     * 
     * @param string $subject The subject to dump (e.g., 'table', 'schema', 'database')
     * @param array $params Parameters for the dump (e.g., ['table' => 'my_table', 'schema' => 'public'])
     * @param array $options Options for the dump (e.g., ['clean' => true, 'if_not_exists' => true, 'data_only' => false])
     * @return void
     */
    public function dump($subject, array $params, array $options = []);

    /**
     * Get data as ADORecordSet - returns table/view data only.
     * For data-only exports (CSV, JSON, XML, etc) via OutputFormatters.
     * Used with OutputFormatters to create various export formats.
     * 
     * @param array $params Parameters with 'table' or 'view' key
     * @return mixed ADORecordSet with table/view data, or null if not supported
     */
    public function getTableData(array $params);
}
