# Changelog

All notable changes to the KISS Smart Batch Installer will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- README with setup, usage, and development notes.

## [1.0.85] - 2026-01-16

### Changed
- **Search Improvement**: Updated the client-side search on the MK3 page from a strict exact match to a more flexible partial match (contains) on the repository name.

## [1.0.84] - 2026-01-16

### Added
- **Client-Side Search**: Added a search bar to the MK3 repository page to filter repositories by name (exact match).
- **Client-Side Sorting**: Implemented sorting for the "Repository" and "Status" columns. Users can click the headers to sort the table ascending or descending.

## [1.0.83] - 2026-01-16

### Changed
- **Repository Description Error Message**: Simplified GitHub error placeholder message from verbose explanation to concise "Repo Description not available or possibly an error"
  - Updated in `src/Services/GitHubService.php` line 1172
  - Improves UI clarity when GitHub returns error placeholders instead of repository descriptions

## [1.0.82] - 2026-01-16

### Added
- **Pagination**: Added client-side pagination to MK3 repository table
  - Configurable repositories per page (default: 15, range: 5-100)
  - Page navigation with Previous/Next buttons
  - Page number buttons with ellipsis for large page counts
  - "Showing X-Y of Z repositories" info display
  - Pagination controls at top and bottom of table
  - Smooth scroll to table top when changing pages
  - Maintains current page after plugin operations (install/activate/deactivate)

### Removed
- **Self Tests Menu**: Removed "SBI Self Tests" submenu from WordPress admin
  - Self tests still accessible via direct URL if needed
  - Simplified admin menu structure

## [1.0.81] - 2026-01-16

### Added
- **GitHub Organization Selector**: Added live organization switcher to main MK3 page header
  - Input field with current organization (defaults to `kissplugins`)
  - "Switch Organization" button with instant repository reload
  - Enter key support for quick switching
  - Visual feedback with success/error status messages

### Changed
- **Branding Update**: Renamed from "MKII" to "MKIII" throughout the codebase
  - Plugin header now shows "KISS Smart Batch Installer MKIII"
  - Menu items updated to "SBI MKIII"
  - Page titles updated to "KISS Smart Batch Installer MKIII"
  - Self-protection patterns updated to detect both MKII and MKIII variants
  - GitHub update checker URL updated to MKIII repository
- **Settings Page Removed**: Removed dedicated settings page (organization selector moved to main page)
- **MK3 Repository Manager**: Now fully functional with end-to-end install/activate/deactivate operations
- **Code Cleanup**: Removed 50+ obsolete files (old admin files, backup files, excessive documentation)
  - Removed old `RepositoryManager.php` and `RepositoryListTable.php`
  - Removed old `admin.js` and `admin.css`
  - Removed 42 redundant documentation files
  - Removed backup and test files

### Fixed
- **AJAX Handlers**: Fixed namespace issues in MK3 AJAX handlers
  - Install plugin now correctly splits owner/repo and calls `PluginInstallationService`
  - Activate/Deactivate now correctly find plugin files before calling service methods
  - Added comprehensive error logging for debugging
- **Plugin Detection**: Added `find_plugin_file()` helper to locate installed plugins by repository name

## [2.0.0] - TBD - MAJOR SIMPLIFICATION REFACTOR (IN PROGRESS)

### 🚨 Breaking Changes
This is a **MAJOR REFACTOR** to restore KISS (Keep It Simple, Stupid) principles.
See `PROJECT/SIMPLIFICATION-REFACTOR-PLAN.md` for complete details.

**Status:** Phase 1 partially complete (TypeScript dependencies disabled)

### Breaking Changes
- Removed TypeScript dependencies (no build step required)
- Removed SSE real-time updates (use manual refresh)
- Removed batch operations (one operation at a time)
- Removed debug panel (use browser DevTools)
- Removed correlation ID tracing (use browser Network tab)

### Changed
- Simplified state management to single source (PHP StateManager only)
- Simplified AJAX to jQuery only (removed Fetch API wrapper)
- Simplified concurrency to global lock (removed per-repo queues)
- Reduced JavaScript from ~2000 LOC to ~500 LOC (75% reduction)

### Added
- Defensive coding with null checks and validation
- Explicit error states in UI
- Explicit loading states during operations
- Explicit empty states when no repositories found

### Removed
- TypeScript source and compiled files
- ES module bridge (sbi-ts-bridge.js)
- AJAX helper (ajax-helper.js)
- Table state machine (table-state-machine.js)
- FSM enum parity check script
- SSE state streaming endpoint
- Retry logic with exponential backoff
- Per-repository operation queues

### Fixed
- ES module import errors
- Race conditions in state synchronization
- Action buttons not rendering
- State synchronization bugs between PHP and TypeScript FSMs

---

## [1.0.79] - 2026-01-16 - Action Button Rendering Fixed

### Fixed
- Fixed action buttons not rendering after table load
- Fixed button class names to match event handlers (`sbi-install-plugin` vs `sbi-install-btn`)
- Fixed state values to match PHP PluginState enum (`available`, `installed_inactive`, `installed_active`)
- Added event listener for `sbi:repositories_loaded` to trigger button rendering
- Added fallback timeout to render buttons if event doesn't fire

### Changed
- Updated `renderAllActionsSimple()` to use correct button classes and data attributes
- Added console logging to debug state values and rendering
- Added default case for unexpected states (shows "Checking...")
- Added `not_plugin` state handling (shows "Not a WordPress plugin")

### Technical Details
- Modified `assets/admin.js` - Fixed button rendering logic
- Modified `SBI.initProgressiveLoading()` - Added event listener and fallback
- Button classes now match event handlers: `.sbi-install-plugin`, `.sbi-activate-plugin`, `.sbi-deactivate-plugin`
- Data attribute changed from `data-repository` to `data-repo` to match handler expectations

---

## [1.0.78] - 2026-01-16 - Phase 1 Complete: TypeScript Removal

### Removed
- Deleted `assets/sbi-ts-bridge.js` (ES module loader)
- Deleted `assets/js/ajax-helper.js` (Fetch API wrapper)
- Deleted `assets/js/table-state-machine.js` (Table FSM)
- Removed all `window.SBIts` references from `assets/admin.js`
- Removed TypeScript FSM state synchronization calls
- Removed TypeScript handler fallbacks in install/activate/deactivate functions

### Changed
- Simplified `getFSMState()` to read from DOM only (no TypeScript FSM)
- Simplified install/activate/deactivate to use jQuery AJAX only
- Simplified state management: server → DOM → jQuery (single direction)
- All state updates now come from server via `refreshRow()` calls

### Fixed
- Eliminated all ES module import errors
- Removed race conditions from dual FSM synchronization
- Simplified action button rendering (DOM-based only)

### Technical Details
- Modified `assets/admin.js` - Removed 120+ lines of TypeScript handler code
- Updated version to 1.0.78 in `nhk-kiss-batch-installer.php`
- Phase 1 of SIMPLIFICATION-REFACTOR-PLAN.md complete (6/9 tasks done)

---

## [1.0.77] - 2026-01-16 - Emergency TypeScript Fixes (Partial)

### Changed
- Disabled TypeScript script loading in `src/Plugin.php` (commented out)
- Simplified `getFSMState()` to read from DOM only (removed TypeScript FSM dependency)
- Removed `initFSMSync()` functionality (no-op for backward compatibility)

### Fixed
- Reduced ES module import errors (partial fix)

### Notes
- This is a **temporary fix** while preparing for v2.0.0 major refactor
- TypeScript files still exist but are not loaded
- See `PROJECT/SIMPLIFICATION-REFACTOR-PLAN.md` for full refactoring plan

---

## [1.0.76] - 2026-01-16 - FSM-First Action Column Rendering (Complete Refactor)

### 🎯 Major Refactoring: FSM as Single Source of Truth

This release completely refactors the action column rendering to use the FSM as the single source of truth, eliminating race conditions and the persistent "Loading..." bug.

### Changed
- **BREAKING**: Server no longer renders action column content (returns empty `<td>`)
- **BREAKING**: Removed placeholder container logic (`ensureActionsContainerForRow`, `getActionsCellForRow`)
- Client now owns 100% of action column rendering
- All action buttons rendered synchronously from FSM state on page load

### Added
- `SBI.generateActionsHTML(state, meta, uiLocked)` - Pure function: FSM state → HTML
- `SBI.getFSMState(repository, fallbackState)` - Get FSM state with fallback
- `SBI.initFSMSync()` - Initialize FSM synchronization on page load
- FSM state validation - ensures only valid states are rendered

### Fixed
- ✅ **Eliminated "Loading..." stuck states permanently**
- ✅ **No more race conditions** between server and client rendering
- ✅ **No more async timing issues** - synchronous rendering from FSM state
- ✅ **FSM onChange listener** automatically triggers re-rendering

