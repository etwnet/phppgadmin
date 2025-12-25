# Unified Export Architecture

## Overview

The export system now uses a unified architecture where all formatters accept ADORecordSet input, enabling a single code path for both database structure exports (via dumpers) and query result exports.

**Key Feature:** Output formatters support **streaming output** via output streams for memory efficiency on large exports, or collect output as strings for flexibility.

## Architecture Components

### 1. OutputFormatter Interface & Implementations

All formatters implement a consistent interface with streaming support:

```php
abstract class OutputFormatter
{
    // Set output stream for memory-efficient streaming
    public function setOutputStream($stream);  // Accepts resource|null

    // Format data (writes to stream if set, returns string otherwise)
    public function format(mixed $recordset, array $metadata = []): string;

    public function getMimeType(): string;
    public function getFileExtension(): string;
}
```

**Supported Formatters:**

-   `SqlFormatter` - SQL INSERT statements (single, multi-row) or COPY format
-   `CopyFormatter` - PostgreSQL COPY FROM stdin format
-   `CsvFormatter` - RFC 4180 CSV format
-   `TabFormatter` - Tab-delimited format
-   `HtmlFormatter` - XHTML table output
-   `XmlFormatter` - XML structure with metadata
-   `JsonFormatter` - JSON with column metadata

### 2. DumperInterface

Dumpers provide three export patterns:

```php
interface DumperInterface
{
    // Traditional full export: outputs complete SQL
    public function dump($subject, array $params, array $options = []);

    // Split export: returns [structure, recordset, columns, metadata]
    public function getDump($subject, array $params, array $options = []);

    // Data-only export: returns ADORecordSet
    public function getTableData(array $params);
}
```

**Supported Dumpers:**

-   `TableDumper` - Tables (with structure + data)
-   `ViewDumper` - Views (read-only data export)
-   `DatabaseDumper` - Full database dumps
-   `SchemaDumper` - Schema exports
-   And 12 other specialized dumpers

### 3. FormatterFactory

Centralizes formatter creation:

```php
$formatter = FormatterFactory::create('csv');
$output = $formatter->format($recordset, $metadata);
```

## Usage Patterns

### Pattern 1a: Query Export (String Collection - Default)

For exporting query results and collecting output as string:

```php
// Execute query
$recordset = $pg->conn->Execute($sql);

// Create formatter
$formatter = FormatterFactory::create('csv');

// Format data (collects in memory)
$metadata = ['table' => 'query_result', 'insert_format' => 'copy'];
$output = $formatter->format($recordset, $metadata);

// Send to client
header('Content-Type: text/csv');
echo $output;
```

### Pattern 1b: Query Export (Streaming - Memory Efficient)

For large exports, use output streams to avoid collecting entire output in memory:

```php
// Execute query
$recordset = $pg->conn->Execute($sql);

// Create formatter
$formatter = FormatterFactory::create('csv');

// Set output stream (streams directly to STDOUT)
$formatter->setOutputStream(STDOUT);

// Format data (writes to stream, does not return)
$metadata = ['table' => 'query_result'];
$formatter->format($recordset, $metadata);

// Output automatically sent to client
```

**Location:** `dataexport.php` uses streaming for non-gzipped output

### Pattern 1c: Query Export (Gzipped - Buffered)

For gzipped output, buffer to memory first, compress, then send:

```php
// Need to collect output first to compress
$recordset = $pg->conn->Execute($sql);
$formatter = FormatterFactory::create('csv');

// Use string collection mode
$formatter->setOutputStream(null);
$output = $formatter->format($recordset, $metadata);

// Compress and send
$output = gzencode($output, 9);
header('Content-Type: application/gzip');
echo $output;
```

### Pattern 2: Table Export (Structure + Data)

For exporting table structure with data:

```php
// Get dumper
$dumper = new TableDumper($connection);

// Get split dump
$params = ['table' => 'users', 'schema' => 'public'];
$dump = $dumper->getDump('table', $params, ['clean' => true]);

// Format data with chosen formatter
$formatter = FormatterFactory::create('json');
$dataOutput = $formatter->format($dump['recordset'], $dump['metadata']);

// Combine structure + formatted data
$output = $dump['structure'] . "\n\n" . $dataOutput;
```

