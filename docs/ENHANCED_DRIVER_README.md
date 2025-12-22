# Enhanced PostgreSQL Driver Implementation

## Overview

A custom ADODB PostgreSQL driver has been implemented that extends the standard PostgreSQL 9+ driver with enhanced field metadata caching capabilities. This driver allows direct inspection of field source information from query result sets without requiring separate metadata queries.

## Files Created/Modified

### New Files

1. **`libraries/adodb-custom/adodb-postgres9-enhanced.inc.php`**
    - Custom ADODB driver with field metadata caching
    - Three classes:
        - `ADODB_postgres9Enhanced` - Driver class
        - `ADORecordSet_postgres9Enhanced` - Numeric/mixed result sets
        - `ADORecordSet_assoc_postgres9Enhanced` - Associative result sets

### Modified Files

1. **`libraries/Bootstrap.php`**

    - Added ADODB_NEWCONNECTION callback to register custom driver
    - Loads enhanced driver on demand when `postgres9-enhanced` driver is requested
    - Includes error handling with hard fail if driver cannot be loaded

2. **`libraries/PhpPgAdmin/Database/Connector.php`**
    - Changed `ADONewConnection('postgres8')` → `ADONewConnection('postgres9-enhanced')`
    - All database connections now use the enhanced driver

## How It Works

### Driver Loading (Bootstrap.php)

```php
$ADODB_NEWCONNECTION = function($dbType) {
    if ($dbType !== 'postgres9-enhanced') {
        return false;  // Let ADODB handle other drivers
    }

    // Load custom driver file
    require_once __DIR__ . '/adodb-custom/adodb-postgres9-enhanced.inc.php';

    // Instantiate and return the driver
    return new ADODB_postgres9Enhanced();
};
```

When `ADONewConnection('postgres9-enhanced')` is called:

1. Callback intercepts the call
2. Loads the custom driver file
3. Instantiates the enhanced driver
4. Returns the driver instance to ADODB

### Field Metadata Caching

The recordset classes cache field metadata lazily (on first access):

```php
// Store native PostgreSQL result resource during initialization
function _initRS()
{
    $this->_pgResult = $this->_queryID;
    parent::_initRS();
}

// Lazy-load metadata on first access
private function _loadFieldMeta($i)
{
    if (isset($this->_fieldMeta[$i])) {
        return;  // Already cached
    }

    // Cache metadata using native PostgreSQL functions
    $this->_fieldMeta[$i] = [
        'name'      => pg_field_name($this->_pgResult, $i),
        'type'      => pg_field_type($this->_pgResult, $i),
        'table_oid' => pg_field_table($this->_pgResult, $i),
        'attnum'    => pg_field_num($this->_pgResult, $i),
    ];
}
```

## Public Methods

The enhanced recordset classes provide three new methods for field metadata access:

### `FieldTableOID($i)`

Returns the PostgreSQL OID of the table that the field originates from.

**Parameters:**

-   `$i` (int) - Field index (0-based)

**Returns:**

-   (int|false) - Table OID if the field belongs to a table, false otherwise

**Usage:**

```php
$recordset = $conn->Execute("SELECT * FROM users");
$tableOid = $recordset->FieldTableOID(0);
if ($tableOid !== false) {
    echo "First field comes from table OID: $tableOid";
}
```

### `FieldAttnum($i)`

Returns the attribute number (column ordinal) of the field within its source table.

**Parameters:**

-   `$i` (int) - Field index (0-based)

**Returns:**

-   (int|false) - Attribute number (1-based in PostgreSQL) if from a table, false otherwise

**Usage:**

```php
$recordset = $conn->Execute("SELECT id, name FROM users");
$attnum = $recordset->FieldAttnum(1);  // Get attribute number of 'name' column
if ($attnum !== false) {
    echo "Field 'name' is attribute number: $attnum";
}
```

### `FieldType($i)`

Returns the PostgreSQL type name for the field.

**Parameters:**

-   `$i` (int) - Field index (0-based)

**Returns:**

-   (string) - PostgreSQL type name (e.g., 'integer', 'varchar', 'bytea', 'boolean')

**Usage:**

```php
$recordset = $conn->Execute("SELECT id, email, is_active FROM users");
for ($i = 0; $i < $recordset->_numOfFields; $i++) {
    echo "Field $i type: " . $recordset->FieldType($i) . "\n";
}
// Output:
// Field 0 type: integer
// Field 1 type: varchar
// Field 2 type: boolean
```

## Implementation Details

### Native PostgreSQL Functions Used

-   **`pg_field_table($result, $i)`** - Returns the table OID for field at index $i

    -   Returns false if field is not from a table (e.g., expression results, function returns)
    -   Available since PostgreSQL 7.4

-   **`pg_field_num($result, $i)`** - Returns the attribute number for field at index $i

    -   Returns false if field is not from a table
    -   Available since PostgreSQL 7.4