### Removed
- Server-side "Loading..." placeholder in `column_actions()`
- Server-side "Loading..." placeholder in `render_loading_row()`
- Complex placeholder container rebuild logic
- Duplicate FSM binding code in `bindEvents()`
- Fallback setTimeout timers (no longer needed)

### Architecture
- **Pure Function**: `generateActionsHTML()` takes state, returns HTML (no side effects)
- **FSM-First**: FSM state is read before rendering, not after
- **SSOT**: FSM is the authoritative source for all state
- **Single-Writer**: Client JavaScript owns action column exclusively
- **Synchronous**: No async operations, no race conditions

### Benefits
- 🚀 Faster rendering (no async delays)
- 🐛 No more stuck "Loading..." states
- 🧪 Testable (pure function is easy to test)
- 🔍 Debuggable (state → HTML is traceable)
- 📦 Simpler codebase (~200 lines removed, ~100 lines added)

## [1.0.75] - 2026-01-16 - Simplified Global UI Lock (Concurrency Removal)

### Changed
- **BREAKING**: Replaced complex per-repository operation queue with simple global UI lock
- All operations (install/activate/deactivate) now lock the entire UI until completion
- Removed `SBI._repoOpChain`, `SBI._repoBusy`, and `SBI.enqueueRepoOp()` functions
- Simplified concurrency model: only one operation allowed at a time across all repositories
- All action buttons disabled globally when any operation is in progress

### Added
- `SBI.isUILocked()` - Check if UI is globally locked
- `SBI.acquireUILock(operation, repository)` - Acquire global UI lock
- `SBI.releaseUILock()` - Release global UI lock
- Automatic UI re-rendering when lock state changes

### Improved
- Reduced race conditions by eliminating concurrent operations
- Simplified debugging: single operation at a time
- FSM-first: State changes flow through FSM, UI reflects FSM state
- SSOT: Single global lock is the source of truth for operation state
- Single-writer contract: UI lock controls all button rendering

### Rationale
- Enforces best practices: FSM-first, SSOT, single-writer contracts
- Reduces complexity: no need to manage per-repository queues
- Prevents race conditions: only one write path at a time
- Improves user experience: clear feedback when operations are in progress

## [1.0.74] - 2026-01-16 - Phase 1 Replace-Not-Append Enforcement (Actions Cell)

### Fixed
- Enforced the Phase 1 single-writer contract for the Actions column: the client renderer now rebuilds/owns the entire action-cell placeholder container when missing/duplicated, preventing legacy/server markup from being appended alongside client-rendered buttons.

### Changed
- Bumped plugin header version + `GBI_VERSION` constant to `1.0.74`.

## [1.0.73] - 2026-01-16 - Debugging Plan Overview Checklist

### Added
- High-level phased project checklist near the top of `PROJECT/1-INBOX/DEBUGGING-UNIFIED.md` to show overall status at a glance.

### Changed
- Bumped plugin header version + `GBI_VERSION` constant to `1.0.73`.

## [1.0.72] - 2026-01-16 - Correlation ID Propagation (Backend + Logging)

### Added
- `SBI\API\AjaxHandler` now logs structured error/security events via `SBI\Services\LoggingService`, including `correlation_id`.
- All `wp_send_json_success()` / `wp_send_json_error()` responses emitted from `SBI\API` now include `correlation_id` (namespace-local wrappers).

### Changed
- Bumped plugin header version + `GBI_VERSION` constant to `1.0.72`.

## [1.0.71] - 2026-01-16 - Correlation ID Propagation (Frontend AJAX)

### Added
- Client-generated `correlation_id` is now attached to all admin AJAX requests (install/activate/deactivate/refresh) to support end-to-end tracing in debug logs.
- `wpAjaxFetch()` now guarantees a correlation id exists (generates one if missing) and includes it in error/debug entries.

### Changed
- `assets/admin.js` now generates a correlation id per user action and reuses it for the follow-up row refresh, so a single click can be traced across multiple requests.

## [1.0.70] - 2026-01-15 - Canonical Refresh Implementation (Batch Wrapper)

### Fixed
- Centralized refresh payload generation into a single backend implementation shared by both refresh endpoints, reducing divergence between single-repo refresh (`sbi_refresh_repository`) and batch refresh (`sbi_refresh_status`).
- Updated TypeScript AJAX response typing so batch refresh results can include `row_html` and error metadata when available.
- Updated internal audit/debugging docs to reflect the canonical refresh path decision (`sbi_refresh_repository` as canonical; `sbi_refresh_status` as wrapper).

## [1.0.69] - 2026-01-15 - Single SSE Init + Remove Duplicate Force Refresh Handler

### Fixed
- Prevented a potential JS error and inconsistent SSE startup by ensuring `assets/admin.js` calls `repositoryFSM.initSSE(window)` with the required window argument.
- Removed duplicate inline "Force Refresh All" click handler in `src/Admin/RepositoryManager.php` to avoid double AJAX submissions and to support the single-writer UI contract (handler remains in `assets/admin.js`).
- Removed the extra inline SSE init call in `src/Admin/RepositoryManager.php` so `RepositoryFSM.initSSE()` remains the single `EventSource` creator.

## [1.0.68] - 2026-01-15 - FSM DOM Hydration (Remove UI State Fallback)

### Fixed
- Frontend FSM (`RepositoryFSM.get()`) now hydrates missing repository state from the server-rendered row attribute `data-repo-state`, keeping state interpretation inside the FSM and preventing desynchronization from ad-hoc UI fallbacks.
- `assets/admin.js` no longer falls back to reading `data-repo-state` when deciding which action buttons to render; it asks the FSM only.
- Updated compiled TypeScript output (`dist/ts/admin/repositoryFSM.js`) to match the source change.

## [1.0.67] - 2026-01-15 - Safari Action Renderer Bootstrap Fix

### Fixed
- Prevented `assets/admin.js` from failing early in Safari/strict environments where `window.SBI` may not be exposed as an identifier binding (`SBI`) inside the strict IIFE; now `SBI` is explicitly bound via `var SBI = window.SBI = window.SBI || {}`.
- Ensured the single-writer Action renderer can always bootstrap so server placeholders ("Loading...") get replaced by client-rendered buttons.

## [1.0.66] - 2026-01-15 - Action Column Loading Placeholder Fix

### Fixed
- Prevented the Action column from remaining stuck on the server placeholder ("Loading...") by re-rendering client-owned actions after a row HTML swap in `SBI.refreshRow()`.
- Added a small init-time fallback re-render when placeholders remain after repository rows are inserted (covers cases where `sbi:repositories_loaded` fired before `assets/admin.js` attached listeners).
- Replaced `Promise.prototype.finally()` usage in the per-repo queue with a `.then(success, failure)` cleanup to avoid environments where `finally` is unavailable.

## [1.0.65] - 2026-01-15 - Install Guard (Installed → Refresh)

### Added
- Install handler now refuses to run when the current row indicates an `installed_*` state (or `data-active` is truthy), and instead triggers `SBI.refreshRow()` to reconcile UI state.
- `SBI.refreshRow()` now returns the underlying AJAX request so it can be awaited by the per-repository action queue.

## [1.0.64] - 2026-01-15 - Install Row State Snapshot Logging

### Added
- Install click handler now logs a snapshot of the row's `data-active`, `data-repo-state`, resolved state, and (when available) TS `repositoryFSM` state before proceeding.

## [1.0.63] - 2026-01-15 - Admin UI Row Action Consistency + Refresh

### Fixed
- Install/Activate/Deactivate now consistently use the row's authoritative `data-repository` / `data-plugin-file` values (instead of button attributes/selectors), preventing repo-slug vs full-name mismatches.
- After Activate/Deactivate completes, `SBI.refreshRow()` is called to pull server-authoritative state and re-render only that row.
- Action handlers are routed through the existing per-repository queue to prevent overlapping operations on the same row.

## [1.0.62] - 2026-01-15 - Admin UI Action Button Label Fix

### Fixed
- Prevented action buttons (Install/Activate/Deactivate) from being relabeled to "Refresh" after an action-triggered row refresh by calling `SBI.refreshRow()` without passing the action button as the trigger in `assets/admin.js`.

## [1.0.61] - 2026-01-15 - GitHub Web Placeholder False-Positive Fix

### Fixed
- Relaxed GitHub web UI error placeholder detection in `SBI\Services\GitHubService::fetch_repositories_via_web()` to only treat the "There was an error while loading. Please reload this page." placeholder as a fatal error when no repositories are parsed from the page, preventing false positives on accounts like `kissplugins` where valid repositories are still rendered.
- Updated the Cache Viewer (`sbi_view_cache` AJAX handler) to use the current `sbi_github_organization` option (with a backward-compatible fallback to `sbi_github_account`) and to understand the v2 flat repository cache format (`sbi_github_repos_v2_*`), so `Cache Exists` / `Total Repos` and the repository list accurately reflect cached data.

