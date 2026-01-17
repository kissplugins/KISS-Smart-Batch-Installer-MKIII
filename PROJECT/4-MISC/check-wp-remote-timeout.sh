#!/bin/bash
# check-wp-remote-timeout.sh
# 
# Detects wp_remote_* calls without timeout arguments
# Reduces false positives by checking context (20 lines before call)
#
# Usage: ./check-wp-remote-timeout.sh [directory]
# Example: ./check-wp-remote-timeout.sh src/

set -e

# Configuration
CONTEXT_LINES=20
TARGET_DIR="${1:-src}"

# Colors
RED='\033[0;31m'
YELLOW='\033[1;33m'
GREEN='\033[0;32m'
NC='\033[0m' # No Color

echo "🔍 Checking for wp_remote_* calls without timeout..."
echo "Target: $TARGET_DIR"
echo "Context: $CONTEXT_LINES lines before each call"
echo ""

# Counters
total_calls=0
flagged_calls=0

# Find all PHP files
while IFS= read -r file; do
    # Get line numbers of wp_remote_* calls
    while IFS=: read -r linenum content; do
        [ -z "$linenum" ] && continue
        
        total_calls=$((total_calls + 1))
        
        # Calculate context window
        start=$((linenum - CONTEXT_LINES))
        [ $start -lt 1 ] && start=1
        
        # Extract context (lines before the call)
        context=$(sed -n "${start},${linenum}p" "$file")
        
        # Check if 'timeout' appears in context
        if ! echo "$context" | grep -q "'timeout'"; then
            flagged_calls=$((flagged_calls + 1))
            echo -e "${YELLOW}⚠️  Possible missing timeout:${NC}"
            echo -e "   File: ${file}:${linenum}"
            echo -e "   Code: ${content}"

            # Show reason for flagging
            if echo "$context" | grep -q "\$args.*="; then
                echo -e "   ${RED}Reason: \$args defined but no 'timeout' key found in context${NC}"
            elif echo "$context" | grep -q "self::"; then
                echo -e "   ${GREEN}Likely OK: Uses constant/method (manual review needed)${NC}"
            elif echo "$content" | grep -q "\$args"; then
                echo -e "   ${RED}Reason: Uses \$args variable but no timeout in ${CONTEXT_LINES}-line context${NC}"
            else
                echo -e "   ${RED}Reason: No timeout argument provided${NC}"
            fi
            echo ""
        fi
    done < <(grep -n "wp_remote_\(get\|post\|request\|head\)" "$file" 2>/dev/null | grep -v "wp_remote_retrieve" || true)
done < <(find "$TARGET_DIR" -name "*.php" -type f 2>/dev/null)

# Summary
echo "================================================"
echo "Summary:"
echo "  Total wp_remote_* calls found: $total_calls"
echo "  Flagged (possible missing timeout): $flagged_calls"
echo ""

if [ $flagged_calls -eq 0 ]; then
    echo -e "${GREEN}✓ All calls appear to have timeout arguments!${NC}"
    exit 0
else
    echo -e "${YELLOW}⚠️  $flagged_calls call(s) flagged for manual review${NC}"
    echo ""
    echo "Note: These may be false positives if:"
    echo "  - Timeout is defined in a constant/method"
    echo "  - Timeout is set via apply_filters()"
    echo "  - \$args is defined more than $CONTEXT_LINES lines before"
    exit 1
fi

