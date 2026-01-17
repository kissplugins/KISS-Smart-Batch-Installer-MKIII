# PHP Lint Check Setup

**STATUS:** Completed

## Overview

Simple PHP syntax validation for local development and GitHub Actions that only runs on pull requests.

## Files Created

1. **`scripts/php-lint.sh`** - Bash script for PHP syntax checking
2. **`.github/workflows/php-lint.yml`** - GitHub Actions workflow (PR-only)
3. **`composer.json`** - Added `lint` script

## Features

✅ **Single Test Only** - One unified lint check  
✅ **PR-Only Activation** - Does not run on direct commits  
✅ **No Concurrent Runs** - Uses concurrency groups to prevent parallel runs  
✅ **Local & CI** - Same script runs locally and in GitHub Actions  
✅ **Fast** - Only checks PHP syntax, no heavy analysis  

## Usage

### Local Development

```bash
# Run directly
./scripts/php-lint.sh

# Or via composer
composer lint
```

### GitHub Actions

Automatically runs when:
- A pull request is opened
- A pull request is updated
- Only if PHP files or the lint script changed

**Does NOT run on:**
- Direct commits to main/develop
- Pushes without PR
- Non-PHP file changes

## Configuration

### Directories Checked

- `src/` - Plugin source code
- `framework/` - NHK Framework
- `nhk-kiss-batch-installer.php` - Main plugin file

### Concurrency Control

```yaml
concurrency:
  group: php-lint-${{ github.event.pull_request.number }}
  cancel-in-progress: true
```

This ensures:
- Only one lint check runs per PR
- New pushes cancel previous runs
- No resource waste on outdated commits

### Trigger Conditions

```yaml
on:
  pull_request:
    branches:
      - main
      - develop
    paths:
      - '**.php'
      - 'scripts/php-lint.sh'
      - '.github/workflows/php-lint.yml'
```

## Output Example

```
🔍 PHP Lint Check - KISS Smart Batch Installer
================================================

Using PHP: PHP 8.2.27 (cli)

Checking PHP files...

Scanning directory: src/
✓ src/Container.php
✓ src/Plugin.php
✓ src/Enums/PluginState.php
...

================================================
Summary:
  Files checked: 28
  Errors found: 0

✓ All PHP files passed syntax check!
```

## Error Handling

If syntax errors are found:

```
✗ Syntax error in: src/Services/Example.php
Parse error: syntax error, unexpected '}' in src/Services/Example.php on line 42

================================================
Summary:
  Files checked: 28
  Errors found: 1

✗ PHP lint check failed with 1 error(s)
```

Exit code: 1 (fails the build)

## PHP Version

- **Local**: Uses system PHP (detected automatically)
- **GitHub Actions**: PHP 8.0 (matches composer.json requirement)

## Integration with Existing Tools

This lint check complements existing quality tools:

| Tool | Purpose | When to Use |
|------|---------|-------------|
| `composer lint` | Syntax check | Before commit |
| `composer phpcs` | Code style | Before PR |
| `composer phpstan` | Static analysis | Before release |
| `composer test` | Unit tests | During development |

## Benefits

1. **Fast Feedback** - Catches syntax errors before code review
2. **No Overhead** - Only runs when needed (PRs with PHP changes)
3. **Resource Efficient** - Cancels outdated runs automatically
4. **Consistent** - Same script locally and in CI
5. **Simple** - No complex configuration or dependencies

## Verification

Tested locally:
- ✅ 28 PHP files checked
- ✅ 0 errors found
- ✅ Script executable and working
- ✅ Composer integration functional

## Related Files

- `composer.json` - Contains `lint` script
- `.github/workflows/php-lint.yml` - GitHub Actions workflow
- `scripts/php-lint.sh` - Lint implementation

