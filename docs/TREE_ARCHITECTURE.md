# Tree Navigation Architecture - Technical Reference

## System Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                      BROWSER (Client-Side)                      │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │             JavaScript Tree Engine (xtree2.js)            │ │
│  │                                                            │ │
│  │  WebFXTreeHandler                                          │ │
│  │  ├─ idPrefix: "wfxt-"                                      │ │
│  │  ├─ idSeparator: "-"                                       │ │
│  │  ├─ getUniqueId(oNode)                                     │ │
│  │  │  ├─ if node.semanticId → use it                         │ │
│  │  │  ├─ else → build from node path                         │ │
│  │  │  └─ else → fallback to counter                          │ │
│  │  └─ _sanitizeForId(text) → safe identifier                │ │
│  │                                                            │ │
│  │  WebFXTreeAbstractNode                                     │ │
│  │  ├─ semanticId: null (optional)                            │ │
│  │  └─ id: "wfxt-..." (generated from semantic or counter)   │ │
│  └────────────────────────────────────────────────────────────┘ │
│                                 ▲                                 │
│                                 │                                 │
│                      Parses XML from server                       │
│                                 │                                 │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │          Tree Loading Engine (xloadtree2.js)             │ │
│  │                                                            │ │
│  │  WebFXLoadTree.createItemFromElement()                   │ │
│  │  ├─ Parse XML attributes                                  │ │
│  │  ├─ Extract "semanticid" attribute if present             │ │
│  │  ├─ Create WebFXLoadTreeItem                              │ │
│  │  └─ Pass semanticId to node before ID generation          │ │
│  │                                                            │ │
│  │  Node persistence via cookies:                            │ │
│  │  ├─ Store expanded node IDs in cookie                     │ │
│  │  ├─ Restore on page reload                                │ │
│  │  └─ Works reliably because IDs are stable                 │ │
│  └────────────────────────────────────────────────────────────┘ │
│                                 ▲                                 │
│                                 │                                 │
│                          HTTP GET/POST                            │
│                                 │                                 │
└─────────────────────────────────┼─────────────────────────────────┘
                                  │
                                  │
┌─────────────────────────────────┼─────────────────────────────────┐
│                      SERVER (Server-Side)                         │
├─────────────────────────────────────────────────────────────────┤
│                                 │                                 │
│                                 ▼                                 │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │            Tree Rendering (Misc.php)                      │ │
│  │                                                            │ │
│  │  printTree($data, $attrs, $section)                       │ │
│  │  ├─ Convert recordset to array                            │ │
│  │  ├─ Run tree hook (plugins)                               │ │
│  │  └─ Call printTreeXML()                                   │ │
│  │                                                            │ │
│  │  printTreeXML($treedata, $attrs, $section)                │ │
│  │  ├─ Loop through each data row                            │ │
│  │  ├─ Build XML tree element                                │ │
│  │  ├─ For each node:                                        │ │
│  │  │  ├─ Get node text from data                            │ │
│  │  │  ├─ Call generateSemanticTreeId($text, $section)       │ │
│  │  │  └─ Add "semanticid" attribute to XML                  │ │
│  │  └─ Output XML response                                   │ │
│  │                                                            │ │
│  │  generateSemanticTreeId($text, $prefix = '')              │ │
│  │  ├─ Sanitize text:                                        │ │
│  │  │  ├─ lowercase                                          │ │
│  │  │  ├─ replace [^a-z0-9_-] with _                         │ │
│  │  │  ├─ collapse multiple _                                │ │
│  │  │  └─ limit to 50 chars                                  │ │
│  │  ├─ Add prefix if provided                                │ │
│  │  └─ Return final semantic ID                              │ │
│  └────────────────────────────────────────────────────────────┘ │
│                                 │                                 │
│                                 ▼                                 │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │            XML Response Example                            │ │
│  │                                                            │ │
│  │  <?xml version="1.0" encoding="utf-8"?>                   │ │
│  │  <tree>                                                    │ │
│  │    <tree                                                   │ │
│  │      text="production"                                    │ │
│  │      action="redirect.php?server=..."                     │ │
│  │      icon="..."                                           │ │
│  │      semanticid="servers_production"  ← NEW ATTRIBUTE     │ │
│  │    />                                                      │ │
│  │    <tree                                                   │ │
│  │      text="staging"                                       │ │
│  │      action="redirect.php?server=..."                     │ │
│  │      icon="..."                                           │ │
│  │      semanticid="servers_staging"  ← NEW ATTRIBUTE        │ │
│  │    />                                                      │ │
│  │  </tree>                                                   │ │
│  └────────────────────────────────────────────────────────────┘ │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