**Location:** `dbexport.php` (can use either `dump()` or `getDump()`)

### Pattern 3: Full Database Export

For traditional SQL exports:

```php
// Get dumper for entire database
$dumper = new DatabaseDumper($connection);

// Direct dump to stdout
ob_start();
$dumper->dump('database', $params, $options);
$output = ob_get_clean();

// Send to client as SQL
header('Content-Type: application/sql');
echo $output;
```

**Location:** `dbexport.php` (traditional path)

## Data Flow

### Query Export Flow

```
SQL Query
  ↓
$recordset = Execute()
  ↓
$formatter = FormatterFactory::create('csv')
  ↓
$output = $formatter->format($recordset)
  ↓
Send to client
```

### Table Export Flow

```
Table Name + Schema
  ↓
$dumper = new TableDumper($connection)
  ↓
$dump = $dumper->getDump('table', $params)
  ↓
// $dump['structure'] contains CREATE TABLE SQL
// $dump['recordset'] contains table data
  ↓
$formatter = FormatterFactory::create('json')
  ↓
$output = $formatter->format($dump['recordset'])
  ↓
Send structure + formatted data
```

## Output Stream Support

### Two Output Modes

OutputFormatters support two output modes for flexibility:

#### 1. String Collection Mode (Default)

Without setting an output stream, formatters collect and return output as string:

```php
$formatter = FormatterFactory::create('csv');
$output = $formatter->format($recordset, $metadata);
// $output contains formatted string, can be manipulated, compressed, etc.
```

**Advantages:**

-   Output can be modified after generation (e.g., compress, add headers)
-   Works with buffering and capture mechanisms
-   Easy to test

**Disadvantages:**

-   Entire output held in memory
-   Slower for very large datasets

#### 2. Streaming Mode (Memory Efficient)

Set an output stream to write directly without collecting:

```php
$formatter = FormatterFactory::create('csv');
$formatter->setOutputStream(STDOUT);  // Or any resource
$formatter->format($recordset, $metadata);
// Output written directly to stream
```

**Advantages:**

-   Memory efficient - no string collection
-   Faster for large datasets
-   Natural streaming support

**Disadvantages:**

-   Cannot modify output after generation
-   Not suitable for compression (unless buffered first)

### Practical Example: dataexport.php

```php
// Direct streaming for uncompressed exports
if ($output !== 'gzipped') {
    $formatter->setOutputStream(STDOUT);
    $formatter->format($rs, $metadata);
} else {
    // Buffer for gzip compression
    $formatter->setOutputStream(null);  // String mode
    $output = $formatter->format($rs, $metadata);
    $output = gzencode($output, 9);
    echo $output;
}
```

## Implementation Details

### Formatter Input: ADORecordSet

All formatters expect an ADODB RecordSet as input:

```php
class CsvFormatter implements OutputFormatterInterface
{
    public function format(mixed $recordset, array $metadata = []): string
    {
        $output = '';

        // Process headers
        if ($recordset->RecordCount() > 0) {
            // ... process column names
        }

        // Process rows
        $recordset->moveFirst();
        while (!$recordset->EOF) {
            foreach ($recordset->fields as $field) {
                // ... format field value
            }
            $recordset->moveNext();
        }

        return $output;
    }
}
```

### Dumper Integration

Dumpers can provide data as ADORecordSet via `getTableData()`:

```php
class TableDumper extends AbstractDumper
{
    public function getTableData($params)
    {
        $table = $params['table'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        $this->connection->conn->setFetchMode(ADODB_FETCH_NUM);
        $recordset = $this->connection->dumpRelation($table, false);

        return $recordset;
    }
}
```

## Metadata Object

The `$metadata` parameter allows formatters to access context:

```php
$metadata = [
    'table' => 'users',                    // Table/result name
    'insert_format' => 'copy',             // For SQL: 'copy', 'single', 'multi'
    'schema' => 'public',                  // Schema name
    'columns' => [...],                    // Column definitions (optional)
    'exported_at' => date('Y-m-d H:i:s')   // Timestamp
];
```

Different formatters use different metadata fields:

-   **SqlFormatter** - uses `insert_format` and `table`
-   **HtmlFormatter** - uses `table` for header
-   **JsonFormatter** - uses `table`, `columns` for metadata section
-   **CsvFormatter** - ignores metadata

## Benefits

1. **Single Code Path**: One formatter implementation handles both queries and dumper results
2. **Streaming Capable**: Row-by-row processing never loads full dataset
3. **Flexible**: Mix structure and data exports easily
4. **Consistent**: All formats work with all data sources
5. **Extensible**: Add new formatters without changing consumer code
6. **Memory Efficient**: ADORecordSet supports lazy loading

## Migration Guide

### Old Pattern (dataexport.php - before)

```php
// ~150 lines of inline CSV/JSON/XML/HTML formatting code
if ($format === 'csv') {
    // ... build CSV manually
} elseif ($format === 'json') {
    // ... build JSON manually
} // ... repeat for each format
```

### New Pattern (dataexport.php - after)

```php
// 3 lines of unified formatter code
$formatter = FormatterFactory::create($output_format);
$output_buffer = $formatter->format($rs, $metadata);
echo $output_buffer;
```

**Result:** Reduced from ~150 lines to ~3 lines, with better separation of concerns.

## Files Modified

### Core Infrastructure

-   `libraries/PhpPgAdmin/Database/Dump/DumperInterface.php` - Added getDump() and getTableData()
-   `libraries/PhpPgAdmin/Database/Dump/AbstractDumper.php` - Implemented getDump() and getTableData()

### Formatters (All Refactored)

-   `libraries/PhpPgAdmin/Database/Export/OutputFormatter.php` - Changed signature to accept ADORecordSet
-   `libraries/PhpPgAdmin/Database/Export/SqlFormatter.php` - Generates INSERT/COPY from recordset
-   `libraries/PhpPgAdmin/Database/Export/CopyFormatter.php` - Generates COPY format from recordset
-   `libraries/PhpPgAdmin/Database/Export/CsvFormatter.php` - Processes recordset directly
-   `libraries/PhpPgAdmin/Database/Export/TabFormatter.php` - Tab-delimited from recordset
-   `libraries/PhpPgAdmin/Database/Export/HtmlFormatter.php` - XHTML from recordset
-   `libraries/PhpPgAdmin/Database/Export/XmlFormatter.php` - XML from recordset
-   `libraries/PhpPgAdmin/Database/Export/JsonFormatter.php` - JSON from recordset

### Dumpers (Data Export Support)

-   `libraries/PhpPgAdmin/Database/Dump/TableDumper.php` - Added getTableData()
-   `libraries/PhpPgAdmin/Database/Dump/ViewDumper.php` - Added getTableData()

### Consumer Pages

-   `dataexport.php` - Simplified from ~150 lines of inline formatting to 3 lines using formatters

## Testing

To test the unified export system:

```php
// Test 1: Query export as CSV
$_REQUEST['action'] = 'export';
$_REQUEST['output_format'] = 'csv';
$_REQUEST['query'] = 'SELECT * FROM users LIMIT 10';
// ... dataexport.php handles it

// Test 2: Table export as JSON
$dumper = new TableDumper($connection);
$dump = $dumper->getDump('table',
    ['table' => 'users', 'schema' => 'public'],
    []
);
$formatter = FormatterFactory::create('json');
$output = $formatter->format($dump['recordset']);

// Test 3: Full database dump as SQL
$dumper = new DatabaseDumper($connection);
$dumper->dump('database', [], ['clean' => true]);
```

## Future Enhancements

1. **Custom Formatters**: Easy plugin system for new export formats
2. **Compression**: Built-in gzip/bzip2 support
3. **Incremental Exports**: Support for exporting only recent changes
4. **Format-Specific Options**: Column selection, filtering, sorting directly in formatters
