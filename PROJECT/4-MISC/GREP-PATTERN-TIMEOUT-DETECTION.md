# GREP Pattern for Timeout Detection in wp_remote_* Calls

**Purpose:** Reduce false positives when detecting missing timeout arguments in WordPress HTTP API calls.

**Problem:** Simple grep for `wp_remote_get` without `timeout` flags cases where `$args` is defined separately with timeout included.

---

## The Challenge

### Pattern 1: Inline timeout (Easy to detect) ✅
```php
wp_remote_get( $url, [ 'timeout' => 10 ] );
```

### Pattern 2: Separate $args with timeout (Harder to detect) ⚠️
```php
$args = [
    'timeout' => 10,
    'headers' => [ ... ],
];
$response = wp_remote_get( $url, $args );
```

### Pattern 3: Missing timeout (Should flag) ❌
```php
$args = [
    'headers' => [ ... ],
];
$response = wp_remote_get( $url, $args );
```

---

## Proposed Multi-Step Grep Strategy

### Step 1: Find all wp_remote_* calls
```bash
grep -n "wp_remote_\(get\|post\|request\|head\)" file.php
```

### Step 2: Check for inline timeout in same line
```bash
grep "wp_remote_\(get\|post\|request\|head\).*'timeout'" file.php
```

### Step 3: Find $args definitions with timeout
```bash
grep -B 10 "wp_remote_\(get\|post\|request\|head\)" file.php | grep "\$args.*=.*\[" -A 5 | grep "'timeout'"
```

---

## Advanced: Context-Aware Detection

### Approach 1: Function-Level Analysis
**Goal:** Check if timeout exists anywhere in the same function scope

```bash
# Extract function containing wp_remote_get
awk '/function.*{/,/^}/' file.php | grep -A 50 "wp_remote_get" | grep "'timeout'"
```

### Approach 2: Backward Context Search (10 lines)
**Goal:** Look 10 lines before wp_remote_get for $args with timeout

```bash
grep -B 10 "wp_remote_get.*\$args" file.php | grep -E "(\$args\s*=|\s+'timeout')"
```

**Example output:**
```
74:        $args = [
75:            'timeout' => 10,
--
82:        $response = wp_remote_get( $org_url, $args );
```

### Approach 3: Variable Tracking Pattern
**Goal:** Track $args variable from definition to usage

```bash
# Find wp_remote_get calls using $args variable
grep -n "wp_remote_get.*\$args" file.php > /tmp/calls.txt

# For each call, check preceding lines for $args definition with timeout
while read line; do
    linenum=$(echo "$line" | cut -d: -f1)
    start=$((linenum - 20))
    sed -n "${start},${linenum}p" file.php | grep -E "(\$args\s*=.*\[|'timeout')"
done < /tmp/calls.txt
```

---

## Recommended Grep Pattern for WP Code Check

### Single-Command Solution (Best Effort)
```bash
# Find wp_remote_* calls WITHOUT timeout in context (20 lines before)
grep -B 20 "wp_remote_\(get\|post\|request\|head\)" file.php | \
  grep -v "'timeout'" | \
  grep "wp_remote_\(get\|post\|request\|head\)"
```

**Limitation:** This will still flag false positives if timeout is >20 lines away.

---

## Better Solution: Two-Pass Grep

### Pass 1: Find all wp_remote_* calls
```bash
grep -n "wp_remote_\(get\|post\|request\)" src/**/*.php | \
  grep -v "wp_remote_retrieve" > /tmp/remote_calls.txt
```

### Pass 2: For each call, check context
```bash
#!/bin/bash
while IFS=: read -r file line rest; do
    # Extract 15 lines before the call
    start=$((line - 15))
    [ $start -lt 1 ] && start=1
    
    # Check if timeout appears in context
    context=$(sed -n "${start},${line}p" "$file")
    
    if ! echo "$context" | grep -q "'timeout'"; then
        echo "⚠️  Missing timeout: $file:$line"
        echo "$rest"
        echo ""
    fi
done < /tmp/remote_calls.txt
```