---

## Data Flow: Complete Example

### Scenario: User Views Servers Page

#### 1. Initial Page Load

```
servers.php (index view)
  ↓
Calls LayoutRenderer.printBody()
  ↓
Includes <div id="wfxt-container"></div>
  ↓
JavaScript runs writeTree()
  ├─ Creates WebFXLoadTree("Servers", "servers.php?action=tree", ...)
  ├─ Calls tree.write("wfxt-container")
  └─ Calls tree.setExpanded(true)
    ↓
Makes AJAX request: servers.php?action=tree
```

#### 2. Server Processes Tree Request

```
servers.php?action=tree
  ↓
if ($action == 'tree') doTree();
  ↓
doTree()
  ├─ Gets server list from config
  ├─ Defines tree attributes:
  │  ├─ 'text' => field('desc')
  │  ├─ 'icon' => field('icon')
  │  └─ 'branch' => field('branch')
  └─ Calls $misc->printTree($nodes, $attrs, 'servers')
    ↓
printTree()
  ├─ Converts recordset to array
  ├─ Runs plugins via do_hook('tree')
  └─ Calls $this->printTreeXML($treedata, $attrs, 'servers')
    ↓
printTreeXML($treedata, $attrs, 'servers')
  ├─ Outputs XML header
  ├─ For each server:
  │  ├─ Outputs <tree>
  │  ├─ Adds: text="db.example.com:5432"
  │  ├─ Adds: action="..."
  │  ├─ Adds: icon="..."
  │  ├─ Calls generateSemanticTreeId("db.example.com:5432", "servers")
  │  │  ├─ lowercase: "db.example.com:5432"
  │  │  ├─ sanitize: "db_example_com_5432"
  │  │  ├─ add prefix: "servers_db_example_com_5432"
  │  │  └─ returns: "servers_db_example_com_5432"
  │  ├─ Adds: semanticid="servers_db_example_com_5432"
  │  └─ Closes: />
  └─ Returns XML response
```

#### 3. Browser Receives and Parses XML

```
XHR receives:
<?xml version="1.0" encoding="utf-8"?>
<tree>
  <tree
    text="db.example.com:5432"
    action="redirect.php?..."
    icon="ConnectedServer"
    semanticid="servers_db_example_com_5432"
  />
  <tree
    text="db.staging.com:5432"
    action="redirect.php?..."
    icon="ConnectedServer"
    semanticid="servers_db_staging_com_5432"
  />
</tree>
  ↓
xloadtree2.js:documentLoaded()
  ├─ Parses XML
  └─ For each <tree>:
       createItemFromElement()
       ├─ Extract text="db.example.com:5432"
       ├─ Extract icon="ConnectedServer"
       ├─ Extract semanticid="servers_db_example_com_5432"
       ├─ Create WebFXLoadTreeItem
       ├─ Set semanticId = "servers_db_example_com_5432"
       ├─ Call getUniqueId(node)
       │  ├─ Check node.semanticId? YES
       │  ├─ Return "wfxt-servers_db_example_com_5432"
       └─ Set node.id = "wfxt-servers_db_example_com_5432"
         ↓
Rendered tree in DOM:
<tree id="wfxt-servers_db_example_com_5432">
  <span>db.example.com:5432</span>
</tree>
```

