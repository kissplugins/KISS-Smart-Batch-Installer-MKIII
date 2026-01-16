========================================================================
 AI / LLM AGENT INSTRUCTIONS - SMART BATCH INSTALLER FSM
========================================================================

## 🚨 CRITICAL: FINITE STATE MACHINE (FSM) PROTECTION 🚨

This plugin's core architecture is built around a Finite State Machine (FSM) that manages
all repository states and business logic. The FSM is the heart of the system and must be
protected from accidental modification.

### ⚠️ FSM CORE COMPONENTS - DO NOT REFACTOR WITHOUT EXPLICIT REQUEST ⚠️

#### **PROTECTED FILES - HANDLE WITH EXTREME CARE:**

1. **`src/ts/admin/repositoryFSM.ts`** - Frontend TypeScript FSM
   - Core state management class
   - State storage and listener system
   - SSE real-time integration
   - Enhanced error handling (v1.0.32)

2. **`src/Services/StateManager.php`** - Backend PHP FSM
   - State transition validation
   - State persistence and caching
   - SSE event emission
   - Error context management

3. **`src/Enums/PluginState.php`** - PHP State Definitions
   - Core state enumeration
   - Database-stored values
   - State transition rules

4. **`src/ts/types/fsm.ts`** - TypeScript State Definitions
   - Frontend state enumeration
   - State utility functions
   - Must match PHP enum exactly

#### **CRITICAL METHODS - NEVER MODIFY WITHOUT EXPLICIT REQUEST:**

- `RepositoryFSM.set()` - Core state setter
- `RepositoryFSM.get()` - Core state getter
- `RepositoryFSM.onChange()` - Listener registration
- `StateManager.transition()` - Backend state transitions
- `RepositoryFSM.initSSE()` - Real-time updates
- `getActionableErrorMessage()` - Enhanced error messages

### 🛡️ FSM PROTECTION RULES

#### **RULE 1: NO UNAUTHORIZED FSM MODIFICATIONS**
- Do NOT modify FSM core files without explicit user request
- Do NOT change state values or enum definitions
- Do NOT alter state transition logic
- Do NOT modify SSE integration code

#### **RULE 2: EXTEND, DON'T REPLACE**
- Add new functionality around the FSM, not within it
- Use existing state transitions for new features
- Leverage existing error handling mechanisms
- Build on top of existing SSE infrastructure

#### **RULE 3: MANDATORY USER CONSULTATION**
When a task requires FSM modification, you MUST:

1. **STOP** and inform the user that FSM changes are needed
2. **EXPLAIN** exactly what FSM changes are required and why
3. **WARN** about potential risks and breaking changes
4. **REQUEST** explicit permission before proceeding
5. **PROVIDE** testing requirements and validation steps

### 🎯 SUMMARY FOR AI AGENTS

1. **PROTECT THE FSM** - It's the core of the entire system
2. **ASK BEFORE MODIFYING** - Get explicit permission for FSM changes
3. **EXTEND, DON'T REPLACE** - Build around the FSM, not within it
4. **TEST THOROUGHLY** - FSM changes require comprehensive validation
5. **DOCUMENT EVERYTHING** - Keep documentation current and accurate
6. **ENFORCE SINGLE WRITER CONTRACT** - Ensure that only one process or component can modify the FSM state at any given time, preventing overlapping write operations and reducing the risk of conflicting state changes.
7. IMPLEMENT AUTOMATED STATE INTEGRITY CHECKS – Regularly validate the FSM’s current state and transitions through automated health checks and self-tests, ensuring early detection of invalid states, transition errors, or desynchronization between frontend and backend. This helps catch issues before they impact users and maintains overall system stability.

The FSM is production-ready and battle-tested. Treat it with the respect it deserves.

There should be a total of only 1 FSM PHP for the backend and 1 FSM JS for the frontend. There should not be more than 1 FSM per backend and frontend.

