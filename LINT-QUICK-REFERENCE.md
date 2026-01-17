# PHP Lint - Quick Reference

## Run Locally

```bash
# Direct execution
./scripts/php-lint.sh

# Via composer (if available)
composer lint
```

## GitHub Actions Behavior

### ✅ Runs On
- Pull requests to `main` or `develop`
- Only when `.php` files change
- Only when lint script or workflow changes

### ❌ Does NOT Run On
- Direct commits (without PR)
- Pushes to branches
- Non-PHP file changes

### 🔒 Concurrency Protection
- Only 1 run per PR at a time
- New pushes cancel previous runs
- Group: `php-lint-{PR_NUMBER}`

## What It Checks

- ✅ All PHP files in `src/`
- ✅ All PHP files in `framework/`
- ✅ Main plugin file: `nhk-kiss-batch-installer.php`

## Exit Codes

- `0` - All files passed
- `1` - Syntax errors found

## Quick Test

```bash
# Test a single file
php -l src/Plugin.php

# Test all files (full check)
./scripts/php-lint.sh
```

## Integration

Part of the quality toolchain:

```bash
./scripts/php-lint.sh  # Syntax check (fast)
composer phpcs         # Code style (medium)
composer phpstan       # Static analysis (slow)
composer test          # Unit tests (varies)
```

## Troubleshooting

**Script not executable:**
```bash
chmod +x scripts/php-lint.sh
```

**PHP not found:**
```bash
which php
# Update PHP_BIN in script if needed
```

**GitHub Actions failing:**
- Check workflow file: `.github/workflows/php-lint.yml`
- Verify PHP version matches: `php: ">=8.0"`
- Check file paths in workflow triggers
```