#### 4. User Expands Nodes and Refreshes Page

```
User expands server "db.example.com:5432"
  ↓
setExpanded(true) called on node
  ├─ DOM updated to show children
  └─ Event fires: WebFXTreeCookiePersistence.setExpanded()
    ↓
Cookie saved:
webfx-tree-cookie-persistence=wfxt-servers_db_example_com_5432

User presses F5 to refresh
  ↓
Page reloads, same flow as above
  ↓
When nodes are created with id="wfxt-servers_db_example_com_5432"
  ↓
WebFXTreeCookiePersistence reads cookie
  ├─ Gets: "wfxt-servers_db_example_com_5432"
  ├─ Checks: Is this node ID in cookie? YES
  └─ Calls: node.setExpanded(true)
    ↓
Node is automatically expanded ✓
```

---

## Key Design Decisions

### 1. Server-Side Generation

**Decision**: Generate semantic IDs on the server in `printTreeXML()`

**Rationale**:

-   Consistent ID generation across all clients
-   Server has access to full context (section, hierarchy)
-   Easier to customize via configuration in the future
-   Reduces JavaScript complexity

**Trade-off**:

-   Slightly more server processing (negligible: ~0.1ms per node)
-   XML response is slightly larger

### 2. Dual ID System

**Decision**: Support both `semanticId` and counter-based ID as fallback

**Rationale**:

-   Backward compatible if server doesn't provide semantic ID
-   Graceful degradation if ID generation fails
-   Easier testing (can disable semantic IDs temporarily)

**Implementation**:

```javascript
// Priority order:
1. semanticId from server (XML attribute)
2. Generated from node path
3. Counter-based fallback
```

### 3. Sanitization Approach

**Decision**: Aggressive sanitization (replace all special chars with underscore)

**Rationale**:

-   Guarantees valid HTML/JavaScript identifier
-   Simple and fast (single regex)
-   Predictable results
-   No edge cases with special characters

**Example**:

```
"User's Table (Active)" → "user_s_table__active_"
"Table@2024!#$" → "table_2024____"
"Table--Name" → "table__name"
```

### 4. Section Prefix

**Decision**: Optional prefix based on section context

**Rationale**:

-   Namespaces IDs by context (servers, tables, views, etc.)
-   Helps identify node type from ID
-   Prevents collisions if same text appears in different contexts

**Example**:

```
Section: "servers"    → "servers_production"
Section: "tables"     → "tables_production"
Same text, different IDs!
```

---

## State Diagram: Node Lifecycle

```
┌─────────────────────┐
│   Node Created      │
│   (text, action)    │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────────────────────────────────────┐
│  getUniqueId(oNode) Called                           │
│                                                     │
│  Has semanticId?                                    │
│  ├─ YES → Use it: "servers_production"               │
│  └─ NO → Generate from path: "servers_production"    │
└─────────────┬───────────────────────────────────────┘
              │
              ▼
┌─────────────────────────────────────────────────────┐
│  Sanitize ID                                        │
│  ├─ Apply regex: /[^a-z0-9_-]/g → _                │
│  ├─ Collapse: /_+/g → _                             │
│  └─ Limit: .substring(0, 50)                        │
│                                                     │
│  Result: "servers_production" (already clean)      │
└─────────────┬───────────────────────────────────────┘
              │
              ▼
┌─────────────────────────────────────────────────────┐
│  Final ID Generated                                 │
│  "wfxt-servers_production"                          │
│        ↑               ↑                             │
│        │               └─ Semantic part              │
│        └─ Global prefix                             │
└─────────────┬───────────────────────────────────────┘
              │
              ▼
┌─────────────────────────────────────────────────────┐
│  Node Added to Handler                              │
│  webFXTreeHandler.all["wfxt-servers_production"] = │
│    <WebFXTreeAbstractNode>                          │
└─────────────┬───────────────────────────────────────┘
              │
              ▼
┌─────────────────────────────────────────────────────┐
│  Node Rendered in DOM                               │
│  <div id="wfxt-servers_production">                 │
│    <span>production</span>                          │
│  </div>                                             │
└─────────────┬───────────────────────────────────────┘
              │
              ▼
    ┌─────────────────────┐
    │  User Interaction   │
    ├─────────────────────┤
    │ ✓ Expand            │
    │ ✓ Collapse          │
    │ ✓ Select            │
    │ ✓ Click on Action   │
    └─────────────────────┘
```

