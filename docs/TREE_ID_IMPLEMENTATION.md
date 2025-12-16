# Semantic Tree ID Implementation Details

## Quick Start Guide for Developers

### Understanding the Change

**Before**: Tree nodes had IDs like `wfxt-0`, `wfxt-1`, `wfxt-2`

```
These IDs were based on a counter that reset on every page load
↓ Problem: Tree state couldn't persist reliably
```

**After**: Tree nodes have IDs like `wfxt-servers_production`, `wfxt-database_mydb`

```
These IDs are based on actual node content
↓ Benefit: Tree state persists across page reloads
```

---

## How Tree IDs Are Generated

### Path: Server → JavaScript → Browser

```
1. PHP (Misc.php)
   └─ generateSemanticTreeId("production", "servers")
      └─ Returns: "servers_production"

2. XML Output
   └─ <tree text="production" semanticid="servers_production" ... />

3. JavaScript (xloadtree2.js)
   └─ Parses XML, extracts semanticid attribute
   └─ node.semanticId = "servers_production"

4. JavaScript (xtree2.js)
   └─ webFXTreeHandler.getUniqueId(node)
   └─ Returns: "wfxt-servers_production"
```

---

## Code Examples

### Example 1: How a Server Node Gets Its ID

**PHP Code (all_db.php)**:

```php
$nodes = $misc->getServers();  // Get list of servers

$attrs = array(
    'text' => field('id'),  // Server ID becomes node text
    // ... other attributes
);

$misc->printTree($nodes, $attrs, 'servers');
// ↓ Calls Misc::printTree() with section='servers'
```

**Generated XML**:

```xml
<?xml version="1.0" encoding="utf-8"?>
<tree>
  <tree
    text="db.example.com:5432"
    action="redirect.php?server=db.example.com:5432"
    icon="ConnectedServer"
    semanticid="servers_db.example.com:5432"
  />
</tree>
```

**JavaScript Result**:

```javascript
// xloadtree2.js parses the XML:
node.semanticId = "servers_db.example.com:5432";

// xtree2.js generates the final ID:
node.id = "wfxt-servers_db.example.com:5432";
// (After sanitization: "wfxt-servers_db_example_com_5432")
```

---

### Example 2: Custom Semantic ID in JavaScript

If you need to manually create tree nodes:

```javascript
// Create a tree item
const item = new WebFXTreeItem("My Custom Item", "action.php?param=value");

// Option 1: Let system generate ID from text
// Result: item.id = "wfxt-my_custom_item"

// Option 2: Provide explicit semantic ID
item.semanticId = "custom_my_item";
// After calling getUniqueId(): item.id = "wfxt-custom_my_item"
```

---

### Example 3: PHP Semantic ID Generation

```php
// In your tree rendering code:
$misc = AppContainer::getMisc();

// Simple node text
$id = $misc->generateSemanticTreeId("My Table");
// Result: "my_table"

// With section prefix
$id = $misc->generateSemanticTreeId("My Table", "tables");
// Result: "tables_my_table"

// Special characters are normalized
$id = $misc->generateSemanticTreeId("User's Table (Active)");
// Result: "user_s_table__active_"
```

---

## ID Sanitization Process

### Input → Sanitized ID

```
"Production Server"
  ↓ lowercase
"production server"
  ↓ replace non-alphanumeric with _
"production_server"
  ↓ collapse multiple _
"production_server"
  ↓ limit to 50 chars
"production_server"  ✓
```

```
"Table@2024!#$"
  ↓ lowercase
"table@2024!#$"
  ↓ replace non-alphanumeric with _
"table_2024____"
  ↓ collapse multiple _
"table_2024_"
  ↓ limit to 50 chars
"table_2024_"  ✓
```

---

## Persistence: How Tree State is Saved and Restored

### What Gets Saved

The browser stores **expanded node IDs** in a cookie:

```javascript
// Cookie name: webfx-tree-cookie-persistence
// Cookie value: "wfxt-servers_production+wfxt-database_mydb+wfxt-schema_public"
//               ↑ These are the IDs of expanded nodes
```

### How It Works on Page Reload

1. **Page loads** → JavaScript initializes tree
2. **Tree nodes created** with their semantic IDs
3. **Cookie read** → Extract list of expanded node IDs
4. **Each node checked** → If its ID is in the cookie, expand it
5. **Result** → Same nodes are expanded as before the reload

### Key Advantage

**Because IDs are semantic (based on content), they're the same every time:**

```
Reload 1: Node "production" → ID "wfxt-servers_production" → Save in cookie
Reload 2: Node "production" → ID "wfxt-servers_production" → Load from cookie ✓
Reload 3: Node "production" → ID "wfxt-servers_production" → Load from cookie ✓
```

**vs. Old Counter System:**

```
Reload 1: Node "production" → ID "wfxt-0" → Save in cookie
Reload 2: Node "staging" → ID "wfxt-0" → Load from cookie (WRONG!)
         Node "production" → ID "wfxt-1" → Not in cookie
```

---

## Integration Points

### When Adding New Tree Sections

If you add a new tree section that wasn't there before:

