========================================================================
 AI / LLM AGENT INSTRUCTIONS - SMART BATCH INSTALLER FSM
========================================================================

## 🔧 LOCAL ENVIRONMENT SETUP

**IMPORTANT**: This project has a local environment configuration file that provides
WP-CLI access and other machine-specific paths.

### Quick Start for AI Agents

1. **Check for `.env.local` file** in the project root
2. **Source the file** to access environment variables:
   ```bash
   source .env.local
   ```
3. **Use WP-CLI** via the configured variables:
   ```bash
   $WPCLI_PATH $LOCAL_SITE_NAME plugin list
   $WPCLI_PATH $LOCAL_SITE_NAME core version
   $WPCLI_PATH $LOCAL_SITE_NAME db query "SELECT ..."
   ```

### Environment Variables Available

- `WPCLI_PATH` - Path to the Local WP-CLI wrapper script
- `LOCAL_SITE_NAME` - Current Local site name
- `WPCLI_COMMAND` - Full command template for quick use
- `WP_ROOT` - WordPress installation root
- `PLUGIN_DIR` - This plugin's directory

### Benefits

- ✅ No need to ask user for WP-CLI path
- ✅ Consistent across all AI agent sessions
- ✅ Machine-specific (git-ignored)
- ✅ Self-documenting with inline instructions

---

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