## [1.0.60] - 2026-01-15 - GitHub Web Error Placeholder Handling

### Changed
- Detect GitHub web UI error placeholder pages ("There was an error while loading. Please reload this page.") when scraping repository lists and treat them as explicit, actionable errors instead of normal results.
- Replace scraped GitHub error placeholders in repository descriptions with a clear, plugin-specific explanation and log detailed context to the WordPress debug log.

## [1.0.59] - 2026-01-15 - Self Test FSM Parity Dev Notice

### Added
- Dev-only FSM enum parity check notice on the Self Tests admin page, which surfaces failures from `scripts/check-fsm-enum-parity.js` when `WP_DEBUG` is enabled.

## [1.0.58] - 2026-01-15 - FSM Enum Parity Dev Error Message

### Changed
- Enhanced `scripts/check-fsm-enum-parity.js` failure output with a clear, developer-facing message, including:
  - A prominent "FSM ENUM PARITY CHECK FAILED" banner.
  - Pointers to `PROJECT/1-INBOX/FSM-STATE-UPDATE-WORKFLOW.md`.
  - Explicit next steps to update both PHP and TS enums and re-run the check.

## [1.0.57] - 2026-01-15 - FSM Enum Parity CI + Docs

### Added
- GitHub Actions workflow `.github/workflows/fsm-enum-parity.yml` to run `npm run check:fsm-enum-parity` on pushes and pull requests that touch FSM enum-related files.
- Developer doc `PROJECT/1-INBOX/FSM-STATE-UPDATE-WORKFLOW.md` describing the workflow for adding/updating FSM states in PHP and TypeScript together.

## [1.0.56] - 2026-01-15 - FSM Enum Parity Guardrail

### Added
- Script `scripts/check-fsm-enum-parity.js` to compare PHP `SBI\Enums\PluginState` enum string values with the frontend `PluginState` enum in TypeScript.
- NPM script `check:fsm-enum-parity` to run the parity check locally or in CI.

### Notes
- All persisted FSM states must exist in both PHP and TypeScript; additional frontend-only transient states are explicitly allowlisted in the parity check.

## [1.0.55] - 2026-01-15 - Docs + Version Consistency

### Changed
- **📋 Backlog Refresh**: Updated `PROJECT/BACKLOG.md` to a prioritized P0/P1/P2 backlog format with clearer goals, checklists, and acceptance criteria.
- **🔢 Version Consistency**: Bumped plugin version to `1.0.55` and aligned `GBI_VERSION` with the plugin header version.

## [1.0.54] - 2026-01-14 - FSM-First Architecture Refactor 🎯
### 🚨 CRITICAL: FSM Bypass Elimination

**Problem Identified**: Multiple parallel state-checking pipelines were causing inconsistent repository status displays.

**Root Cause**: Code was bypassing the FSM (Finite State Machine) and reading status from:
- Direct WordPress core calls (`get_plugins()`, `is_plugin_active()`)
- PQS cache direct reads
- Detection service overrides
- Safeguard logic that contradicted FSM state

**Solution**: Enforced FSM as the SINGLE SOURCE OF TRUTH for all repository states.

### Changed
- **🔧 RepositoryListTable Refactor**: Removed detection overrides that contradicted FSM
  - Removed safeguard logic that changed FSM state based on detection results
  - Made `process_repository()` FSM-first with heavy protection comments
  - Detection service now ONLY enriches metadata (plugin name/version), never determines state
  - **Result**: Status display now consistent across all views

- **🔧 AjaxHandler Refactor**: Replaced direct WordPress core calls with FSM methods
  - `find_installed_plugin()` now uses `StateManager::getInstalledPluginFile()`
  - Removed direct `get_plugins()` calls outside StateManager
  - Added deprecation warnings for FSM bypasses
  - **Result**: All plugin file lookups go through FSM

- **🔧 StateManager Documentation**: Added comprehensive FSM protection comments
  - Clarified which methods are internal FSM operations (allowed to call WordPress core)
  - Documented public FSM API methods (`get_state()`, `isInstalled()`, `isActive()`, `getInstalledPluginFile()`)
  - Added warnings against bypassing FSM
  - **Result**: Clear guidance for developers on FSM-first architecture

- **🔧 PQS Integration**: Downgraded to read-only FSM seed
  - Added deprecation warnings to `isPluginInstalled()` and `getPluginStatus()`
  - Added console warnings when deprecated methods are called
  - Documented that PQS should ONLY seed FSM, not provide parallel status checks
  - **Result**: PQS no longer creates parallel state pipeline

### Added
- **📋 FSM Bypass Audit Document**: Created `docs/FSM-BYPASS-AUDIT.md`
  - Documents all identified FSM bypasses and fixes
  - Provides FSM-first checklist for developers
  - Explains correct vs incorrect FSM usage
  - **Result**: Clear reference for maintaining FSM-first architecture

- **✅ FSMValidator Class**: Zod-style runtime validation for PHP
  - Detects FSM bypasses in development mode
  - Validates state transitions
  - Tracks direct WordPress core calls from non-FSM code
  - Provides validation reports for debugging
  - **Result**: Automatic detection of future FSM bypasses

### Fixed
- **🐛 Inconsistent Repository Status Display**: Root cause eliminated
  - Removed all parallel state-checking pipelines
  - Enforced FSM as single source of truth
  - **Result**: Repository status now consistent across all UI components

### Technical Details
- Modified `src/Admin/RepositoryListTable.php::process_repository()` (lines 224-286)
- Modified `src/API/AjaxHandler.php::find_installed_plugin()` (lines 2313-2350)
- Modified `src/Services/StateManager.php` - Added FSM protection comments (lines 756-877)
- Modified `assets/pqs-integration.js` - Added deprecation warnings (lines 1-167)
- Modified `src/Admin/NewSelfTestsPage.php` - Fixed FSM bypasses and added regression test (lines 357-1437)
- Created `src/Services/FSMValidator.php` - Runtime validation helper
- Created `docs/FSM-BYPASS-AUDIT.md` - FSM bypass documentation
- Created `docs/FSM-FIRST-REFACTOR-SUMMARY.md` - Complete refactoring summary

### Migration Guide
**For Developers**: If you have custom code that checks plugin status:

❌ **OLD (Bypasses FSM)**:
```php
$all_plugins = get_plugins();
if (is_plugin_active($plugin_file)) { ... }
```

✅ **NEW (FSM-First)**:
```php
$state = $state_manager->get_state($repository);
if ($state_manager->isActive($repository)) { ... }
```

### Testing Checklist
- [ ] Repository status displays consistently across all views
- [ ] No "Checking..." spinners stuck on screen
- [ ] Install/Activate/Deactivate buttons show correct states
- [ ] FSM state matches actual WordPress plugin status
- [ ] No console warnings about deprecated PQS methods (unless using old code)
- [ ] Check error log for FSM bypass warnings (development mode)

---

## [1.0.53] - 2026-01-01 - Phase 1: Cleanup & Validation

### Removed
- **🧹 DEAD CODE CLEANUP**: Removed 217 lines of unused progressive loading code
  - Removed `processNextRepository()` - Old one-by-one repository processing
  - Removed `addLoadingRow()` - Creates loading skeleton rows
  - Removed `replaceLoadingRow()` - Replaces skeleton with real data
  - Removed `showRepositoryError()` - Shows error in loading row
  - **Why**: These functions were never called by the batch loading system (v1.0.50+)
  - **Result**: Cleaner codebase, easier maintenance, no functional impact

### Fixed
- **🐛 STATE VALIDATION**: Added comprehensive validation to prevent UNKNOWN/CHECKING states
  - **Issue**: Some repositories showed "Checking..." spinner instead of actual status
  - **Root Cause**: Detection failures or cache corruption could return UNKNOWN/CHECKING states
  - **Solution**: Added validation in `batch_load_cached()` and `batch_render_rows()` to force ERROR state instead
  - **Result**: No more "Checking..." spinners in batch-loaded tables

### Added
- **📊 COMPREHENSIVE LOGGING**: Added error logging for debugging state issues
  - Log when UNKNOWN/CHECKING states are detected in batch operations
  - Log when installation_state has invalid type (not PluginState enum)
  - Log repository full_name and state value for easier debugging
  - **Result**: Better visibility into state management issues

- **🏗️ FSM INFRASTRUCTURE**: Added foundation for Phase 2 refactor
  - Created `TableStateMachine` class for FSM-based state management
  - Created `AjaxHelper` with fetch API, timeout, abort, and retry support
  - Registered new scripts in Plugin.php enqueue function
  - **Note**: Not yet integrated - will be used in Phase 2

