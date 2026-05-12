# C2 Bare Cart-Line Migration — Follow-up Plan

> **For Codex (implementer) + Opus (reviewer):** Single PR landing on `dev`. Codex implements all three tasks in one branch, opens a PR, runs `codex review --base dev` until APPROVE or STOP-3, then Opus does a final pre-merge review (Item 8's destructive nature means we hold to one Opus review even for follow-ups).

**Goal:** Close the three residual concerns from PR #118's eleven-round review trail. None block the canonical single-device deployment; all three close real edge-case correctness or robustness gaps that the parapharmacy-go-live audit phase would otherwise re-surface.

**Architecture:** Three tightly-related task groups in a single PR — they all live in `apps/pos/src/lib/migration/`, `apps/pos/src/stores/`, and `apps/pos/src/lib/storage.ts`. No backend changes.

**Tech Stack:**
- `apps/pos`: React 19 / Vite 7 / TypeScript strict / Zustand 5 / Tauri 2 / Vitest. Commands: `pnpm typecheck`, `pnpm lint`, `pnpm test`. Lint baseline: **0 errors / 41 warnings — do not introduce new warnings.**
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp-pos-stabperf`. **Never push to `main`; merge to `dev` via PR.**
- Branch: `feat/pos-c2-migration-followup`

---

## Pre-flight downstream audit (paste into the PR body)

The PR body MUST open with this audit so Codex catches every ingress upfront. Lessons L1 (cross-tenant), L8 (cross-screen ownership), L9 (ingress-site enumeration), and STOP-3 (round-5 surface) apply.

For each task below, enumerate every ingress site and confirm each is updated together.

---

## Task 1: Remove `productStore.companyConfig` mutation from the migration (Codex PR #118 r11 P2 #1)

**Why:** `resolveIsMenuTenant` in `c2BareCartLineDump.ts` calls `useProductStore.setState({ companyConfig: config })` as a convenience side effect. The migration only needs the boolean module check; the global mutation is a code smell that races mid-flight company switches (a stale dispatch from company A could overwrite company B's freshly-fetched config). The cleanest fix is to remove the mutation entirely — productStore is hydrated by its own `fetchProducts` / `fetchCompanyConfig` flow.

**Files:**
- Modify: `apps/pos/src/lib/migration/c2BareCartLineDump.ts:114-132` (`resolveIsMenuTenant`)
- Modify: `apps/pos/src/lib/migration/__tests__/c2BareCartLineDump.test.ts` (if any test asserts on productStore being mutated)

**Pre-flight (L9 ingress audit):** Every consumer of `productStore.companyConfig` that might rely on the migration's seeding side effect. Grep `companyConfig` in the codebase. The migration should NOT be a productStore writer.

- [ ] **Step 1: Branch + audit consumers.**

  ```bash
  cd /Users/houssamr/Projects/syneriva/apps/erp-pos-stabperf
  git checkout -b feat/pos-c2-migration-followup dev
  git pull --ff-only
  grep -rn "productStore.*companyConfig\|companyConfig" apps/pos/src --include="*.ts" --include="*.tsx" | head -20
  ```
  Confirm that productStore's own actions (`fetchProducts`, `refreshCompanyConfig` in `authStore`, etc.) are the only writers. If anything else writes through the migration's side effect, refactor before changing the migration.

- [ ] **Step 2: Write the failing test.** Add to `c2BareCartLineDump.test.ts`:

  ```typescript
  // apps/pos/src/lib/migration/__tests__/c2BareCartLineDump.test.ts
  // Codex PR #118 r11 P2 #1 — the migration MUST NOT mutate
  // productStore.companyConfig. The boolean module check uses the
  // fetched config locally and discards it.
  it('Codex r11 P2 #1: does not mutate productStore.companyConfig as a side effect of resolveIsMenuTenant', async () => {
    const { useProductStore } = await import('@/stores/productStore');
    useProductStore.setState({ companyConfig: null } as never);

    // Use the dispatcher with a stubbed fetchCompanyConfig so we can
    // assert productStore is NOT touched.
    // ... drive runC2BareCartLineDump through the resolveIsMenuTenant
    // path (mock fetchCompanyConfig via vi.mock or by injecting a custom
    // isMenuTenant resolver).

    expect(useProductStore.getState().companyConfig).toBeNull();
  });
  ```
  Note: the existing test harness mocks `fetchCompanyConfig` via the `isMenuTenant` injection. The cleanest assertion path is to mock the dynamic import or to test that the migration's success path no longer calls `useProductStore.setState`.

- [ ] **Step 3: Run the failing test.**

  ```bash
  cd apps/pos && pnpm test src/lib/migration/__tests__/c2BareCartLineDump.test.ts
  ```
  Expected: FAIL.

- [ ] **Step 4: Remove the mutation.** In `c2BareCartLineDump.ts`:

  ```typescript
  async function resolveIsMenuTenant(companyId?: string): Promise<boolean | null> {
    try {
      const config = await fetchCompanyConfig();
      // r11 P2 #1: do NOT seed productStore.companyConfig here. The
      // migration is a read-only consumer of the module list; productStore
      // is hydrated by its own fetchProducts / authStore.refreshCompanyConfig
      // flow. Mutating from this background path could race a mid-flight
      // company switch and clobber the post-switch slot with the pre-
      // switch config.
      if (companyId) {
        await setStoredValue(companyConfigCacheKey(companyId), config);
      }
      return hasModule(config, 'Menu');
    } catch (error) {
      if (companyId) {
        const cached = await getStoredValue<CompanyConfig>(companyConfigCacheKey(companyId));
        if (cached !== null) {
          // r11 P2 #1: same — no productStore write here either.
          return hasModule(cached, 'Menu');
        }
      }
      console.warn('[c2BareCartLineDump] deferred: company config unavailable', error);
      return null;
    }
  }
  ```

- [ ] **Step 5: Run tests.** Expected: PASS.

- [ ] **Step 6: Commit.**

  ```bash
  git add apps/pos/src/lib/migration/c2BareCartLineDump.ts \
          apps/pos/src/lib/migration/__tests__/c2BareCartLineDump.test.ts
  git commit -m "fix(pos): remove productStore.companyConfig mutation from C2 migration (PR #118 r11 P2 #1)"
  ```

**Acceptance criteria:** Migration's `resolveIsMenuTenant` does NOT call `useProductStore.setState`. Existing tests still pass; new regression test asserts the no-mutation contract.

---

## Task 2: Scope the banner persistence key per (company, terminal) (Codex PR #118 r11 P2 #2)

**Why:** `StorageKeys.C2_MIGRATION_BANNER` is a single global key. PR #118 r6 closure made the migration's completion state per-terminal, but the banner persistence stayed global. On a multi-company-per-device deployment, company A's banner flag would leak to company B on next boot — cashier sees a banner that references a dump from a different company. Edge case but real.

**Files:**
- Modify: `apps/pos/src/lib/storage.ts` (the `C2_MIGRATION_BANNER` key + introduce a key builder)
- Modify: `apps/pos/src/stores/c2MigrationBannerStore.ts` (hydrate/show/dismiss take a `(companyId, terminalId)` scope)
- Modify: `apps/pos/src/lib/migration/c2BareCartLineDump.ts` (pass the scope when calling `setBannerPending`)
- Modify: `apps/pos/src/App.tsx` (pass the scope when calling `useC2MigrationBannerStore.getState().show()`)
- Modify: `apps/pos/src/components/C2MigrationBanner.tsx` (the banner reads the active scope from auth/terminal stores; consumes `visible` for the active scope only)
- Modify: `apps/pos/src/components/__tests__/C2MigrationBanner.test.tsx`
- Modify: `apps/pos/src/lib/migration/__tests__/c2BareCartLineDump.test.ts`

**Pre-flight (L9 ingress audit + L8 ownership):**
1. Banner write surfaces: migration's `setBannerPending(true)` + Zustand `show()`.
2. Banner read surfaces: `hydrate()` on banner mount + `visible` selector.
3. Banner clear surface: `dismiss()`.
4. The Zustand store's `visible` is in-memory; under multi-scope, it must distinguish "is the current (company, terminal) banner pending" vs. "is ANY banner pending". Simplest shape: `visibleScope: { companyId: string; terminalId: string } | null` — banner only renders if its current scope matches.
5. The persisted storage key becomes `c2_migration_banner:${companyId}:${terminalId}`.
6. Migration call site at `c2BareCartLineDump.ts` already has `companyId` + `terminalId` — pass them to `setBannerPending`.

- [ ] **Step 1: Update `storage.ts`.**

  ```typescript
  // apps/pos/src/lib/storage.ts
  export const StorageKeys = {
    // ...existing keys...
    // r11 P2 #2: removed the static C2_MIGRATION_BANNER key. Use
    // c2MigrationBannerKey(companyId, terminalId) instead so banners
    // do not leak across (company, terminal) pairs on multi-tenant
    // devices.
  } as const;

  export function c2MigrationBannerKey(companyId: string, terminalId: string): string {
    return `c2_migration_banner:${companyId}:${terminalId}`;
  }
  ```

- [ ] **Step 2: Update the Zustand banner store.**

  ```typescript
  // apps/pos/src/stores/c2MigrationBannerStore.ts
  import { create } from 'zustand';
  import { c2MigrationBannerKey, getStoredValue, setStoredValue } from '@/lib/storage';

  interface BannerScope {
    companyId: string;
    terminalId: string;
  }

  interface C2MigrationBannerState {
    /**
     * The (company, terminal) scope for which the banner is currently
     * visible. Null if no banner is pending. The banner component reads
     * the active scope from auth+terminal stores and renders only when
     * `visibleScope` matches — so a stale banner from a previous
     * (company, terminal) does not show after a switch.
     */
    visibleScope: BannerScope | null;
    hydrate: (scope: BannerScope) => Promise<void>;
    show: (scope: BannerScope) => void;
    dismiss: (scope: BannerScope) => Promise<void>;
  }

  export const useC2MigrationBannerStore = create<C2MigrationBannerState>()((set) => ({
    visibleScope: null,

    hydrate: async (scope) => {
      const pending = await getStoredValue<boolean>(c2MigrationBannerKey(scope.companyId, scope.terminalId));
      if (pending === true) {
        set({ visibleScope: scope });
      }
    },

    show: (scope) => {
      set({ visibleScope: scope });
    },

    dismiss: async (scope) => {
      await setStoredValue(c2MigrationBannerKey(scope.companyId, scope.terminalId), false);
      set((state) =>
        state.visibleScope &&
        state.visibleScope.companyId === scope.companyId &&
        state.visibleScope.terminalId === scope.terminalId
          ? { visibleScope: null }
          : state,
      );
    },
  }));
  ```

- [ ] **Step 3: Update the migration's `setBannerPending` default.**

  ```typescript
  // c2BareCartLineDump.ts
  // The default helper now uses the (companyId, terminalId)-scoped key.
  const setBannerPending = options.setBannerPending
    ?? (async (pending: boolean) => {
      if (!options.companyId || !options.terminalId) {
        // Defensive: tests injecting their own setBannerPending can omit
        // both ids. Production paths always pass them.
        return;
      }
      await setStoredValue(
        c2MigrationBannerKey(options.companyId, options.terminalId),
        pending,
      );
    });
  ```

- [ ] **Step 4: Update App.tsx's `show()` call.**

  ```typescript
  // App.tsx, inside the .then(result) handler:
  if (result.dumpedIds.length > 0) {
    // ...existing reconcile...
    useC2MigrationBannerStore.getState().show({
      companyId: dispatchedCompanyId,
      terminalId: dispatchedTerminalId,
    });
  }
  ```

- [ ] **Step 5: Update `C2MigrationBanner.tsx`.**

  ```typescript
  // C2MigrationBanner.tsx
  import { useEffect } from 'react';
  import { AlertCircle, X } from 'lucide-react';
  import { useTranslation } from 'react-i18next';
  import { useAuthStore } from '@/stores/authStore';
  import { useTerminalStore } from '@/stores/terminalStore';
  import { useC2MigrationBannerStore } from '@/stores/c2MigrationBannerStore';

  export function C2MigrationBanner() {
    const { t } = useTranslation('common');
    const companyId = useAuthStore((s) => s.companyId);
    const terminalId = useTerminalStore((s) => s.terminal?.id ?? null);
    const visibleScope = useC2MigrationBannerStore((s) => s.visibleScope);
    const hydrate = useC2MigrationBannerStore((s) => s.hydrate);
    const dismiss = useC2MigrationBannerStore((s) => s.dismiss);

    useEffect(() => {
      if (companyId && terminalId) {
        void hydrate({ companyId, terminalId });
      }
    }, [companyId, terminalId, hydrate]);

    const isActiveScope =
      visibleScope !== null
      && companyId !== null
      && terminalId !== null
      && visibleScope.companyId === companyId
      && visibleScope.terminalId === terminalId;

    if (!isActiveScope) return null;

    return (
      <div /* ...same JSX as before... */>
        {/* dismiss handler passes the active scope */}
        <button onClick={() => void dismiss({ companyId: companyId!, terminalId: terminalId! })}>
          {t('c2Migration.dismiss')}
        </button>
      </div>
    );
  }
  ```

- [ ] **Step 6: Update the banner tests.** Mirror the existing 4-test shape but with the scoped store API. Add a regression test:

  ```typescript
  it('r11 P2 #2: does NOT render for a different (company, terminal) scope than the dispatched one', async () => {
    useAuthStore.setState({ companyId: 'company-a' } as never);
    useTerminalStore.setState({ terminal: { id: 'terminal-1' } } as never);

    act(() => {
      useC2MigrationBannerStore.getState().show({
        companyId: 'company-b',  // different from active
        terminalId: 'terminal-1',
      });
    });

    render(<C2MigrationBanner />);
    expect(screen.queryByTestId('c2-migration-banner')).toBeNull();
  });
  ```

- [ ] **Step 7: Update the migration test that asserts on `setBannerPending`** (the existing test passes `{ setBannerPending }` as an injected helper — it'll continue to pass without changes).

- [ ] **Step 8: Run tests.** Expected: PASS.

- [ ] **Step 9: Commit.**

  ```bash
  git add apps/pos/src/lib/storage.ts \
          apps/pos/src/stores/c2MigrationBannerStore.ts \
          apps/pos/src/lib/migration/c2BareCartLineDump.ts \
          apps/pos/src/App.tsx \
          apps/pos/src/components/C2MigrationBanner.tsx \
          apps/pos/src/components/__tests__/C2MigrationBanner.test.tsx \
          apps/pos/src/lib/migration/__tests__/c2BareCartLineDump.test.ts
  git commit -m "fix(pos): scope C2 migration banner per (company, terminal) (PR #118 r11 P2 #2)"
  ```

**Acceptance criteria:** Banner persistence key and Zustand visibility both keyed by (company, terminal) pair. Multi-company-per-device cannot leak the banner across companies. Migration's `setBannerPending` writes the scoped key. Banner only renders when the current auth+terminal active scope matches `visibleScope`. Tests cover the cross-scope isolation.

---

## Task 3: Verify migration v31 schema applies before C2 dispatch

**Why:** `getMigrationRanFromDb` runs `SELECT value FROM pos_migration_state WHERE key = $1`. If the v31 schema (which creates `pos_migration_state`) hasn't applied yet at the moment of the first C2 dispatch, the query throws "no such table" and the migration's catch path doesn't handle that case explicitly — it bubbles up and App.tsx's `.catch` runs, scheduling a 30s retry. The retry would also fail until the schema is applied.

**This is a verification task, not a code change**, unless the verification reveals an ordering bug. If found, the fix is a defensive try/catch returning `{ deferred: true }`.

**Files:**
- Modify (only if bug found): `apps/pos/src/lib/migration/c2BareCartLineDump.ts:139-156` (the two DB helpers)
- Inspect: `apps/pos/src/lib/db/migrations.ts` (v31 entry)
- Inspect: `apps/pos/src/lib/db/index.ts` (database setup + migration runner ordering)

**Pre-flight:**

- [ ] **Step 1: Trace the boot order.** Confirm that `getDatabase(companyId)` in `runC2BareCartLineDump` triggers the schema migration runner BEFORE returning the db handle. Look for:

  ```bash
  grep -rn "runMigrations\|applyMigrations\|MIGRATIONS\[\]\|migrate\b" apps/pos/src/lib/db --include="*.ts"
  ```

  Most Tauri SQLite codebases run migrations as part of `getDatabase`'s lazy init. If that's the case here, the v31 table will exist by the time `getMigrationRanFromDb` runs — verification complete, no fix needed.

- [ ] **Step 2: Read the migration v31 test** at `apps/pos/src/lib/db/__tests__/migrations.v31.test.ts`. Verify it sets up an empty DB, runs the migration, and the `pos_migration_state` table exists.

- [ ] **Step 3: If ordering is correct, document the finding inline.** Add a one-line comment at the top of `runC2BareCartLineDump`:

  ```typescript
  // Migration ordering: getDatabase(companyId) applies all pending
  // schema migrations (including v31's pos_migration_state table)
  // before returning the handle, so getMigrationRanFromDb cannot race
  // a missing-table state. Verified 2026-05-11.
  ```

- [ ] **Step 4: If ordering is BROKEN (unlikely), add a defensive try/catch.**

  ```typescript
  async function getMigrationRanFromDb(db: Database, key: string): Promise<boolean> {
    try {
      const row = await queryOne<{ value: string }>(
        db,
        'SELECT value FROM pos_migration_state WHERE key = $1',
        [key],
      );
      return row?.value === 'true';
    } catch (error) {
      // Defensive: if the v31 schema hasn't applied yet, treat as not-ran.
      // The dispatch effect's 30s retry will pick it up after the schema
      // catches up.
      console.warn('[c2BareCartLineDump] pos_migration_state unreachable, treating as not-ran', error);
      return false;
    }
  }
  ```

  And mirror the catch in `setMigrationRanInDb` (returning silently rather than throwing).

- [ ] **Step 5: Commit (either way — documentation OR defensive code).**

  ```bash
  git add apps/pos/src/lib/migration/c2BareCartLineDump.ts
  git commit -m "docs(pos): document C2 migration v31 schema ordering (PR #118 r11 follow-up)"
  # OR if a defensive fix was needed:
  # git commit -m "fix(pos): defensive try/catch around pos_migration_state queries"
  ```

**Acceptance criteria:** Either (a) ordering is verified and a comment documents it, or (b) defensive try/catch is in place. Either outcome is acceptable.

---

## Final preflight + PR

- [ ] **Run full preflight battery.**

  ```bash
  cd /Users/houssamr/Projects/syneriva/apps/erp-pos-stabperf/apps/pos
  pnpm typecheck
  pnpm lint
  pnpm test
  ```

  Expected:
  - typecheck: 0 errors
  - lint: 0 errors / 41 warnings (baseline preserved)
  - tests: all pass, +3-5 new tests across the three tasks

- [ ] **Push branch + open PR.**

  ```bash
  git push -u origin feat/pos-c2-migration-followup
  gh pr create --base dev --title "fix(pos): C2 cart-line migration — r11 follow-ups + v31 ordering verification" \
    --body "$(cat <<'EOF'
  ## Summary

  Closes the three residual concerns from PR #118's eleven-round review trail. See `docs/superpowers/plans/2026-05-11-pos-c2-migration-followup-handoff.md` for the full spec.

  ## Pre-flight downstream audit

  [Enumerate every ingress site touched by each task — L9 discipline.]

  ## What shipped

  ### Task 1 — productStore.companyConfig mutation removed
  [Brief.]

  ### Task 2 — banner persistence scoped per (company, terminal)
  [Brief.]

  ### Task 3 — v31 schema ordering verified
  [Brief — verification outcome.]

  ## Test plan

  - [x] typecheck 0 errors
  - [x] lint 41 warnings (baseline preserved)
  - [x] tests +N (regression guards for r11 P2 #1 + #2 + cross-scope isolation)
  - [ ] Manual smoke (audit phase): single-device single-company-single-terminal flow unchanged.

  🤖 Generated with [Claude Code](https://claude.com/claude-code)
  EOF
  )"
  ```

- [ ] **Codex review.** Iterate until APPROVE or STOP-3.

  ```bash
  codex review --base dev --title "PR #N: C2 migration follow-ups"
  ```

- [ ] **Save review trail** to `docs/superpowers/reviews/2026-05-1X-pos-c2-migration-followup-codex-rounds.md`.

- [ ] **Hand to Opus for pre-merge review.** Same rule as PR #118 — destructive migration adjacencies warrant the final Opus pass before merging.

---

## Done criteria

- All three tasks merged in a single PR to `dev`.
- C2 cluster fully closed at the production level.
- PR #92 body updated with the follow-up PR # and SHA.

After this, POS is production-ready end-to-end. The roadmap's remaining items (T2.1 paginated catalog warmup, virtualization, image caching, the design/UX polish workstream) are all out of scope per the production-readiness handoff plan.

---

## Out of scope

- **30s deferred-retry observability** — adding a counter/log for "this terminal has retried N times" is a nice-to-have for production telemetry but does not affect correctness. Defer to the performance/observability workstream.
- **Exponential backoff on the retry** — same as above; defer.
- **Folding the migration's company-config cache key into `authStore.refreshCompanyConfig`** — minor refactor; defer.

---

## Cross-references

- PR #118 review trail (the trail this PR closes): `docs/superpowers/reviews/2026-05-11-pos-c2-bare-cart-migration-codex-rounds.md`
- Item 8 spec: `docs/superpowers/plans/2026-05-11-pos-production-readiness-handoff.md` §Item 8
- C2 kickoff (Risk #3 spec): `docs/superpowers/plans/2026-05-10-pos-c2-menu-tenant-desync-kickoff.md`
