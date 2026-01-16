# Local Environment Configuration
**STATUS:** Completed

## Overview

This project uses a `.env.local` file to store machine-specific configuration, primarily for WP-CLI access through the Local by Flywheel wrapper script.

## Files Created

1. **`.env.local`** - Active configuration (git-ignored)
2. **`.env.local.example`** - Template for new developers
3. **Updated `.gitignore`** - Excludes local env files and data-stream files

## Purpose

Enables AI agents and developers to access WP-CLI without manual path configuration each session.

## Usage

### For AI Agents

```bash
# Source the environment
source .env.local

# Use WP-CLI
$WPCLI_PATH $LOCAL_SITE_NAME plugin list
$WPCLI_PATH $LOCAL_SITE_NAME core version
$WPCLI_PATH $LOCAL_SITE_NAME db query "SELECT * FROM wp_options LIMIT 5"

# Or use the full command template
eval "$WPCLI_COMMAND plugin list"
```

### For Developers

```bash
# Source in your shell session
source .env.local

# Now you can use the variables
echo $WPCLI_PATH
echo $LOCAL_SITE_NAME

# Run WP-CLI commands
$WPCLI_PATH $LOCAL_SITE_NAME --info
```

## Environment Variables

| Variable | Description | Example |
|----------|-------------|---------|
| `WPCLI_PATH` | Path to local-wp wrapper | `/Users/username/bin/local-wp` |
| `LOCAL_SITE_NAME` | Current Local site name | `neochrome-timesheets` |
| `WPCLI_COMMAND` | Full command template | `/Users/.../local-wp neochrome-timesheets` |
| `WP_ROOT` | WordPress root directory | `/Users/.../app/public` |
| `PLUGIN_DIR` | This plugin's directory | `/Users/.../wp-content/plugins/...` |

## Setup for New Machines

1. Copy the example file:
   ```bash
   cp .env.local.example .env.local
   ```

2. Update paths in `.env.local` to match your machine

3. Verify WP-CLI access:
   ```bash
   source .env.local
   $WPCLI_PATH $LOCAL_SITE_NAME core version
   ```

## Integration with AGENTS.md

The standardized data analysis pattern from AGENTS.md can now use these variables:

```bash
# Capture data
$WPCLI_PATH $LOCAL_SITE_NAME db query "SELECT * FROM wp_posts LIMIT 50" > data-stream.json 2>&1

# Display
cat data-stream.json

# Analyze
# ... (follow the 4-step pattern)
```

## Benefits

- ✅ **No Repetition**: AI agents don't need to ask for paths each session
- ✅ **Consistency**: Same configuration across all tools and agents
- ✅ **Git-Safe**: Machine-specific files are ignored
- ✅ **Self-Documenting**: Inline comments explain usage
- ✅ **Template Provided**: Easy setup for new developers

## Related Documentation

- `PROJECT/1-INBOX/LOCAL-WPCLI-SETUP.md` - Original WP-CLI wrapper setup
- `AGENTS.MD` - AI agent instructions (now includes env setup)
- `.env.local.example` - Template file

## Verification

Current configuration verified working:
- ✅ WP-CLI wrapper accessible at `/Users/noelsaw/bin/local-wp`
- ✅ Site `neochrome-timesheets` running WordPress 6.8.3
- ✅ Plugin version 1.0.83 active
- ✅ Environment variables properly sourced