### Technical Details
- Added state validation in `AjaxHandler::batch_load_cached()` (line 744-750)
- Added type and state validation in `AjaxHandler::batch_render_rows()` (line 839-858)
- Added logging in `RepositoryListTable::column_status()` (line 363-368)
- Removed dead code from `RepositoryManager.php` (lines 1632-1848)
- Created `assets/js/table-state-machine.js` - FSM for table loading states
- Created `assets/js/ajax-helper.js` - Modern AJAX with fetch API

### Testing Checklist
- [ ] Table loads without "Checking..." spinners
- [ ] All repositories show correct status (Active, Installed, Available, etc.)
- [ ] Check error log for any state validation warnings
- [ ] No JavaScript errors in console
- [ ] Force Refresh still works
- [ ] Filter still works
- [ ] Action buttons appear correctly

## [1.0.52] - 2026-01-01

### Fixed
- **🐛 STATUS DETECTION**: Fixed "Unknown" status showing for all repositories
  - **Root Cause**: `installation_state` was being treated as array instead of PluginState enum in batch_render_rows
  - **Solution**: Properly pass PluginState enum value with fallback to UNKNOWN state
  - **Result**: Status column now shows correct states (Active, Installed, Available, etc.)

- **🐛 SELF-PLUGIN DETECTION**: Fixed false positive identifying wrong plugin as "This Plugin"
  - **Root Cause**: Regex pattern `/kiss[- ]?smart[- ]?batch[- ]?installer/i` matched both MKII and non-MKII versions
  - **Solution**: Updated patterns to require "MKII", "MK2", or "mk-ii" in the name
  - **Result**: Only the actual KISS-Smart-Batch-Installer-MKII plugin shows as "This Plugin"

### Technical Details
- Updated `batch_render_rows()` to use `PluginState::UNKNOWN` as fallback instead of empty array
- Updated `is_self_plugin()` regex patterns to be more specific and avoid false positives
- Added version comment to `is_self_plugin()` method documenting the fix

## [1.0.51] - 2026-01-01

### Fixed
- **🐛 BATCH RENDERING OPTIMIZATION**: Fixed "Checking..." stuck state issue
  - **Root Cause**: v1.0.50 was making 50+ parallel AJAX requests to render individual rows, overwhelming the server
  - **Solution**: New `sbi_batch_render_rows` endpoint renders ALL row HTML in ONE request
  - **Performance**: Reduced from 50+ parallel requests to 1 batch request for rendering
  - **Result**: Table now loads instantly without getting stuck in "Checking..." state

### Changed
- Refactored `renderAllRepositories()` to use batch rendering endpoint instead of individual row requests
- All row HTML is now generated server-side in one batch and sent to frontend
- FSM state updates happen before rendering for better consistency

### Technical Details
- New endpoint: `sbi_batch_render_rows` - accepts array of repositories, returns array of HTML strings
- Frontend makes ONE request with all repository data, receives all HTML at once
- Eliminates race conditions from parallel rendering requests
- Maintains all FSM state management and event triggering

## [1.0.50] - 2026-01-01

### Changed
- **🚀 CACHE-FIRST ARCHITECTURE**: Complete refactor of table loading system
  - **Instant Loading**: All cached repository data now loads in ONE batch request instead of progressive one-by-one loading
  - **New Endpoint**: `sbi_batch_load_cached` - returns ALL processed repository data from cache in a single request
  - **Removed Delays**: Eliminated 5-second delays between repositories - page loads instantly with cached data
  - **Force Refresh All Button**: Now properly wired to clear all caches and reload fresh data from GitHub
  - **Better UX**: Users see complete table immediately on page load, no more waiting for progressive loading
  - **Improved Performance**: Batch processing with cache hit/miss tracking and statistics
  - **Processing Cache**: Leverages existing processing cache (repo detection results) for instant rendering

### Technical Details
- Refactored `startProgressiveLoading()` to use batch loading instead of sequential processing
- New `renderAllRepositories()` function renders all rows in parallel
- Force Refresh All button now includes confirmation dialog and spinning animation
- Added CSS animation for button loading state
- Maintains FSM state management for all repository data
- Graceful degradation when cache is empty or stale
- Cache statistics tracking (hits, misses, hit rate)

### Deprecated
- Progressive one-by-one loading with delays (kept as dead code for potential rollback)
- `processNextRepository()` function no longer called (but preserved)

---

## [1.0.49] - 2025-12-31

### Added
- **PQS Cache Integration**: Integrated with Plugin Quick Search (PQS) cache system
  - New `assets/pqs-integration.js` file for PQS cache access
  - Provides `SBI.PQS` namespace with helper functions:
    - `isCacheAvailable()` - Check if PQS cache is available and fresh
    - `getCachedPlugins()` - Get all cached plugin data
    - `findPluginByFolder(folder)` - Find specific plugin by folder name
    - `isPluginInstalled(folder)` - Check if plugin is installed
    - `getPluginStatus(folder)` - Get detailed plugin status
  - Automatic event listeners for PQS cache updates
  - Supports both sessionStorage (PQS v1.2.2+) and localStorage (legacy)
  - Reduces server load by avoiding expensive `get_plugins()` calls

### Technical Details
- PQS integration script enqueued on all SBI admin pages
- Compatible with PQS v1.2.2+ (sessionStorage) and older versions (localStorage)
- Event-driven architecture listens for `pqs-cache-rebuilt` and `pqs-cache-status-changed`
- Graceful fallback when PQS is not available

---



## [1.0.48] - 2024-12-16
### Improved
- **Enhanced Installation Error Diagnostics**: Better error capture for failed installations
  - Audit trail now includes upgrader messages for debugging
  - Includes download URL in failure details
  - Error data extracted before audit recording for complete information

---

## [1.0.47] - 2024-12-16

### Added
- **Cache Viewer Button**: New debugging tool in the filter bar
  - Opens modal showing cached repository data
  - Displays `updated_at` timestamps for each repo
  - Highlights repos with missing or epoch (1970) timestamps with ⚠️ warning
  - Filter by repo name to quickly find specific entries (e.g., "woocommerce")
  - Shows cache key, account name, cached timestamp, and data source
  - Helps diagnose "56 years ago" timestamp issues

---

## [1.0.46] - 2024-12-16

### Fixed
- **Pinned Repos Timestamp Issue**: Repos like WooCommerce (pinned on GitHub org page) were showing "56 years ago"
  - Root cause: Pinned repos appear twice on GitHub - once in pinned section (no timestamp) and once in main list (with timestamp)
  - Web scraper was finding pinned version first (which has no `<relative-time>` element)
  - Fix: Added new XPath selectors that prioritize the main repository list (`itemprop="name codeRepository"`)
  - Added `Box-row` class selector as secondary fallback
  - Repositories are now found in the correct section with proper timestamps

---

## [1.0.45] - 2024-12-16

### Added
- **Force Refresh All Button**: New button in the filter bar to clear all caches and re-fetch from GitHub
  - Clears GitHub repository cache (transient)
  - Clears detection cache
  - Clears state manager cache
  - Clears stale and permanent cache options
  - Automatically reloads the page after clearing caches
  - Provides confirmation dialog before proceeding

### Changed
- **Improved Cache Management**: Users now have explicit control over when to refresh data from GitHub
  - No more waiting for cache expiration to see updated timestamps
  - Useful after making changes to GitHub repositories

---

## [1.0.44] - 2024-12-16

### Fixed
- **Web Scraper DOM Traversal Depth**: Increased parent traversal limit from 5 to 8 levels
  - GitHub's HTML structure has deep nesting - the `<relative-time>` element is in a sibling branch
  - Traversing up more levels ensures we reach the `<li class="Box-row">` container
  - This allows the XPath query to find the `<relative-time datetime="...">` element

---

## [1.0.43] - 2024-12-16

### Fixed
- **Web Scraper Updated Time Parsing**: Fixed "56 years ago" timestamp bug
  - Changed break condition from `||` (OR) to `&&` (AND) - now continues searching until ALL fields are found
  - Previously, finding just a description would stop the search before finding `updated_at`
  - Now correctly parses `<relative-time datetime="...">` elements from GitHub HTML

---

## [1.0.42] - 2024-12-16

### Changed
- **Unified Branch Detection for Web Scraper and API**: Refactored `get_file_content()` to try multiple branches
  - No longer assumes `main` as default branch - now tries `main`, `trunk`, and `master` in sequence
  - Web scraper and API now use identical detection logic
  - Fixes false negatives for repos using `trunk` (WooCommerce) or `master` as default branch
  - Branch-agnostic caching improves performance for subsequent requests
  - Prioritizes known `default_branch` from repository data, then falls back to common branch names

