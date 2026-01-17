# KISS Smart Batch Installer MKIII

Batch-install and manage WordPress plugins from GitHub repositories. 
Built on the NHK framework and shipped with an
admin UI for installing, activating, and deactivating plugins by repository.

## Overview

KISS Smart Batch Installer MKIII (SBI MKIII) scans a GitHub organization or
user account, detects WordPress plugins, and lets admins install or activate
them from a single admin screen.

## Key Features

- GitHub org/repo scanning with caching
- One-at-a-time install/activate/deactivate actions
- Admin UI under Plugins > SBI MKIII
- Audit log submenu for traceability
- Update checker wired to GitHub releases

## Requirements

- WordPress 6.0+
- PHP 8.0+
- NHK framework available in `framework/` (bundled in this repo)

## Installation

1. Copy this plugin directory into `wp-content/plugins/`.
2. Ensure the `framework/` directory is present (required).
3. Activate "KISS Smart Batch Installer MKIII" in WordPress.

## Usage

1. Open `Plugins > SBI MKIII`.
2. Switch organization if needed.
3. Install, activate, or deactivate plugins one at a time.

## Development

PHP dependencies are managed via Composer. Frontend tooling is minimal and
TypeScript builds are optional.

```bash
composer install
npm install
npm run build:ts
```

## Useful Scripts

- `npm run build:ts` - Build TypeScript sources.
- `npm run watch:ts` - Watch TypeScript sources.
- `npm run check:fsm-enum-parity` - Validate enum parity (legacy FSM tooling).
- `composer test` - Run PHPUnit.
- `composer phpcs` - WordPress coding standards.
- `composer phpstan` - Static analysis.

## Project Structure

- `nhk-kiss-batch-installer.php` - Plugin bootstrap and updater.
- `src/` - Plugin classes (services, admin UI, API handlers).
- `assets/` - Admin JS/CSS.
- `framework/` - NHK framework runtime.
- `dist/` - Built assets.

## Notes

- The MKIII interface favors a single, sequential flow for reliability.
- The project includes additional documentation under `PROJECT/`.

## Changelog

See `CHANGELOG.md`.
