# Local WP-CLI Setup - Bloomz Prod 08 15

## ✅ Installation Complete

WP-CLI wrapper script has been successfully installed and configured for direct access to Local sites.

---

## 📍 Files Created/Modified

### **1. Script Created**
- **Location:** `~/bin/local-wp`
- **Size:** 3.1 KB
- **Permissions:** Executable (`755`)
- **Purpose:** Wrapper script that auto-detects MySQL socket and runs WP-CLI

### **2. Shell Configuration Updated**
- **File:** `~/.zshrc`
- **Change:** Added `export PATH="$HOME/bin:$PATH"`
- **Effect:** Makes `local-wp` command available globally

---

## 🚀 Quick Start

### **Basic Usage**
```bash
local-wp <site-name> <wp-cli-command> [args...]
```

### **Common Commands**

```bash
# Get WordPress version
local-wp bloomz-prod-08-15 core version

# Get site URL
local-wp bloomz-prod-08-15 option get siteurl

# List active plugins
local-wp bloomz-prod-08-15 plugin list --status=active --format=table

# List all plugins
local-wp bloomz-prod-08-15 plugin list

# Run database query
local-wp bloomz-prod-08-15 db query "SELECT * FROM wp_options LIMIT 5"

# List all available sites
local-wp --list

# Show help
local-wp --help
```

---

## 🔧 How It Works

1. **Site Validation** - Verifies site exists in `~/Local Sites/`
2. **PHP Detection** - Finds latest PHP version from Local installation
3. **MySQL Socket** - Auto-detects MySQL socket path from Local's run directory
4. **Temp Config** - Creates temporary PHP ini with socket configuration
5. **WP-CLI Execution** - Runs WP-CLI with custom PHP config
6. **Cleanup** - Removes temporary files after execution

---

## ✅ Verified Working

- ✓ `local-wp bloomz-prod-08-15 core version` → Returns `6.8.2`
- ✓ `local-wp bloomz-prod-08-15 option get siteurl` → Returns site URL
- ✓ `local-wp bloomz-prod-08-15 plugin list` → Lists all plugins
- ✓ `local-wp --list` → Lists all available Local sites

---

## 🎯 AI Agent Access

The AI agent can now execute WP-CLI commands directly:

```bash
local-wp bloomz-prod-08-15 <command>
```

This enables automated WordPress management, plugin operations, and database queries.