Please do not refactor anything beyond the scope of the immediate tasks. Please do not change any labels unless explicitly instructed. Please keep the TOC if one exists, increment the version number for each change, and add to the existing changelog within the code. Please try your best to adhere to DRY principles so we can re-use existing functions or better yet re-use WP public functions or API calls.

## Standardized Data Analysis Pattern

When debugging or analyzing external data sources (APIs, databases, logs, scrapers), use this standardized workflow to ensure reproducible, transparent analysis. This pattern is **mandatory** when agent ↔ human debugging is not working or when dealing with complex data structures.

### The 4-Step Pattern

#### Step 1: Capture the Data
Run the command or API call, piping output to `./data-stream.json` (always overwrite):

```bash
# For WP-CLI/Database queries
local-wp [installation-name] db query "SELECT * FROM wp_posts LIMIT 50" > data-stream.json 2>&1

# For API/scraper endpoints
curl -s "https://api.example.com/scraped-data" > data-stream.json 2>&1

# For log files
tail -n 100 /path/to/plugin.log > data-stream.json 2>&1

# For WordPress REST API
curl -s "https://example.com/wp-json/wp/v2/posts?per_page=10" > data-stream.json 2>&1
```

**Important:** Always redirect stderr to stdout (`2>&1`) to capture error messages.

#### Step 1.5: Validate Data Structure (Optional but Recommended)
Before analysis, validate the data format:

```bash
# For JSON data - validate structure
if command -v jq &> /dev/null; then
    jq empty data-stream.json 2>&1 || echo "⚠️ Invalid JSON detected"
fi

# For CSV data - check delimiter and headers
head -5 data-stream.json

# For plain text logs - check encoding
file data-stream.json
```

#### Step 2: Display the Raw Output
Immediately run `cat data-stream.json` so the full content appears in chat context:

```bash
cat data-stream.json
```

**Why this matters:**
- Prevents AI hallucination (forces analysis of actual data)
- Ensures transparency (user can see what AI is analyzing)
- Eliminates copy-paste errors and truncation issues
- Creates a shared reference point for discussion

#### Step 3: Analyze the File
Perform structured analysis in this order:

1. **Infer the schema:**
   - List all fields/columns and their data types
   - Identify primary keys, foreign keys, relationships
   - Note any nested structures or arrays
   - Provide example values for each field

2. **Check data quality:**
   - Flag missing/null values (count and percentage)
   - Identify outliers or anomalous values
   - Detect duplicates (by key fields)
   - Check for data type mismatches
   - Validate constraints (e.g., email format, date ranges)

3. **Summarize statistics:**
   - Record counts (total rows, unique values)
   - Calculate aggregates (avg/min/max for numeric fields)
   - List unique values for categorical fields
   - Identify distribution patterns

4. **Recommend actions:**
   - Suggest validation rules (e.g., Zod schema, JSON Schema)
   - Propose data transformations or cleanup steps
   - Recommend database constraints or indexes
   - Flag security concerns (exposed credentials, PII)

#### Step 4: Iterate if Needed
If more data is required, repeat with updated parameters, always using `./data-stream.json`:

```bash
# Refine query based on initial analysis
local-wp [installation-name] db query "SELECT * FROM wp_posts WHERE post_status = 'publish' LIMIT 100" > data-stream.json 2>&1
cat data-stream.json
# Analyze again...
```

---

### Usage Examples

#### Example 1: WordPress Database Analysis
```bash
# User request: "Analyze the latest 50 published posts"

# Step 1: Capture
local-wp my-site db query "SELECT ID, post_title, post_date, post_status FROM wp_posts WHERE post_status = 'publish' ORDER BY post_date DESC LIMIT 50" > data-stream.json 2>&1

# Step 1.5: Validate
jq empty data-stream.json 2>&1 || echo "⚠️ Invalid JSON"

# Step 2: Display
cat data-stream.json

# Step 3: Analyze
# - Schema: ID (int), post_title (string), post_date (datetime), post_status (string)
# - Quality: Check for null titles, future dates, invalid statuses
# - Stats: Count by month, average title length, date range
# - Recommend: Add index on post_date, validate post_status enum
```