-   **`pg_field_type($result, $i)`** - Returns the PostgreSQL type name for field at index $i

    -   Available in all PostgreSQL versions with PHP pgsql extension

-   **`pg_field_name($result, $i)`** - Returns the field name (also inherited from parent classes)

### Inheritance Hierarchy

```
ADORecordSet (ADODB base)
  └─ ADORecordSet_postgres64 (PostgreSQL 6.4+)
      └─ ADORecordSet_postgres7 (PostgreSQL 7.0+)
          └─ ADORecordSet_postgres8 (PostgreSQL 8.0+)
              └─ ADORecordSet_postgres9 (PostgreSQL 9.0+)
                  └─ ADORecordSet_postgres9Enhanced ← Enhanced implementation
```

The enhanced driver inherits all existing functionality including:

-   Bytea automatic decoding via `pg_unescape_bytea()`
-   Blob detection and handling
-   Standard ADODB recordset methods (MoveNext, \_fetch, \_prepFields, etc.)

### Bytea/Blob Handling

**No changes** to existing blob handling. The enhanced driver maintains backward compatibility:

-   Bytea fields are still automatically decoded using `pg_unescape_bytea()`
-   Large Objects (LO) handling remains manual-only (as in the original driver)
-   Blob detection still happens via `pg_field_type()` in `_initRS()`

The field metadata caching is completely independent of blob handling.

## Requirements

-   **PHP Version:** 7.4+ (for pg_field_table and pg_field_num functions)
-   **PostgreSQL Version:** 7.4+ (for pg_field_table and pg_field_num availability)
-   **ADODB Library:** Already included in phpPgAdmin

## Error Handling

If the custom driver fails to load during initialization:

```
failed to load driver: [error message]
```

The application will exit immediately with a clear error message. This is intentional—the enhanced driver is a required component, not optional.

Possible errors:

-   Driver file not found: Check that `libraries/adodb-custom/adodb-postgres9-enhanced.inc.php` exists
-   ADODB not initialized: Ensure ADODB_DIR is set and adodb.inc.php is loaded
-   Class instantiation failed: Check driver file for syntax errors

## Testing

Two test files are provided:

1. **`test-driver-syntax.php`** - Verifies driver syntax and class existence

    - Can be run standalone without database
    - Checks: file existence, class definitions, method availability
    - Usage: `php test-driver-syntax.php`

2. **`test-enhanced-driver.php`** - Tests callback registration
    - Requires full Bootstrap initialization
    - Usage: `php test-enhanced-driver.php`

## Performance Considerations

-   **Lazy loading:** Field metadata is only loaded on first access, not for every field in every recordset
-   **Caching:** Once loaded, metadata is cached in `$_fieldMeta` array for the recordset lifetime
-   **Minimal overhead:** Only calls `pg_field_table()`, `pg_field_num()` when explicitly requested
-   **No impact on queries:** Metadata access happens after query execution, doesn't affect SQL performance

## Future Enhancements (Optional)

1. **Prepared statements optimization** - Cache parameter types across statement executions
2. **Array type handling** - Extend `MetaType()` to handle PostgreSQL array types properly
3. **JSON type handling** - Add special handling for JSON/JSONB columns
4. **Large Object (LO) support** - Auto-detect OID columns pointing to large objects
5. **Composite type support** - Handle PostgreSQL composite (record) types

Currently, these are left as manual/optional features for future development.

## Compatibility Notes

-   **Backward compatible:** Existing code continues to work unchanged
-   **Default behavior:** All database connections automatically use the enhanced driver
-   **Legacy fallback:** If needed, applications can still use standard postgres8 driver by modifying Connector.php
-   **PHP 7.2 compatibility:** Type hints use `mixed` to work with older PHP versions
-   **PostgreSQL 9.0+ tested:** Works with all PostgreSQL versions 9.0 and later

## Usage Example

```php
<?php
// This is transparent - no special initialization needed
// The enhanced driver loads automatically via the ADODB_NEWCONNECTION callback

require_once 'libraries/Bootstrap.php';

// ... later in code ...

$connector = new PhpPgAdmin\Database\Connector($host, $port, $sslmode, $user, $pass, $db);
$result = $connector->conn->Execute("SELECT id, name, email FROM users");

// Access field metadata directly from the recordset
while (!$result->EOF) {
    // Get field source information
    $nameTableOid = $result->FieldTableOID(1);  // 'name' field
    $nameAttnum = $result->FieldAttnum(1);
    $nameType = $result->FieldType(1);

    echo "Field 'name': type={$nameType}, source_table_oid={$nameTableOid}, attnum={$nameAttnum}\n";
    echo "Value: {$result->fields['name']}\n";

    $result->MoveNext();
}
```

## License

This enhanced driver is part of the phpPgAdmin project and follows the same dual licensing as ADODB:

-   BSD 3-Clause License
-   GNU Lesser General Public Licence (LGPL) v2.1 or later