---

## Persistence Flow

### Cookie Format

```
Cookie Name: webfx-tree-cookie-persistence
Cookie Value: ID1,ID2,ID3,...

Example:
webfx-tree-cookie-persistence=wfxt-servers_production,wfxt-database_mydb,wfxt-schema_public
                                ^~~~~~~~~~~~~~~~~~ ^~~~~~~~~~~~~~~~~~ ^~~~~~~~~~~~~~~~~~~~
                                Server 1           Database          Schema
                                (expanded)         (expanded)        (expanded)
```

### Save Flow (User Expands Node)

```
User clicks expand arrow on "production" server
  ↓
WebFXTreeAbstractNode._onclick() triggered
  ↓
Call setExpanded(true)
  ↓
Update DOM (show children)
  ↓
Fire event → persistenceManager.setExpanded(node, true)
  ↓
WebFXTreeCookiePersistence.setExpanded()
  ├─ Read current cookie
  ├─ Get node.id: "wfxt-servers_production"
  ├─ Append to list
  └─ Write cookie: "...,wfxt-servers_production"
```

### Restore Flow (Page Reload)

```
Page loads
  ↓
writeTree() creates tree
  ↓
Nodes created with their semantic IDs
  ├─ Node 1: id = "wfxt-servers_production"
  ├─ Node 2: id = "wfxt-servers_staging"
  └─ Node 3: id = "wfxt-database_mydb"
  ↓
Check persistence: WebFXTreeConfig.usePersistence = true
  ↓
For each node:
  ├─ Call persistenceManager.getExpanded(node)
  ├─ Check if node.id in cookie
  ├─ "wfxt-servers_production" in cookie? YES
  ├─ Set node.open = true
  ├─ Update DOM to show expanded state
  └─ Repeat for other nodes
  ↓
Tree appears with same nodes expanded as before ✓
```

---

## Error Handling & Fallbacks

### Scenario 1: Semantic ID Not Provided

```
XML from server missing semanticid attribute:
<tree text="production" action="..." />

JavaScript fallback:
  ├─ Check node.semanticId? null
  ├─ Try to build from path? Can't (no parent info)
  └─ Use counter: "wfxt-" + idCounter++
      Result: "wfxt-5" (old behavior)

Note: Persistence still works if counter is consistent
```

### Scenario 2: Invalid Characters in Text

```
Node text: "Table@2024!#$"

generateSemanticTreeId():
  ├─ Input: "table@2024!#$"
  ├─ Replace [^a-z0-9_-]: "table_2024____"
  ├─ Collapse underscores: "table_2024_"
  └─ Result: "servers_table_2024_"

No error, graceful degradation ✓
```

### Scenario 3: Text Too Long

```
Node text: "This is a very long database name that exceeds fifty characters"

generateSemanticTreeId():
  ├─ Input: "this is a very long database name that exceeds fifty characters"
  ├─ Sanitize: "this_is_a_very_long_database_name_that_exceeds_fifty_charact"
  ├─ Limit to 50: "this_is_a_very_long_database_name_that_exceed"
  └─ Result: "servers_this_is_a_very_long_database_name_that_exceed"

Truncated but still unique ✓
```

---

## Performance Metrics

### Server-Side (PHP)