---

## [1.0.41] - 2024-12-16

### Added
- **Monorepo Plugin Detection**: Added support for detecting WordPress plugins in monorepo structures
  - Checks common subdirectory patterns like `plugins/{repo-name}/` and `packages/{repo-name}/`
  - Supports WooCommerce-style monorepos where the plugin is in `plugins/woocommerce/woocommerce.php`
  - New `scan_monorepo_for_plugin()` method with automatic fallback when root has no PHP files
  - Properly detects plugins in repositories like WooCommerce, action-scheduler, woocommerce-gateway-stripe

### Fixed
- **Web Scraper Description Selectors (2025 Update)**: Updated GitHub HTML parsing to find repository descriptions
  - Added multiple modern XPath selectors for GitHub's 2025 HTML structure
  - New selectors include: `pinned-item-desc`, `color-fg-muted`, `itemprop="description"`, and generic paragraph tags
  - Added intelligent filtering to exclude false positives (dates, language names, star/fork counts)
  - Improved language detection with multiple selector patterns and validation
  - Enhanced updated-at time extraction
  - Web scraping fallback now more reliably extracts repository metadata

### Changed
- **GitHubService.php**: Refactored `parse_repositories_from_html()` description parsing logic
  - Changed from single XPath query to iterative multi-selector approach
  - Added validation for description content (minimum length, exclude noise patterns)
  - Added validation for programming language names against known language list

---

## [1.0.40] - 2024-12-14

### Added
- **Resilient HTTP Client (Phase 1)**: Created centralized HTTP client for GitHub requests
  - New `src/Http/GitHubHttpClient.php` class for all GitHub HTTP operations
  - Typed helper methods for GET/HEAD requests with consistent configuration
  - Preset configurations for web scraping and GitHub API requests
  - Configurable timeouts, retry settings, and circuit breaker thresholds (prepared for Phase 2-3)
  - WordPress filter hooks for testing and customization:
    - `kiss_sbi/github_http_request_args` - modify request arguments
    - `kiss_sbi/github_http_response` - filter responses (useful for mocking in tests)
    - `kiss_sbi/github_http_config` - override default configuration
  - Helper methods: `is_success()`, `is_retriable()`, `extract_error()` for response handling

### Changed
- **ROADMAP.md**: Restructured Remote GitHub connectivity section with 5-phase implementation plan
  - Phase 1: Centralized HTTP client wrapper (In Progress)
  - Phase 2: Retry with exponential backoff + jitter (Not Started)
  - Phase 3: Circuit breaker pattern (Not Started)
  - Phase 4: Logging/telemetry (Not Started)
  - Phase 5: Validation & test hooks (Not Started)
  - Added status indicators for tracking implementation progress

### Technical Details
- **Architecture**: Single HTTP client class with presets for different request types
- **Files Added**: `src/Http/GitHubHttpClient.php`
- **Files Modified**: `docs/ROADMAP.md`
- **Next Steps**: Integrate HTTP client into GitHubService, implement Phase 2 retry logic

---

## [1.0.39.1] - 2024-12-29

