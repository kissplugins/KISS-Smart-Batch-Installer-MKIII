#!/bin/bash
# PHP Lint Check - Validates PHP syntax across the codebase
# Usage: ./scripts/php-lint.sh

set -e

echo "🔍 PHP Lint Check - KISS Smart Batch Installer"
echo "================================================"
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Counter for errors
ERROR_COUNT=0
FILE_COUNT=0

# Directories to check
DIRS_TO_CHECK=(
    "src"
    "framework"
)

# Find PHP executable
if command -v php &> /dev/null; then
    PHP_BIN="php"
elif [ -f "/usr/local/bin/php" ]; then
    PHP_BIN="/usr/local/bin/php"
else
    echo -e "${RED}✗ PHP executable not found${NC}"
    exit 1
fi

echo "Using PHP: $($PHP_BIN --version | head -n 1)"
echo ""

# Function to lint a single file
lint_file() {
    local file="$1"
    FILE_COUNT=$((FILE_COUNT + 1))
    
    if ! $PHP_BIN -l "$file" > /dev/null 2>&1; then
        echo -e "${RED}✗ Syntax error in: $file${NC}"
        $PHP_BIN -l "$file" 2>&1 | grep -v "^No syntax errors"
        ERROR_COUNT=$((ERROR_COUNT + 1))
        return 1
    else
        echo -e "${GREEN}✓${NC} $file"
        return 0
    fi
}

# Main lint loop
echo "Checking PHP files..."
echo ""

for dir in "${DIRS_TO_CHECK[@]}"; do
    if [ -d "$dir" ]; then
        echo "Scanning directory: $dir/"
        while IFS= read -r -d '' file; do
            lint_file "$file"
        done < <(find "$dir" -type f -name "*.php" -print0)
        echo ""
    else
        echo -e "${YELLOW}⚠ Directory not found: $dir${NC}"
    fi
done

# Check main plugin file
if [ -f "nhk-kiss-batch-installer.php" ]; then
    echo "Checking main plugin file..."
    lint_file "nhk-kiss-batch-installer.php"
    echo ""
fi

# Summary
echo "================================================"
echo "Summary:"
echo "  Files checked: $FILE_COUNT"
echo "  Errors found: $ERROR_COUNT"
echo ""

if [ $ERROR_COUNT -eq 0 ]; then
    echo -e "${GREEN}✓ All PHP files passed syntax check!${NC}"
    exit 0
else
    echo -e "${RED}✗ PHP lint check failed with $ERROR_COUNT error(s)${NC}"
    exit 1
fi