---

## Regex Pattern for Advanced Tools (ripgrep, ast-grep)

### Using ripgrep with multiline support
```bash
rg -U "wp_remote_(get|post|request)\([^)]+\)" --no-line-number | \
  rg -v "timeout"
```

### Using ast-grep (AST-based, most accurate)
```yaml
# ast-grep pattern (requires ast-grep tool)
rule:
  pattern: wp_remote_get($URL, $ARGS)
  not:
    any:
      - pattern: wp_remote_get($URL, [..., 'timeout' => $_, ...])
      - pattern: |
          $args = [..., 'timeout' => $_, ...];
          ...
          wp_remote_get($URL, $args)
```

---

## Practical Grep Command for CI/CD

### Recommended for GitHub Actions / CI
```bash
#!/bin/bash
# check-wp-remote-timeout.sh

echo "Checking for wp_remote_* calls without timeout..."

# Find all PHP files
find src -name "*.php" -type f | while read file; do
    # Get line numbers of wp_remote_* calls
    grep -n "wp_remote_\(get\|post\|request\|head\)" "$file" | \
    grep -v "wp_remote_retrieve" | \
    while IFS=: read linenum content; do
        # Check 20 lines before for timeout
        start=$((linenum - 20))
        [ $start -lt 1 ] && start=1
        
        if ! sed -n "${start},${linenum}p" "$file" | grep -q "'timeout'"; then
            echo "⚠️  $file:$linenum - Possible missing timeout"
        fi
    done
done
```

**Usage:**
```bash
chmod +x check-wp-remote-timeout.sh
./check-wp-remote-timeout.sh
```

---

## Edge Cases to Consider

### Case 1: Timeout in class constant
```php
private const DEFAULT_ARGS = [ 'timeout' => 10 ];
$response = wp_remote_get( $url, self::DEFAULT_ARGS );
```
**Detection:** Would need to track constant definitions (complex)

### Case 2: Timeout in filter/apply_filters
```php
$args = apply_filters( 'my_http_args', [] );
$response = wp_remote_get( $url, $args );
```
**Detection:** Cannot determine statically (requires runtime analysis)

### Case 3: Timeout in merged arrays
```php
$args = array_merge( $defaults, [ 'headers' => [...] ] );
$response = wp_remote_get( $url, $args );
```
**Detection:** Would need to track $defaults definition

### Case 4: Timeout in method call
```php
$args = $this->build_request_args();
$response = wp_remote_get( $url, $args );
```
**Detection:** Would need to analyze method implementation

---

## Recommendation for WP Code Check

### Tier 1: High Confidence (Flag these)
- ✅ Inline call without timeout: `wp_remote_get( $url )`
- ✅ Inline array without timeout: `wp_remote_get( $url, [ 'headers' => [...] ] )`

### Tier 2: Medium Confidence (Check context)
- ⚠️ Variable usage: `wp_remote_get( $url, $args )` - Check 20 lines before

### Tier 3: Low Confidence (Manual review)
- ⚠️ Constant/method usage: `wp_remote_get( $url, self::ARGS )` - Requires deeper analysis

### Suggested Grep Pattern (Balanced)
```bash
# Find wp_remote_* calls and check 20-line context
grep -B 20 -n "wp_remote_\(get\|post\|request\|head\)" src/**/*.php | \
  awk '/wp_remote_/ {
    if (context !~ /timeout/) {
      print "⚠️  Line " NR ": " $0
    }
    context = ""
  }
  { context = context "\n" $0 }'
```

---

## Conclusion

**Best Approach for Reducing False Positives:**

1. **Use 20-line backward context** (covers 99% of cases)
2. **Exclude lines with `'timeout'` in context**
3. **Flag for manual review** rather than hard fail
4. **Document known patterns** (constants, methods) as exceptions

**For WP Code Check Integration:**
- Use the two-pass bash script above
- Set context window to 20 lines
- Output warnings, not errors
- Provide file:line references for manual review

This will catch genuine missing timeouts while reducing false positives from separated $args definitions.