#### Example 2: API Endpoint Testing
```bash
# User request: "Check the scraper endpoint data structure"

# Step 1: Capture
curl -s "https://neochrome-timesheets.local/wp-json/scraper/v1/latest" > data-stream.json 2>&1

# Step 1.5: Validate
jq empty data-stream.json 2>&1

# Step 2: Display
cat data-stream.json

# Step 3: Analyze
# - Schema: Extract all fields and types from JSON response
# - Quality: Check for missing required fields, validate URLs
# - Stats: Count items, check response size
# - Recommend: Create TypeScript interface or Zod schema
```

#### Example 3: Log File Debugging
```bash
# User request: "Find errors in the plugin logs"

# Step 1: Capture
tail -n 200 ~/Library/Application\ Support/Local/run/*/logs/php-error.log > data-stream.json 2>&1

# Step 2: Display
cat data-stream.json

# Step 3: Analyze
# - Schema: Parse log format (timestamp, level, message, file, line)
# - Quality: Group by error type, count occurrences
# - Stats: Errors per hour, most common error messages
# - Recommend: Fix top 3 errors, add error handling
```

#### Example 4: Webhook Payload Validation
```bash
# User request: "Validate Stripe webhook structure"

# Step 1: Capture (from test webhook or logs)
curl -s "https://dashboard.stripe.com/api/v1/events?limit=5" \
  -H "Authorization: Bearer $STRIPE_KEY" > data-stream.json 2>&1

# Step 1.5: Validate
jq empty data-stream.json 2>&1

# Step 2: Display
cat data-stream.json

# Step 3: Analyze
# - Schema: Map Stripe event structure (id, type, data, created)
# - Quality: Validate required fields, check signature format
# - Stats: Event types distribution, timestamp ranges
# - Recommend: Create webhook handler with proper validation
```

---

### Best Practices

1. **Always use `./data-stream.json`** - Consistent location makes debugging easier
2. **Capture stderr** - Use `2>&1` to catch error messages
3. **Validate before analyzing** - Use `jq empty` for JSON, `head` for text
4. **Show raw data first** - Always `cat` before analyzing
5. **Be systematic** - Follow the 4-step pattern every time
6. **Iterate incrementally** - Refine queries based on findings

### File Management

**Add to `.gitignore`:**
```gitignore
# Data analysis temporary files
data-stream.json
data-stream-*.json
data-stream.txt
data-stream.csv
```

**Optional: Keep timestamped history**
```bash
# Save with timestamp but symlink to standard name
timestamp=$(date +%Y%m%d-%H%M%S)
command > "data-stream-${timestamp}.json" 2>&1
ln -sf "data-stream-${timestamp}.json" data-stream.json

# Clean up old files (keep last 10)
ls -t data-stream-*.json | tail -n +11 | xargs rm -f
```

---

### When to Use This Pattern

✅ **Use this pattern when:**
- Debugging API integrations or webhooks
- Analyzing database query results
- Inspecting log files for errors
- Validating data imports/exports
- Testing scraper or crawler output
- Reviewing WordPress REST API responses
- Analyzing WP_Query results or custom queries
- Troubleshooting performance issues with data

❌ **Don't use this pattern for:**
- Simple one-line commands with obvious output
- File content that's already in the repository
- Data that's already visible in the conversation
- Sensitive data that shouldn't be written to disk

---

### Troubleshooting

**Problem:** `jq: command not found`
```bash
# Install jq (macOS)
brew install jq

# Or skip validation step
cat data-stream.json  # Proceed to analysis
```

**Problem:** File is too large for chat context
```bash
# Show first 100 lines
head -100 data-stream.json

# Or use jq to filter
jq '.results[:10]' data-stream.json  # First 10 items from array
```

**Problem:** Binary or non-text data
```bash
# Check file type first
file data-stream.json

# Convert if needed
iconv -f ISO-8859-1 -t UTF-8 data-stream.json > data-stream-utf8.json
```

---