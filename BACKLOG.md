# KISS Smart Batch Installer MKII - BACKLOG

**Doc Metadata**
- **Last updated**: 2026-01-15
- **Plugin version (context)**: 1.0.70
- **Scope**: Engineering backlog focused on FSM integrity + reliability
- **Rule**: FSM is the single source of truth; no parallel state pipelines

---

## Priority Legend

- **P0 (High)**: Prevents state corruption/inconsistency; blocks other work
- **P1 (Medium)**: Improves reliability/UX; not usually blocking
- **P2 (Low/Deferred)**: Nice-to-have; do after stabilization

---

## Backlog (Prioritized)

### P0 - State Enum Synchronization (PHP <-> TypeScript)

**Status**: [x] Completed

**Goal**: Prevent frontend/backend FSM mismatches by enforcing enum parity.

**Files**:
- `src/Enums/PluginState.php`
- `src/ts/types/fsm.ts`

**Checklist**:
- [x] Add a script/command to diff PHP enum values vs TS enum values
- [x] Add CI check to fail builds if mismatch is detected
- [x] Document the "how to add a state" process (update both enums in one PR)
- [x] Add a quick developer-facing error message when mismatch is detected

**Acceptance criteria**:
- [x] CI fails when enums diverge
- [x] Developer docs clearly explain the update workflow

---

### P0 - State Transition Validation (Matrix + Automated Tests)

**Status**: [ ] Not started

**Goal**: Prevent invalid transitions and make failures diagnosable.

**Checklist**:
- [ ] Document a transition matrix (source -> allowed targets)
- [ ] Add automated tests for allowed transitions
- [ ] Add automated tests for disallowed transitions
- [ ] Log failed transitions with context (repo, from, to, source)

**Acceptance criteria**:
- [ ] Invalid transitions are blocked consistently
- [ ] Logs contain enough context to debug quickly

---

### P1 - Single FSM Per Layer (Backend + Frontend)

**Status**: [ ] Not started

**Goal**: Ensure exactly one FSM instance per layer to avoid split-brain state.

**Checklist**:
- [ ] Add a backend guard/warning if StateManager is initialized multiple times
- [ ] Add a frontend guard/warning if repositoryFSM is initialized multiple times
- [ ] Document the rule in onboarding/dev docs

**Acceptance criteria**:
- [ ] Duplicate FSM instances are detected and surfaced clearly

---

### P1 - SSE Real-Time Integration Improvements

**Status**: [ ] Not started

**Goal**: Improve reliability of real-time state updates.

**Checklist**:
- [ ] Auto-reconnect with exponential backoff
- [ ] Fallback polling when SSE is unavailable
- [ ] Log SSE errors and connection state changes

**Acceptance criteria**:
- [ ] State updates remain reliable under flaky connections

---

### P1 - Extensibility Constraints (Hooks Around Transitions)

**Status**: [ ] Not started

**Goal**: Provide safe extension points so custom code doesn't bypass the FSM.

**Checklist**:
- [ ] Add pre-transition and post-transition hooks
- [ ] Document supported extension patterns
- [ ] Add guidance: "never call get_plugins()/is_plugin_active() outside StateManager"

**Acceptance criteria**:
- [ ] Developers can extend behavior without introducing parallel state checks

---

### P2 - Testing & Validation Requirements (CI/CD)

**Status**: [ ] Not started

**Goal**: Make FSM integrity tests mandatory in automation.

**Checklist**:
- [ ] Add CI workflow that runs the self-test suite (or equivalent automated checks)
- [ ] Gate merges on FSM-related regression tests
- [ ] Add lightweight smoke checks for critical admin pages

**Acceptance criteria**:
- [ ] CI reliably blocks regressions that bypass or corrupt FSM

---

### P2 - FSM Core File Protection (Deferred until stable)

**Status**: [-] Deferred

**Goal**: Reduce accidental edits to FSM core files once stabilized.

**Deferred until**: Testing has concluded and codebase is stable enough to warrant hard protection.

**Checklist (when re-activated)**:
- [ ] File permissions / repo policy for protected FSM core files
- [ ] Pre-commit hook to block edits without an explicit override
- [ ] Document override process

**Protected files (core FSM)**:
- `src/Services/StateManager.php`
- `src/Enums/PluginState.php`
- `src/ts/admin/repositoryFSM.ts`
- `src/ts/types/fsm.ts`

---

## Notes

- Completed items should be moved to `CHANGELOG.md` and removed from this file to keep it small.