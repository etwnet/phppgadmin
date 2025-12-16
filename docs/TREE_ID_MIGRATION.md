# Tree Navigation ID System Migration

## Overview

The tree navigation in phpPgAdmin has been updated to use **semantic IDs** instead of counter-based sequential IDs. This provides a more stable and deterministic identification system for tree nodes.

## Problem with Previous System

The old implementation used an incrementing counter (`webFXTreeHandler.idCounter`) that:

-   Reset to 0 on every page reload
-   Generated IDs like `wfxt-0`, `wfxt-1`, `wfxt-2`, etc.
-   Made tree state unstable and dependent on loading order
-   Made it difficult to restore tree expansion state reliably
-   Created ambiguous references when bookmarking or saving tree state

## New System: Semantic IDs

The new system generates **stable, meaningful IDs** based on node content:

### Examples

| Old ID   | New ID                 |
| -------- | ---------------------- |
| `wfxt-0` | `wfxt-production`      |
| `wfxt-1` | `wfxt-database_mydb`   |
| `wfxt-5` | `wfxt-table_customers` |
| `wfxt-8` | `wfxt-schema_public`   |

### How It Works

1. **Server-side generation** (PHP in `Misc.php`)

    - When rendering tree nodes in XML, each node gets a `semanticid` attribute
    - The ID is derived from the node's text content (e.g., server name, database name, table name)
    - Sanitization rules ensure safe, valid JavaScript/HTML identifiers

2. **Client-side generation** (JavaScript in `xtree2.js`)

    - When a semantic ID is provided, it's used as-is
    - If not provided, IDs are built from the node's path in the tree
    - Fallback to counter-based IDs only if semantic generation fails (legacy support)

3. **Persistence across reloads**
    - Because IDs are stable, tree expansion state persists correctly
    - Cookies store expanded node IDs
    - On page reload, the same nodes expand because IDs remain identical

## Files Modified

### 1. **js/xtree2.js**

-   Modified `webFXTreeHandler.getUniqueId()` to accept node parameter
-   Added `_sanitizeForId()` helper method
-   Added support for semantic ID generation from node path
-   Added `semanticId` property to `WebFXTreeAbstractNode`

### 2. **js/xloadtree2.js**

-   Added `"semanticid"` to the `_attrs` array for XML parsing
-   Updated `createItemFromElement()` to handle `semanticid` attribute from server
-   Assigns semantic ID to node before setting explicit ID

### 3. **libraries/PhpPgAdmin/Misc.php**

-   Added `generateSemanticTreeId($text, $prefix)` helper method
-   Updated `printTree()` to pass `$section` context to `printTreeXML()`
-   Updated `printTreeXML()` to:
    -   Accept optional `$section` parameter
    -   Generate semantic IDs for each tree node
    -   Output semantic IDs in XML as `semanticid` attributes

### 4. **libraries/PhpPgAdmin/Gui/LayoutRenderer.php**

-   Removed `webFXTreeHandler.idCounter = 0;` line from `writeTree()` function
-   Counter reset is no longer needed with semantic IDs

## ID Generation Algorithm

### Sanitization Rules (PHP)

```php
function generateSemanticTreeId($text, $prefix = '')
{
    // Convert to lowercase
    $id = strtolower($text);

    // Replace non-alphanumeric chars with underscore
    $id = preg_replace('/[^a-z0-9_-]/', '_', $id);

    // Collapse multiple underscores to single
    $id = preg_replace('/_+/', '_', $id);

    // Limit length to 50 chars
    $id = substr($id, 0, 50);

    // Add section prefix if provided
    if ($prefix) {
        $id = $prefix . '_' . $id;
    }

    return $id;
}
```

### Sanitization Rules (JavaScript)

```javascript
webFXTreeHandler._sanitizeForId = function (text) {
	if (!text) return "node";
	return text
		.toLowerCase()
		.replace(/[^a-z0-9_-]/g, "_")
		.replace(/_+/g, "_")
		.substring(0, 50);
};
```

## Benefits

1. ✅ **Stable IDs** - Same nodes always get the same IDs regardless of load order
2. ✅ **Better persistence** - Tree expansion state reliably restores
3. ✅ **Debuggable** - IDs are human-readable and meaningful
4. ✅ **Bookmarkable** - Tree state can be referenced by stable node IDs
5. ✅ **Backward compatible** - Falls back to counter-based IDs if needed
6. ✅ **Deterministic** - No dependency on JavaScript execution order

## Migration Notes

### For Developers

If you're creating custom tree nodes programmatically, you can now optionally provide a `semanticId`:

```javascript
const node = new WebFXTreeItem("My Node", actionUrl);
node.semanticId = "my_custom_id"; // Optional: provide custom semantic ID
```

If not provided, the system will generate one automatically.

### For Server-side Tree Rendering

Tree nodes are now rendered with semantic IDs automatically:

```xml
<tree
    text="production"
    action="..."
    icon="..."
    semanticid="servers_production"
/>
```

The `semanticid` attribute is parsed by `xloadtree2.js` and used for stable identification.

### Testing the Changes

1. **Verify ID generation**

    - Open browser DevTools
    - Inspect tree nodes
    - Confirm IDs are semantic (e.g., `wfxt-production`, `wfxt-database_test`)

2. **Test persistence**

    - Expand some tree nodes
    - Refresh the page
    - Confirm the same nodes remain expanded

3. **Check console**
    - Look for any JavaScript errors
    - Verify no deprecation warnings about the old counter

## Performance Impact

-   **Negligible** - ID generation is minimal overhead
-   Actually **improves performance** in some cases:
    -   Faster tree state restoration (no need to recalculate expanded nodes)
    -   Better cache hit rates for cookie-based persistence

## Compatibility

-   ✅ Modern browsers (Chrome, Firefox, Safari, Edge)
-   ✅ IE 11+ (uses standard DOM APIs)
-   ✅ All PostgreSQL versions supported by phpPgAdmin

## Troubleshooting

### IDs look corrupted or have many underscores

**Cause**: Nodes with special characters in their names  
**Solution**: This is expected; sanitization replaces special chars with underscores  
**Example**: `My Table!@#$` becomes `my_table_`

### Tree doesn't restore expanded state

**Cause**: Browser cookies might be blocked or cleared  
**Solution**: Check browser privacy settings and enable cookies  
**Alternative**: Check browser console for any JavaScript errors

### Old counter-based IDs still appearing

**Cause**: Semantic ID generation may have failed (graceful fallback)  
**Solution**: Check browser console for warnings; verify server-side `generateSemanticTreeId()` is working

## Future Improvements

Potential enhancements that could be added:

1. **Custom ID templates** - Allow specifying ID patterns per section
2. **Hash-based IDs** - Use content hashing for even more stability
3. **ID caching** - Cache generated IDs to avoid recalculation
4. **ID collision detection** - Warn when multiple nodes get the same ID

## References

-   [Previous implementation](../js/xtree2.js)
-   [Tree loading](../js/xloadtree2.js)
-   [Tree rendering](../libraries/PhpPgAdmin/Misc.php)
-   [UI initialization](../libraries/PhpPgAdmin/Gui/LayoutRenderer.php)