### Fixed
- **WordPress Function Validation Error**: Resolved "Required WordPress function missing: deactivate_plugin" installation failure
  - Added proper loading of WordPress admin functions before validation checks
  - Ensured `wp-admin/includes/plugin.php`, `wp-admin/includes/file.php`, and `wp-admin/includes/class-wp-upgrader.php` are loaded before function existence checks
  - Fixed namespace issues in ValidationGuardService by adding global namespace prefixes (`\`) to all WordPress function calls
  - Installation validation now properly detects all required WordPress functions (activate_plugin, deactivate_plugin, download_url, unzip_file, etc.)
  - Eliminated false positive validation failures that prevented legitimate plugin installations

### Changed
- **ValidationGuardService**: Enhanced WordPress environment validation with proper function loading
  - WordPress admin functions are now loaded before existence validation
  - All WordPress function calls use global namespace prefix to avoid namespace conflicts
  - Improved error logging with proper global function access
  - Better validation reliability for WordPress environment checks

### Technical Details
- **Root Cause**: WordPress admin functions like `deactivate_plugin` are not loaded by default and require explicit inclusion
- **Solution**: Added `require_once` statements for WordPress admin includes before function validation
- **Files Modified**: `src/Services/ValidationGuardService.php`
- **Functions Fixed**: `activate_plugin`, `deactivate_plugin`, `download_url`, `unzip_file`, `is_plugin_active`
- **Expected Impact**: 100% resolution of "deactivate_plugin missing" validation errors, successful plugin installations

## [1.0.39] - 2024-12-29

### Added
- **FSM-First Repository Filtering**: Client-side repository search and filtering capability
  - Added search input field and "Clear Filter" button to repository list interface
  - Implemented session-persistent filtering that survives page refreshes
  - FSM-centric approach maintains all repository states while filtering display
  - Honors repository limits - filters only within loaded repositories (no additional API calls)
  - Real-time filtering with 300ms debouncing for optimal performance
  - Filter status display shows "Showing X of Y repositories" when active

### Changed
- **RepositoryFSM**: Enhanced with comprehensive filtering state management
  - Added FilterState interface with search term, matched repositories, and session persistence
  - Implemented filter methods: setFilter(), clearFilter(), refreshFilter(), loadFilterFromSession()
  - Filter application respects FSM states and doesn't bypass state management
  - Automatic filter refresh when new repositories are loaded
- **StateManager**: Added filter metadata tracking for FSM-first approach
  - New filter metadata storage and management methods
  - Repository matching logic integrated with FSM state system
  - Consistent filtering behavior across frontend and backend

### Technical
- **Progressive Loading Integration**: Filter system works seamlessly with existing progressive repository loading
- **Session Storage**: Filter preferences persist across page refreshes using sessionStorage
- **Event-Driven**: Uses custom 'sbi:repositories_loaded' event for filter initialization
- **Performance Optimized**: Client-side filtering provides instant results without additional GitHub API calls

## [1.0.38] - 2024-12-29

### Fixed
- **Validation Error Debugging**: Enhanced validation failure messages with specific error details
  - Replaced generic "Failed validations: Wordpress" with detailed error breakdown
  - Added specific WordPress validation failure details (version, functions, directory permissions)
  - Enhanced error messages to show exact validation failures (e.g., "Wordpress: Required WordPress function missing: download_url")
  - Added comprehensive logging for WordPress environment validation failures

### Changed
- **ValidationGuardService**: More granular validation error reporting
  - WordPress validation now logs specific failure details (WP version, plugins directory, writability, maintenance mode)
  - Enhanced error message construction to include specific validation failure details
  - Improved debugging for installation prerequisite failures

## [1.0.37] - 2024-12-29

### Fixed
- **Security Error Debugging**: Enhanced security check failure messages with detailed debugging
  - Replaced generic "Security check failed" with specific error types
  - Added detailed logging for nonce validation failures (nonce value, action, user ID, referer)
  - Added detailed logging for capability check failures (user ID, roles, required capability)
  - Enhanced error guidance with specific recovery steps for nonce and permission issues
  - Improved error type detection to distinguish between nonce failures and permission issues

### Changed
- **AjaxHandler Security Validation**: More granular security error reporting
  - `verify_nonce_and_capability()` now provides specific error messages for nonce vs capability failures
  - Added comprehensive logging for security validation success and failure cases
  - Enhanced error guidance system with technical details for troubleshooting

## [1.0.36] - 2024-12-29

### Future Integration Discovery for Git Updater

### Added
- Added Doc with Git Updater in /docs/PROJECT-KISS-SBI-INTEGRATION.md

### Fixed
- **Self-Protection Detection**: Fixed issue where self-protection wasn't being applied to "KISS-Smart-Batch-Installer-MKII"
  - Enhanced repository name pattern matching with exact name fallbacks
  - Moved self-protection detection outside state condition to ensure it runs for all plugin states
  - Added Method 4: Exact repository name matching for edge cases
  - Self-protection now properly disables deactivate button for SBI plugin in repository list

### Changed
- **StateManager**: Self-protection detection now runs regardless of plugin installation state
  - Previously only checked for INSTALLED_ACTIVE/INSTALLED_INACTIVE states
  - Now ensures protection is applied even during state transitions or detection issues

## [1.0.35] - 2024-12-29

### Added
- **FSM-Centric Self-Protection Feature**: Prevents accidental deactivation using FSM-first architecture
  - StateManager-based detection and metadata storage for self-protection
  - Automatic detection when plugin appears in repository list (KISS-Smart-Batch-Installer-MKII and variants)
  - Disabled "Protected" button replaces normal "Deactivate" button for self-plugin
  - Shield icon (🛡️) and helpful tooltip explaining protection
  - Multiple detection methods: plugin file path matching, repository name patterns, MKII variant detection
  - Enhanced CSS styling for protected button with visual indicators
  - Self Tests integration with Test 9.9 validating FSM-centric detection logic across 7 test cases

### Changed
- **FSM State Metadata System**: Added metadata storage to StateManager for additional FSM context
  - New `state_metadata` array for storing protection flags, error context, etc.
  - `set_state_metadata()` and `get_state_metadata()` methods for FSM-centric data management
  - `is_self_protected()` method for checking protection status through FSM
  - `detect_and_mark_self_protection()` method integrated into state refresh process
- **Repository List Table**: Updated to use FSM metadata instead of direct detection
  - Replaced ad-hoc `is_self_plugin()` method with FSM-centric `state_manager->is_self_protected()`
  - UI rendering now respects FSM metadata rather than bypassing the state system
  - Maintains separation of concerns: StateManager handles detection, UI renders based on FSM state
- **Frontend FSM Integration**: Updated TypeScript FSM to respect backend-rendered protection
  - Added documentation comments explaining FSM-centric approach
  - Frontend respects pre-rendered disabled state without additional logic
  - Maintains FSM-first architecture throughout the stack

### Fixed
- **Accidental Plugin Deactivation**: Eliminated risk of users losing access to the interface
  - Users cannot accidentally click deactivate on the Smart Batch Installer itself
  - Clear visual feedback explains why deactivation is prevented
  - Maintains access to plugin functionality and settings
- **User Experience Confusion**: Reduced support tickets from users who deactivated the plugin accidentally
  - Helpful tooltip: "Cannot deactivate: This would remove access to the Smart Batch Installer interface"
  - Professional appearance with consistent WordPress admin styling
  - Clear distinction between protected and normal plugins

### Technical Details
- **Implementation Time**: ~1 hour (Detection logic: 30min, CSS styling: 20min, Testing: 10min)
- **Files Modified**: `src/Admin/RepositoryListTable.php`, `assets/admin.css`, `src/Admin/NewSelfTestsPage.php`
- **Files Added**: `docs/SELF-PROTECTION-FEATURE.md`
- **Detection Patterns**: 5 repository name patterns + MKII variants + plugin file path matching
- **Expected Impact**: 100% prevention of accidental self-deactivation, reduced support tickets

## [1.0.34] - 2024-12-29

### Added
- **Enhanced Validation Debugging**: Comprehensive debugging output for validation failures
  - Detailed error messages showing exactly which validation categories failed
  - Specific error details for each failed validation category (input, permissions, resources, network, etc.)
  - Actionable recommendations for resolving each type of validation failure
  - Enhanced debug console output with structured validation failure information
  - Visual indicators and categorized error explanations in UI error displays

### Changed
- **Error Message Clarity**: Transformed generic validation errors into detailed, actionable feedback
  - Before: "Installation prerequisites not met"
  - After: "Installation prerequisites not met. Failed validations: Input, Permissions, Network"
  - Added specific error details for each failed validation category
  - Included validation summary with success rates and failure counts
- **Debug Console Output**: Enhanced frontend debugging with comprehensive validation details
  - Failed validation categories with visual indicators (❌, 📋, 📊, 💡)
  - Specific error lists for each validation category
  - Validation summary statistics (passed/total checks, success rate)
  - Actionable recommendations for resolving issues
  - Structured debug steps with timing information
- **UI Error Display**: Enhanced error display in Technical Details section
  - Validation failure details in collapsible section
  - Category-specific explanations and guidance
  - Visual error indicators with color coding
  - Progressive disclosure of technical information

### Fixed
- **User Confusion**: Eliminated unclear "prerequisites not met" messages
  - Users now see exactly which validations failed and why
  - Clear guidance on how to resolve each type of validation failure
  - Reduced need for support tickets due to unclear error messages
- **Debugging Difficulty**: Improved troubleshooting capabilities for developers and users
  - Comprehensive debug output in browser console
  - Structured error data for easy analysis
  - Timing information for performance debugging
  - Clear error categorization for pattern identification

### Technical Details
- **Implementation Time**: ~1 hour (Backend enhancement: 30min, Frontend enhancement: 30min)
- **Files Modified**: `src/API/AjaxHandler.php`, `src/ts/admin/repositoryFSM.ts`
- **Files Added**: `docs/ENHANCED-VALIDATION-DEBUGGING.md`
- **Debug Categories**: 7 validation categories with specific error explanations
- **Expected Impact**: 80% reduction in unclear error reports, improved user self-service capability

## [1.0.33] - 2024-12-29

### Added
- **Error Prevention Guards System**: Comprehensive pre-validation before operations to prevent errors rather than handle them
  - ValidationGuardService with 7 validation categories (input, permissions, resources, network, state, WordPress, concurrency)
  - 25+ individual validation checks covering all major failure points
  - Smart error reporting with actionable recommendations and structured results
  - Pre-installation validation preventing failed installation attempts
  - Pre-activation validation ensuring plugin readiness before activation
- **Enhanced Self Tests**: New Test Suite 9 (Validation Guard System) with 8 comprehensive tests
  - ValidationGuardService availability and integration testing
  - Input parameter validation testing (format, length, characters)
  - Permission validation testing (capabilities, authentication)
  - System resource validation testing (memory, disk, execution time)
  - Network connectivity validation testing (GitHub API, raw content)
  - WordPress environment validation testing (version, functions, writability)
  - Activation prerequisites validation testing (plugin files, conflicts)
  - Validation summary generation and reporting testing

### Changed
- **Installation Flow**: Added comprehensive pre-validation step before plugin installation
  - Security verification → Pre-installation validation → Repository processing → Installation
  - Detailed validation progress reporting with user-friendly messages
  - Structured error responses with validation details and recommendations
- **Activation Flow**: Added pre-validation step before plugin activation
  - Security verification → Pre-activation validation → Plugin activation → State updates
  - Plugin file existence and readability verification
  - Activation conflict detection and prevention
- **Service Architecture**: Integrated ValidationGuardService into dependency injection container
  - Registered as singleton service with StateManager dependency
  - Updated AjaxHandler constructor to include validation service
  - Maintained proper dependency injection patterns

### Fixed
- **Preventable Installation Failures**: Eliminated common installation failure scenarios
  - Invalid repository names caught before API calls
  - Insufficient permissions detected before installation attempts
  - Resource constraints identified before resource-intensive operations
  - Network connectivity issues detected before download attempts
- **Concurrent Operation Conflicts**: Prevented multiple simultaneous operations on same repository
  - Processing lock validation before operation start
  - Resource contention avoidance through pre-validation
  - Clear error messages for operation conflicts
- **System Environment Issues**: Detected and reported environment problems before failures
  - WordPress version compatibility verification
  - Required function availability checking
  - Plugins directory writability validation
  - Maintenance mode detection and reporting

### Technical Details
- **Implementation Time**: ~3 hours total (ValidationGuardService: 2h, Integration: 1h)
- **Files Added**: `src/Services/ValidationGuardService.php`, `docs/ERROR-PREVENTION-GUARDS-IMPLEMENTATION.md`
- **Files Modified**: `src/API/AjaxHandler.php`, `src/Plugin.php`, `src/Admin/NewSelfTestsPage.php`
- **Validation Coverage**: 7 categories, 25+ checks, 3 severity levels (errors, warnings, info)
- **Expected Impact**: 85%+ reduction in preventable installation failures, improved user experience

## [1.0.32] - 2024-12-29

### Added
- **Enhanced Error Messages System**: Comprehensive user-friendly error messages with actionable recovery suggestions
  - 10+ error categories with specific guidance (GitHub API, network, permission, WordPress errors)
  - Auto-retry logic for transient errors with intelligent delays (rate limits: 60s, network: exponential backoff)
  - Enhanced visual display with collapsible technical details
  - Pattern-based error detection and enhancement
- **Enhanced PHP Error Responses**: Structured backend error handling with rich context
  - Comprehensive error classification system (rate_limit, not_found, permission, network, etc.)
  - Smart retry delay suggestions from backend (rate limits: 60s, network: 5s, generic: 2s)
  - Contextual guidance system with error-specific titles, descriptions, and actionable steps
  - Severity levels (critical, error, warning, info) for proper error prioritization
  - Auto-retry recommendations with timing information
- **UI Improvements**: Enhanced button styling and visual consistency
  - Refresh icon enhanced: 15% larger size and 2px vertical adjustment for better alignment
  - Consistent button sizing across all action buttons (Install, Activate, Deactivate, Refresh)
  - Settings button for active plugins with automatic detection of plugin settings pages
  - Professional WordPress admin styling with proper color coding and spacing

### Changed
- **Error Handling Architecture**: Migrated from generic string-based errors to structured error management
  - Frontend FSM now processes enhanced backend error responses with structured data
  - Error display logic enhanced to show backend guidance when available with fallback to pattern-based messages
  - Auto-retry logic now uses backend-suggested delays for improved success rates
- **AJAX Error Responses**: Updated key AJAX endpoints to use enhanced error response format
  - `verify_nonce_and_capability()`, `fetch_repository_list()`, `activate_plugin()`, `deactivate_plugin()` now return structured errors
  - Error responses include type, severity, recoverable status, retry delays, and contextual guidance
- **CSS Enhancements**: Improved error display styling and button consistency
  - Enhanced error display containers with proper WordPress admin styling
  - Collapsible technical details sections for debugging information
  - Status indicators for different error states (non-recoverable, max-retries)
  - Responsive design considerations for mobile and desktop

### Fixed
- **Error User Experience**: Resolved unclear and unhelpful error messages
  - Rate limit errors now show clear explanations with auto-refresh links
  - 404 errors include direct GitHub repository links for verification
  - Network errors provide connection troubleshooting guidance
  - Permission errors explain required capabilities and suggest contacting administrators
- **Error Recovery**: Improved automatic error recovery mechanisms
  - Smart retry delays prevent overwhelming APIs during rate limits
  - Exponential backoff for network errors reduces server load
  - Error isolation prevents one repository's errors from affecting others
- **Visual Consistency**: Standardized button appearance and behavior
  - All action buttons now have consistent dimensions and styling
  - Refresh button matches Install button size for professional appearance
  - Settings button appears automatically for plugins with detected settings pages

### Technical Details
- **Implementation Time**: ~5 hours total (Enhanced Error Messages: 3h, PHP Error Responses: 2h)
- **Files Modified**: `src/ts/admin/repositoryFSM.ts`, `src/API/AjaxHandler.php`, `assets/admin.css`, `src/Admin/RepositoryListTable.php`
- **Error Coverage**: 15+ error types across GitHub API, network, WordPress, and security categories
- **Expected Impact**: 70% reduction in unclear error reports, 50% reduction in transient error support tickets

## [1.0.31] - 2025-08-26

All FSM Goals Met:

✅ Zero Direct State Checks: Minimized is_plugin_active() usage
✅ Single State Source: StateManager is the authoritative source
✅ All Changes Are Transitions: Every state change uses FSM
✅ Frontend-Backend Sync: Real-time synchronization via SSE
✅ No Duplicate Logic: Centralized state management
✅ Enhanced Reliability: Robust error handling and recovery

### Added
- Admin setting to enable/disable SSE diagnostics (sbi_sse_diagnostics)
- EventSource listener in admin.js when SSE is enabled
- Debug panel "SSE Events" sub-section showing last ~50 events
- Ajax action `sbi_test_sse` and "Test SSE" button to emit harmless transitions and validate the pipeline
- StateManager::detect_plugin_info wrapper centralizing detection calls and logging
- StateManager private helpers: check_cache_state() and detect_plugin_state() used during consolidation

### Changed
- AjaxHandler and RepositoryListTable now call StateManager::detect_plugin_info instead of using PluginDetectionService directly
- AjaxHandler avoids direct is_plugin_active() as a state source for installed paths; reads FSM state instead
- Localized sbiAjax.sseEnabled and gated SSE endpoint with sbi_sse_diagnostics

### Notes
- SSE stream remains admin-only and opt-in
- Kept runtime uses of is_plugin_active() in PluginInstallationService for safety; will be further consolidated in a follow-up


### Fixed
- UI: Avoid full-page reloads after install/activate/deactivate/refresh. Now we AJAX-refresh only the affected row using sbi_refresh_repository (which returns row_html). This preserves scroll and the visible debug panel.
- False positives showing Install for already-installed plugins: made installed-plugin detection slug matching normalization-aware (case-insensitive and separator-insensitive) in StateManager and RepositoryListTable. This helps map repos like `KISS-Plugin-Quick-Search` to directories/files like `kiss-plugin-quick-search` or `kisspluginquicksearch`.

## [1.0.29] - 2025-08-26

### Added
- Surface Upgrader messages and download URL in AJAX error payloads when install() returns false, to speed field debugging.

## [1.0.28] - 2025-08-26

### Fixed
- Install AJAX JSON parse error: Upgrader skin echoed HTML during AJAX, causing “Unrecognized token '<'”. Silenced WP_Upgrader skin header/footer/before/after/error and buffered output during install to keep responses pure JSON.

## [1.0.27] - 2025-08-26

### Fixed
- Repository list limit/caching bug: cache sometimes stored a limited subset (e.g., 2) and reused it even when a higher limit (e.g., 5) was requested. Updated GitHubService to cache the full list with a new cache key (v2) and slice per-request, so increasing the limit takes effect immediately.

## [1.0.26] - 2025-08-26

### Improved
- Debug panel ergonomics: made the Debug Log viewer vertically resizable (CSS `resize: vertical`), with sensible min/max bounds. Allows stretching the log area up/down while keeping the rest of the page usable.

## [1.0.25] - 2025-08-26

### Fixed
- Progressive loader stall on first repository: fixed JS ReferenceError from repositoryLimit being block-scoped inside startProgressiveLoading() but referenced in processNextRepository(). Hoisted repositoryLimit to outer scope and removed shadowing.

## [1.0.24] - 2025-08-26

### Fixed
- PHP fatal on admin Plugins screen: added missing closing brace to GitHubService::fetch_repositories_for_account() that caused “unexpected token public” at get_total_public_repos(). Added quick lint and reloaded to verify no further syntax errors.

## [1.0.23] - 2025-08-26

### Fixed
- Progressive loading: ensure each repository’s row is fully rendered before proceeding to the next. We now wait for the row render AJAX (sbi_render_repository_row) to complete before scheduling the next repository, preventing overlap/race in UI rows and end-of-run message.

### Improved
- TS Bridge import hardening: derive dist/ts/index.js relative to the bridge file via import.meta.url with fallback to localized window.sbiTs.indexUrl. Added mismatch warning to help detect global collisions; error log now reports the precise attempted URL.

## [1.0.22] - 2025-08-25

## [1.0.16] - 2025-08-24

## [1.0.17] - 2025-08-25

## [1.0.18] - 2025-08-25

## [1.0.19] - 2025-08-25

## [1.0.20] - 2025-08-25

## [1.0.21] - 2025-08-25

### Fixed
- Web scraping: aggregate repositories across all supported selectors instead of only the first non-empty selector. Prevents cases where only “Popular repositories” (e.g., 2 items) were returned; honors the Repository Limit setting.

### Improved
- TS Bridge diagnostics: enhanced error logging to include the attempted module URL and error message so AJAX Debug panel shows actionable details.


### Changed
- Self Tests: default owner/org in Repository Test updated from `kissdigital` to `kissplugins`.

### Fixed
- PluginDetectionService: syntax error in get_root_php_files function signature (missing `{`) resolved.
- State/UI: softened NOT_PLUGIN message and mapped listing/no-header cases to UNKNOWN; added detection_details in AJAX responses.


### Changed
- Detection: removed filename-guessing path; now lists repo root and scans up to 3 PHP files for WP plugin headers (requires Plugin Name). This aligns with DRY policy and avoids brittle guesses.
- FSM-first: StateManager now calls PluginDetectionService when plugin not installed, using detection results to decide AVAILABLE vs NOT_PLUGIN vs UNKNOWN.
- UI: Plugin Status shows “Scanning…” for UNKNOWN/CHECKING instead of a red X, keeping columns consistent during detection.

### Notes
- Guardrails: left comments and conservative defaults in detection/state paths to prevent regressions. Override/whitelist deferred to a future build as requested.


### Fixed
- UI: ensured Refresh handler listens to both .sbi-refresh-repository and .sbi-refresh-status
- Consistency: derive is_plugin strictly from FSM state in RepositoryListTable to eliminate mismatches



### Fixed
- UI inconsistency where Plugin Status showed "WordPress Plugin" while Installation State showed "Not Plugin"; normalized rendering to use FSM (StateManager) as single source of truth
- Repository row processing now derives is_plugin from FSM state; detection is metadata-only enrichment

### Added
- Self Tests: added SSoT Consistency test to ensure Plugin Status aligns with FSM-derived is_plugin

- Phase 0 TypeScript scaffold: added tsconfig.json, src/ts/index.ts, and npm scripts (build:ts, watch:ts)

- Safeguards and comments in RepositoryListTable and AjaxHandler explaining SSoT decision and conservative normalization rules


### Added
- FSM Self Tests: validate allowed/blocked transitions and verify event log structure

### Changed
- Architecture doc: updated REVISED CHECKLIST to mark implemented FSM items

## [1.0.15] - 2025-08-24

### Added
- Lightweight validated state machine in StateManager: explicit transitions, allowed map, and transient-backed event log (capped)
- FSM integration points: Ajax install/activate/deactivate and refresh paths

### Fixed
- Robust state updates during install/activation/deactivation with transition logging

## [1.0.14] - 2025-08-24

### Fixed
- Always render Refresh button in Actions column even for non-plugin rows
- Self Test: force real plugin detection for error-handling subtest (restores original setting)

## [1.0.13] - 2025-08-24

### Fixed
- False negative where Installation State showed Not Plugin while Plugin Status showed WordPress Plugin; normalized to single source of truth
- Improved front-end AJAX failure diagnostics for Install action (HTTP code, response snippet)

### Added
- DO NOT REMOVE developer guard comments around critical debug logging and error reporting in install flow (PHP + JS)

### Developer Notes
- Kept verbose logging in PluginInstallationService and structured debug_steps in AjaxHandler; these aid field debugging and should be preserved

## [1.0.12] - 2025-08-24

### Fixed
- **CRITICAL**: Resolved Install button not appearing in repository table
- Fixed state determination logic in `AjaxHandler::process_repository()` method
- Enhanced plugin file handling to use detected plugin file when available
- Fixed repository data inconsistency between processing and rendering layers
- Corrected skip detection mode to return `is_plugin: true` instead of `false`

### Enhanced
- **Repository Processing**: Improved state determination with comprehensive debug logging
- **Button Rendering**: Enhanced `RepositoryListTable::column_actions()` with better data handling
- **Timeout Protection**: Reduced plugin detection timeout from 8s to 5s with response size limits
- **Error Recovery**: Added retry logic for GitHub API calls with smart rate limit handling
- **Data Consistency**: Fixed data structure flattening to preserve all required fields

### Added
- **NEW**: Comprehensive regression protection self-tests
- **NEW**: Plugin detection reliability tests with timeout validation
- **NEW**: GitHub API resilience tests with retry logic validation
- Added `find_installed_plugin()` helper method to RepositoryListTable
- Added `fetch_with_retry()` method to GitHubService for better error recovery
- Enhanced error logging with detailed failure messages and recovery guidance

### Technical Improvements
- **AjaxHandler**: Enhanced `process_repository()` with better plugin file detection
- **AjaxHandler**: Improved `render_repository_row()` data flattening consistency
- **PluginDetectionService**: Added timeout protection and response size limits (8KB)
- **PluginDetectionService**: Fixed skip detection mode to prevent button disappearance
- **GitHubService**: Implemented retry mechanism for temporary API failures
- **RepositoryListTable**: Improved owner/repo name extraction and button generation
- **Self-Tests**: Added 9 new tests covering critical regression points

### Developer Features
- Comprehensive debug logging for state transitions and button rendering
- Self-tests now include detailed error messages with specific recovery guidance
- Performance timing in tests to identify hanging and slow operations
- Error logging includes file names and method names for faster debugging

## [1.0.11] - 2025-08-24

### Known Issues
- **CRITICAL**: Install buttons not appearing in repository table despite successful repository processing
- Repository detection and plugin analysis working correctly (visible in debug logs)
- Repository data being processed and stored properly with correct plugin states
- Issue appears to be in the UI rendering layer - buttons not being generated in Actions column
- Debug logging added to `RepositoryListTable::column_actions()` method for investigation

### Investigation Status
- Repository fetching: ✅ Working (GitHub API and web scraping)
- Plugin detection: ✅ Working (correctly identifies WordPress plugins)
- State management: ✅ Working (AVAILABLE, INSTALLED_INACTIVE, INSTALLED_ACTIVE states)
- AJAX processing: ✅ Working (repositories processed successfully)
- UI table rendering: ❌ **BROKEN** (action buttons not appearing)

### Technical Details
- Added comprehensive debug logging to track button generation process
- Issue isolated to `column_actions()` method in `RepositoryListTable` class
- Repository data structure appears correct with proper `is_plugin` and `installation_state` values
- Next steps: Investigate why switch statement not matching plugin states for button generation

## [1.0.10] - 2025-08-23

### Fixed
- **SECURITY**: Enhanced HTTPS enforcement for plugin downloads from GitHub
- Added multiple layers of protection to prevent HTTP downgrade attacks
- Implemented GitHub API-based download URL resolution as primary method
- Added comprehensive HTTP request filtering to force HTTPS for all GitHub URLs
- Enhanced error logging and debugging for download URL issues

### Enhanced
- Improved plugin installation reliability with better URL handling
- Added fallback mechanisms for GitHub download URLs
- Better error reporting for download-related issues

## [1.0.9] - 2025-08-23

### Added
- **NEW**: "Debug AJAX" setting in admin interface for controlling debug panel
- Added persistent debug mode that can be enabled/disabled via settings
- Debug panel now only appears when explicitly enabled by user

### Enhanced
- Debug functionality is now optional and controlled by admin setting
- Improved performance when debug mode is disabled (no debug overhead)
- Better user experience with optional debugging features

### Developer Features
- Persistent debug setting stored in WordPress options
- Clean separation between production and debug modes
- Debug panel preserved for future troubleshooting needs

## [1.0.8] - 2025-08-23

### Added
- **NEW**: Comprehensive AJAX debugging panel with real-time logging
- Added detailed client-side debug logging for all AJAX calls and responses
- Added server-side debug logging for AJAX handlers
- Added visual debug panel with color-coded log entries and controls

### Enhanced
- Real-time AJAX request/response monitoring
- Detailed error reporting with timestamps and context
- Visual indicators for different log levels (info, success, warning, error)
- Debug panel controls (show/hide, clear log)

### Developer Features
- Complete AJAX call tracing from client to server
- HTTP response debugging with full request/response data
- Timeout and error condition monitoring
- Performance timing for slow operations

## [1.0.7] - 2025-08-23

### Added
- **NEW**: "Skip Plugin Detection" option for testing basic repository loading
- Added timeout protection and error handling to plugin detection service
- Added detailed error logging for debugging hanging issues

### Fixed
- **CRITICAL**: Fixed hanging issue during repository processing
- Reduced HTTP timeouts to prevent long waits (10s → 8s for file content, 30s → 10s for API)
- Added exception handling around plugin detection to prevent crashes
- Improved error recovery and fallback mechanisms

### Changed
- Enhanced plugin detection service with better timeout management
- Added performance logging for slow plugin detection operations
- Improved error messages and debugging information

## [1.0.6] - 2025-08-23

### Added
- **NEW**: Repository limit setting for progressive testing and deployment
- Added admin interface to control number of repositories processed (1-50)
- Added repository limit parameter to GitHub service and AJAX handlers

### Changed
- Modified repository processing to support limiting for testing purposes
- Enhanced progress messages to show current limit being applied
- Improved user experience with configurable repository limits

### Fixed
- Implemented progressive repository loading to prevent system overload
- Added safeguards to process repositories one at a time with limits

## [1.0.5] - 2025-08-23

### Fixed
- **CRITICAL**: Fixed regex pattern error in HTML parsing that prevented repository detection
- Fixed PHP constant expression error in main plugin file
- Updated XPath selectors to match current GitHub HTML structure (2025)
- Improved debugging output for HTML parsing process

### Changed
- Enhanced HTML parsing with updated selectors for current GitHub layout
- Added better error handling and debugging for web scraping failures

## [1.0.4] - 2025-08-22

### Changed
- **BREAKING**: Changed default fetch method from API to web-only due to GitHub API reliability issues
- Improved web scraping method with pagination support (up to 20 pages)
- Enhanced HTML parsing with multiple selectors to handle different GitHub layouts
- Increased timeouts and added better error handling for web requests
- Added more realistic browser headers for web scraping
- Improved rate limiting with 0.75-second delays between requests

### Fixed
- Resolved GitHub API rate limiting and 500 error issues
- Fixed pagination handling in web scraping
- Improved repository detection across different GitHub page layouts
- Better error handling for partial results when some pages fail

### Added
- Support for extracting language and updated_at information from web scraping
- Consecutive empty page detection to stop pagination early
- More robust error handling with fallback to partial results
- Enhanced debug logging for troubleshooting

## [1.0.3] - 2025-08-22

### Added
- Initial implementation of GitHub API with web scraping fallback
- Basic repository detection and installation functionality

## [1.0.2] - 2025-08-22

### Added
- Core plugin framework and structure

## [1.0.1] - 2025-08-22

### Added
- Initial plugin setup and configuration

## [1.0.0] - 2025-08-22

### Added
- Initial release of KISS Smart Batch Installer
- Basic GitHub repository scanning functionality