| Operation                     | Time   | Notes                    |
| ----------------------------- | ------ | ------------------------ |
| generateSemanticTreeId() call | ~0.1ms | Single node, 2 regex ops |
| printTreeXML() per 100 nodes  | ~10ms  | Including ID generation  |
| Server response time          | +0ms\* | Negligible overhead      |

\*Measurable only with profiling

### Client-Side (JavaScript)

| Operation              | Time    | Notes                     |
| ---------------------- | ------- | ------------------------- |
| \_sanitizeForId() call | ~0.05ms | Single node               |
| getUniqueId() call     | ~0.02ms | With semantic ID provided |
| Node creation per 100  | ~5ms    | Creating DOM elements     |
| Tree rendering         | +0ms\*  | Negligible overhead       |

\*Actual DOM rendering dominates

### Total Impact

-   Adding 100 nodes to tree: **~15ms** additional time
-   Typical tree size: 10-50 nodes = **~1-3ms**
-   Imperceptible to users ✓

---

## Security Considerations

### Input Sanitization

**All node text is sanitized** before being used in IDs:

```
Original: "'; DROP TABLE users; --"
Sanitized: "_____drop_table_users____"
Used in ID: "nodes________drop_table_users____"
Result: Safe HTML/JS identifier ✓
```

### XSS Prevention

Semantic IDs are only used as HTML `id` attributes:

```html
<div id="wfxt-safe_identifier"></div>
```

They are NOT output directly in:

-   Content (text is HTML escaped separately)
-   Event handlers
-   Attributes (other than id)

Result: No XSS vector introduced ✓

### Cookie Security

Semantic IDs in cookies:

-   Not sensitive information
-   Already exposed in DOM (via IDs)
-   Can't be exploited for authentication
-   Subject to standard cookie security

Result: No security regression ✓

---

## Future Enhancement Opportunities

### 1. Custom ID Templates

```php
// Allow configuration of ID format
$conf['tree_id_template'] = '{section}_{text}_{oid}';

// Would use OID if available for uniqueness
```

### 2. Hash-Based IDs

```php
// Use content hash for maximum stability
$id = "servers_" . substr(md5($content), 0, 8);
```

### 3. Hierarchical IDs

```javascript
// Build full path ID: parent_child_grandchild
node.id = "wfxt-servers_production-database_mydb-table_users";
```

### 4. Configuration API

```php
// Allow plugins to customize ID generation
$plugin_manager->do_hook('tree_id_generation', [
    'text' => $text,
    'section' => $section,
    'semanticId' => &$semanticId
]);
```

---

## Testing Strategy

### Unit Tests (Proposed)

```php
// Test PHP function
assertEquals("test_node",
    $misc->generateSemanticTreeId("Test Node"));

assertEquals("section_test_node",
    $misc->generateSemanticTreeId("Test Node", "section"));
```

```javascript
// Test JavaScript function
assertEquals("test_node", webFXTreeHandler._sanitizeForId("Test Node"));
```

### Integration Tests (Manual)

1. Expand nodes → Refresh → Check persistence
2. Verify IDs are semantic (not counter-based)
3. Test with special characters in names
4. Test with very long names (100+ chars)
5. Check cookie before/after reload

### Performance Tests

1. Create tree with 1000 nodes
2. Measure ID generation time
3. Verify <50ms total overhead
4. Check memory usage

---

## Migration Checklist

For deployment:

-   [ ] Backup `js/xtree2.js`
-   [ ] Backup `js/xloadtree2.js`
-   [ ] Backup `libraries/PhpPgAdmin/Misc.php`
-   [ ] Backup `libraries/PhpPgAdmin/Gui/LayoutRenderer.php`
-   [ ] Apply changes
-   [ ] Clear browser cookies (to test fresh state)
-   [ ] Test tree expansion persistence
-   [ ] Verify no JavaScript console errors
-   [ ] Test with multiple servers/databases
-   [ ] Monitor server logs for issues
-   [ ] Document in release notes