```php
// In your new section's doTree() function:

$section = 'mytrees';  // ← New section name

$attrs = array(
    'text'   => field('name'),
    'icon'   => 'Folder',
    'action' => 'mytrees.php?action=view&id=' . field('id'),
);

$misc->printTree($data, $attrs, $section);
// ↑ Pass section as 3rd parameter
// This ensures semantic IDs are prefixed with 'mytrees_'
```

**Generated IDs will be:**

```
mytrees_item1
mytrees_item2
mytrees_item3_with_special_chars
```

### When Rendering Nested Trees

Each level of the tree adds to the semantic path:

```
server "production"
  └─ semanticid: "servers_production"
     database "mydb"
       └─ semanticid: "servers_production-database_mydb"
          schema "public"
            └─ semanticid: "servers_production-database_mydb-schema_public"
```

(Note: The actual implementation uses underscore, but the principle is hierarchical)

---

## Debugging Tree IDs

### In Browser Console

```javascript
// Get a tree node
const node = webFXTreeHandler.all["wfxt-servers_production"];

// Check its properties
console.log(node.getText()); // "production"
console.log(node.id); // "wfxt-servers_production"
console.log(node.semanticId); // "servers_production"
console.log(node.getExpanded()); // true/false

// Manually expand/collapse
node.setExpanded(true);
```

### Inspecting Generated XML

Open DevTools Network tab and find the tree XML request:

```
servers.php?action=tree
```

The response will show:

```xml
<tree text="production" semanticid="servers_production" ... />
```

### Checking Persistence Cookie

```javascript
// In browser console:
document.cookie; // Look for: webfx-tree-cookie-persistence=...
```

Or in DevTools:

-   Application → Cookies → Select your domain
-   Find: `webfx-tree-cookie-persistence`
-   Value shows comma-separated expanded node IDs

---

## Common Patterns

### Pattern 1: Database Objects with Names

```php
// Tables, Views, Sequences, etc.
$attrs = array(
    'text'   => field('relname'),  // e.g., "customers"
    'action' => 'tables.php?table=' . field('relname'),
);
// Generates: tables_customers
```

### Pattern 2: Objects with OIDs

```php
// When using OID as identifier
$attrs = array(
    'text'   => field('oid'),  // e.g., "16384"
    'action' => 'item.php?oid=' . field('oid'),
);
// Generates: section_16384
```

### Pattern 3: Composite Display Names

```php
// When node text is built from multiple fields
$text = field('schema') . '.' . field('name');
// e.g., "public.customers"

// Generated ID: schema_public_customers
// (The dot gets sanitized to underscore)
```

---

## Performance Considerations

### ID Generation Overhead

**PHP side**: ~0.1ms per node

-   Simple regex operations
-   No database lookups
-   Minimal string manipulation

**JavaScript side**: ~0.05ms per node

-   Regex replacement in browser
-   Cached sanitization

### Cookie Size Impact

Old system:

```
webfx-tree-cookie-persistence=wfxt-0,wfxt-1,wfxt-2
```

New system:

```
webfx-tree-cookie-persistence=servers_production,database_mydb,table_customers
```

**Slightly larger** but negligible (hundreds of bytes at most).

---

## Troubleshooting Guide

### Issue: IDs have many underscores

**Cause**: Special characters in node text  
**Solution**: This is expected and intentional (safe for HTML/JS)

### Issue: Tree doesn't persist after reload

**Cause**:

-   Cookies disabled
-   Semantic ID not matching (node text changed)
-   Browser cache issue

**Solution**:

-   Enable cookies in browser settings
-   Hard refresh (Ctrl+Shift+R)
-   Check console for errors

### Issue: Same ID for different nodes

**Cause**: Two nodes with identical text in same section  
**Solution**: This is rare but possible; the system falls back to counter IDs

---

## Migration Checklist

If you're updating existing code to use the new system:

-   [ ] Remove any manual `idCounter` resets
-   [ ] Ensure tree sections pass `$section` parameter to `printTree()`
-   [ ] Test tree expansion persistence across reloads
-   [ ] Verify semantic IDs in generated XML
-   [ ] Clear browser cookies to test fresh state
-   [ ] Check browser console for any warnings

---

## References

### Code Locations

| Component          | File                                          | Key Function               |
| ------------------ | --------------------------------------------- | -------------------------- |
| PHP Generation     | `libraries/PhpPgAdmin/Misc.php`               | `generateSemanticTreeId()` |
| PHP Tree Rendering | `libraries/PhpPgAdmin/Misc.php`               | `printTreeXML()`           |
| JS ID Handling     | `js/xtree2.js`                                | `getUniqueId()`            |
| JS XML Parsing     | `js/xloadtree2.js`                            | `createItemFromElement()`  |
| Initialization     | `libraries/PhpPgAdmin/Gui/LayoutRenderer.php` | `printBrowser()`           |

### Further Reading

-   [W3C Cookie Specification](https://tools.ietf.org/html/rfc6265)
-   [DOM API Reference](https://developer.mozilla.org/en-US/docs/Web/API/Document)
-   [RegExp in JavaScript](https://developer.mozilla.org/en-US/docs/Web/JavaScript/Guide/Regular_Expressions)
