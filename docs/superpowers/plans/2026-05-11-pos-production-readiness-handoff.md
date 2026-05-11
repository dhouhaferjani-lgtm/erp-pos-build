# POS Production-Readiness Handoff Plan (Pre-Performance)

> **For Codex (implementer) + Opus (reviewer):** Each numbered item below is an independent PR landed on `dev`. Codex implements one item per branch, opens a PR to `dev`, and runs `codex review --base dev` to drive its own review rounds. Opus reviews each PR after Codex's last round and either merges or requests changes. Steps use checkbox (`- [ ]`) syntax for tracking — mark done as you go.

**Goal:** Land every remaining codeable POS stabilization + hardening item so the application is production-ready. Performance work (pagination, virtualization, image caching) and design/UX polish are explicitly OUT of scope and will be a separate workstream that begins after this plan completes.

**Architecture:** 8 independent PRs to `dev`. No item depends on any other; they can ship in any order (suggested order below builds velocity from smallest to largest). Each PR follows the cadence already established for PRs #106-#110: branch off `dev`, TDD red anchor, implement, run quality gates (typecheck + lint baseline + tests), push, open PR, `codex review --base dev` until APPROVE or STOP-3, merge with `gh pr merge --squash --delete-branch`, save review trail to `docs/superpowers/reviews/`.

**Tech Stack:**
- `apps/pos`: React 19 / Vite 7 / TypeScript strict / Zustand 5 / Tauri 2 / Vitest. Commands: `pnpm typecheck`, `pnpm lint`, `pnpm test`. Lint baseline: **0 errors / 41 warnings — do not introduce new warnings.**
- `apps/api`: Laravel 12 / PHP 8.4 / PostgreSQL 16. Commands: `./vendor/bin/phpstan` (level 8), `./vendor/bin/pint`, `./vendor/bin/phpunit`.
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp-pos-stabperf`. **Never push to `main`; always merge to `dev` via PR.**

---

## Standing review disciplines (apply to every PR)

These are the lessons learned across PRs #106-#110. Codex should bake them into the PR body's pre-flight section BEFORE the first push to minimize multi-round iteration.

**L1 — Cross-tenant audit.** Before pushing any change that runs for all tenants, explicitly enumerate the tenant classes (Menu / standard-retail / hybrid / non-Menu) and verify each is handled correctly. *Origin:* PR #107 round-8 regression where `pullActiveMenu` reconcile wiped standard-retail catalogs.

**L8 — Cross-screen ownership boundaries.** When a new screen or surface takes ownership of a state transition, enumerate every OTHER screen that previously co-owned anything in that transition surface and decide whether the new owner replaces, complements, or races the old. *Origin:* PR #108 r1-r4 chain (BootstrapErrorScreen + CompanyRecoveryScreen + sign-out + dep-change effect).

**L9 — Ingress-site enumeration.** When introducing a new invariant on a data shape (canonicalization, comparison, serialization), enumerate every state-machine ingress that may operate on the pre-invariant shape: every `set({...})` site, every diff/merge function input, every cache read that feeds those sites, every wire boundary. *Origin:* PR #109 r1+r2+r4 (three different ingress sites missing canonicalization). Validated on PR #110 (single-round APPROVE — ingress audit upfront).

**Round budget (STOP-3).** Rounds 1-2 = routine. Rounds 3-5 = kickoff/audit under-spec. Round 6+ = structural rework OR a regression you introduced. **STOP-3 fires past round 5** — brief and surface; do not push past.

**PR body template.** Every PR body should open with a `## Pre-flight downstream audit` section enumerating the surfaces/ingresses touched and how each was handled. Items below pre-populate this audit so Codex can copy directly into the PR body.

---

## Items (suggested execution order — smallest to largest)

### Item 1: POS roadmap doc reconciliation (drift cleanup)

**Why:** `docs/superpowers/plans/2026-04-30-pos-roadmap.md` is the source-of-truth document the next session will read. Two of its claims are now stale: (a) T1.4 is marked 🟡 Pending but PR #92 records it as ✅ closed via PR #96 (+ defense-in-depth PR #102), and (b) the "DIRECT CONFLICT remains" warning about `productStore.ts` at lines 96 + 103 is stale because the POS performance work is now a sequential follow-up (no parallel session). Cleaning these now prevents future sessions from acting on incorrect status.

**Files:**
- Modify: `docs/superpowers/plans/2026-04-30-pos-roadmap.md`

**Pre-flight (L9 ingress audit):** doc-only change; no code ingress sites.

- [ ] **Step 1: Update the T1.4 row at line 64** from 🟡 Pending to ✅ Shipped with the PR references:
  ```markdown
  | T1.4 | Activation hardening Phase 2 (token 30d → 12mo) | `pos-activation-hardening.md` §Phase 2 | ✅ Shipped (PR #96 + #102 defense-in-depth) | T1.4 long token gated on POS triple-check; PR #102 narrowed POS-issued token abilities to `['pos:*']`. |
  ```

- [ ] **Step 2: Update the productStore hotspot row at line 96 + the prose at line 103** to reflect the C2 cluster having landed AND the POS performance work being a sequential follow-up, not a parallel session:
  ```markdown
  | `productStore.ts` | — | — | Cache + virtualizer | C2 Day 1-3 (PR #107/#109/#110) — composite IDs + canonicalize-before-diff invariant. Performance work is a SEQUENTIAL follow-up, not a parallel session. |
  ```
  And rewrite line 103:
  ```markdown
  **Hotspot (resolved):** `productStore.ts` — the C2 cluster reshaped this file (composite IDs, canonicalize-before-diff). The deferred paginated catalog warmup (T2.1) will build on the current state when the performance workstream begins. No active parallel sessions; the previous "DIRECT CONFLICT remains" caveat no longer applies.
  ```

- [ ] **Step 3: Add a row at the top of the "Already shipped" table for the C2 cluster + T2.4 closure** so the table reflects PRs #106-#110 landings:
  ```markdown
  | #106 + #108 | T2.4 | Bootstrap error handling state machine (Day 1 primitives + Day 2 AppRouter wiring) | ✅ Merged to dev |
  | #107 + #109 + #110 | C2 | Menu-tenant catalog desync (composite IDs + canonicalization + server-side menu_category_id) | ✅ Merged to dev |
  ```

- [ ] **Step 4: Commit and push** (no PR needed — this is doc-only and lands directly on a doc-cleanup branch):

  ```bash
  git checkout -b chore/pos-roadmap-reconciliation dev
  git add docs/superpowers/plans/2026-04-30-pos-roadmap.md
  git commit -m "docs(pos): reconcile roadmap with PR #96/#102/#106-#110 landings"
  git push -u origin chore/pos-roadmap-reconciliation
  gh pr create --base dev --title "docs(pos): reconcile roadmap" --body "Source-of-truth cleanup — T1.4 ✅, productStore hotspot resolved, C2 + T2.4 rows added to the shipped table."
  ```

- [ ] **Step 5: Codex review + merge.** Single round expected (doc-only).

**Acceptance criteria:** Roadmap doc reads correctly — no stale "🟡 Pending" rows for shipped work, no stale "DIRECT CONFLICT" warning, C2 + T2.4 closure reflected in the shipped table.

**Expected Codex rounds:** 1.

---

### Item 2: T1.6 — TND smoke test (currency precision)

**Why:** The roadmap (`pos-roadmap.md:66`) flags T1.6 as a pending one-shot manual smoke + 1 unit test for Tunisian dinar (TND) currency precision (3 decimal places, vs EUR's 2). Currency precision pitfalls are well-documented in this codebase (see memory: `project_monetary_precision.md`). The T0.1 plan §Currency precision called for explicit TND verification before go-live to a TN tenant. ~30 minutes.

**Files:**
- Modify or create: `apps/pos/src/lib/__tests__/currencyPrecision.test.ts` (or extend an existing currency test file — check `apps/pos/src/lib/` first)
- No production code change expected; this is a regression guard for the existing `CurrencyScale` helper.

**Pre-flight (L9 ingress audit):** test-only; no production ingress sites.

- [ ] **Step 1: Branch + locate the currency helper.**

  ```bash
  git checkout -b test/pos-tnd-smoke dev
  find apps/pos/src/lib -iname "*currency*" -o -iname "*money*" -o -iname "*scale*" | head -5
  ```

- [ ] **Step 2: Read the existing currency helper and any existing TND-related tests.** Identify the `CurrencyScale` (or equivalent) helper and any test that already covers EUR (2-decimal). TND uses 3 decimals. The new tests mirror the existing EUR shape but assert 3-decimal precision.

- [ ] **Step 3: Write the failing test.** A representative shape (adapt to the actual helper API discovered in step 2):

  ```typescript
  // apps/pos/src/lib/__tests__/currencyPrecision.test.ts
  import { describe, it, expect } from 'vitest';
  import { CurrencyScale } from '@/lib/currencyScale'; // adjust import

  describe('TND currency precision (T1.6 smoke)', () => {
    it('formats with 3 decimal places', () => {
      expect(CurrencyScale.bcformat('1.5', 3)).toBe('1.500');
      expect(CurrencyScale.bcformat('1.5005', 3)).toBe('1.500'); // truncates, does not round
    });

    it('adds two TND values without precision loss', () => {
      const sum = CurrencyScale.bcadd('1.234', '5.678', 3);
      expect(sum).toBe('6.912');
    });

    it('subtracts TND values exactly (no float drift)', () => {
      const diff = CurrencyScale.bcsub('10.000', '0.001', 3);
      expect(diff).toBe('9.999');
    });

    it('multiplies quantity * unit_price at 3-decimal scale', () => {
      const lineTotal = CurrencyScale.bcmul('3', '0.999', 3);
      expect(lineTotal).toBe('2.997');
    });
  });
  ```

- [ ] **Step 4: Run and verify the test passes (the helper already supports arbitrary scale; this is a regression guard).**

  ```bash
  cd apps/pos && pnpm test src/lib/__tests__/currencyPrecision.test.ts
  ```
  Expected: PASS (4/4).

- [ ] **Step 5: Run full preflight battery.**

  ```bash
  cd apps/pos && pnpm typecheck && pnpm lint && pnpm test
  ```
  Expected: typecheck 0 errors, lint 0 errors / 41 warnings, tests all pass.

- [ ] **Step 6: Manual smoke (engineer signs off).** With a TN tenant seeded (or a quick swap of the EUR fixture currency to TND), ring up a 3-decimal line, verify the receipt total matches `quantity * unit_price` exactly with no rounding artifacts. Capture a screenshot or a one-line note in the PR body.

- [ ] **Step 7: Commit + PR.**

  ```bash
  git add apps/pos/src/lib/__tests__/currencyPrecision.test.ts
  git commit -m "test(pos): T1.6 TND currency precision regression guard"
  git push -u origin test/pos-tnd-smoke
  gh pr create --base dev --title "test(pos): T1.6 — TND currency precision smoke" \
    --body "$(cat <<'EOF'
  ## Summary

  T1.6 from POS roadmap. Adds 4 regression-guard tests for TND (3-decimal) currency precision using the existing `CurrencyScale` helper. The helper already supports arbitrary scale; these tests pin the contract so a future refactor or scale-default change surfaces immediately for TN-tenant launches.

  Manual smoke: rang up `3 * 0.999 TND` on a TND-currency fixture, receipt total matched `2.997` exactly. [screenshot or note]

  ## Test plan

  - [x] `pnpm typecheck` — 0 errors.
  - [x] `pnpm lint` — 0 errors / 41 warnings (baseline preserved).
  - [x] `pnpm test` — all pass.
  EOF
  )"
  ```

- [ ] **Step 8: Codex review + merge.** Single round expected.

**Acceptance criteria:** 4 tests asserting TND 3-decimal precision in `apps/pos`. Manual smoke confirmed in PR body. Lint baseline preserved.

**Expected Codex rounds:** 1.

---

### Item 3: `FullReceiptResponse` TS type completion

**Why:** Server returns `product_id`, `composite_item_id`, and `menu_category_id` (post-C2 Day 3) per receipt line via `$line->toArray()` in `ReceiptController::show`. The TS type at `apps/pos/src/types/receipt.ts:115` declares only display fields — refund-flow consumers downstream cannot type-check against those id fields. Adding them now unblocks the server-recall refund path before that consumer is written.

**Files:**
- Modify: `apps/pos/src/types/receipt.ts:115`

**Pre-flight (L9 ingress audit):** type-only change. Verify no existing consumer of `FullReceiptResponse.lines` would break by adding optional fields. The fields are additive + optional (`?:`), so this should be safe.

- [ ] **Step 1: Branch + audit consumers.**

  ```bash
  git checkout -b type/pos-receipt-line-ids dev
  grep -rn "FullReceiptResponse" apps/pos/src --include="*.ts" --include="*.tsx"
  ```

- [ ] **Step 2: Update the `FullReceiptResponse.lines` type.** Replace lines 115-127 of `apps/pos/src/types/receipt.ts` with:

  ```typescript
  lines: Array<{
    id: string;
    line_number: number;
    /**
     * C2 Day 3: bare sellable UUID. Composite IDs (Menu-tenant) are
     * unpacked at the wire boundary by `unpackCompositeIdsOnLines`;
     * `menu_category_id` below carries the category context.
     */
    product_id: string | null;
    composite_item_id: string | null;
    /**
     * C2 Day 3: Menu-tenant category context. Null for non-Menu
     * tenants and pre-C2 historical lines (graceful degradation).
     */
    menu_category_id: string | null;
    product_code: string;
    product_name: string;
    quantity: string;
    unit_price: string;
    line_total: string;
    tax_rate: string;
    tax_amount: string;
    discount_amount: string;
    modifiers: Array<{ name: string; price: string }> | null;
  }>;
  ```

- [ ] **Step 3: Run typecheck.** Expected: 0 errors. If any consumer breaks, it's because they spread `line` into a stricter shape — fix the consumer if so.

  ```bash
  cd apps/pos && pnpm typecheck
  ```

- [ ] **Step 4: Run full preflight battery.**

  ```bash
  cd apps/pos && pnpm lint && pnpm test
  ```

- [ ] **Step 5: Commit + PR.**

  ```bash
  git add apps/pos/src/types/receipt.ts
  git commit -m "type(pos): FullReceiptResponse line shape includes product_id + composite_item_id + menu_category_id (C2 Day 3 follow-up)"
  git push -u origin type/pos-receipt-line-ids
  gh pr create --base dev --title "type(pos): FullReceiptResponse line ids (C2 Day 3 follow-up)" \
    --body "$(cat <<'EOF'
  ## Summary

  Server returns `product_id`, `composite_item_id`, and the newly-added (C2 Day 3) `menu_category_id` per receipt line via `$line->toArray()` in `ReceiptController::show`. The TS type didn't declare these — adding them so the server-recall refund flow (when it lands) can type-check against the category-context restoration path without further drift.

  All fields nullable to match server semantics: non-Menu tenants populate `product_id` only; Menu tenants populate `product_id` (or `composite_item_id`) + `menu_category_id`; pre-C2 historical lines have `menu_category_id = null`.

  ## Test plan

  - [x] `pnpm typecheck` — 0 errors.
  - [x] `pnpm lint` — baseline preserved.
  - [x] `pnpm test` — all pass.
  EOF
  )"
  ```

- [ ] **Step 6: Codex review + merge.** Single round expected.

**Acceptance criteria:** Type compiles, all consumers continue to typecheck, lint + tests baseline preserved.

**Expected Codex rounds:** 1.

---

### Item 4: Optimistic +1 `pendingReceiptCount` on insert

**Why:** Anchor at `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:80` (one of the `// TODO(go-live-followup):` anchors from PR #91 Step 5.3 sweep). Currently `pendingReceiptCount` is read from SQLite via `syncStore.refreshFromSQLite` after each sync tick. When a cashier inserts an offline receipt, the count doesn't reflect the new row until the next refresh. The optimistic increment closes that gap — the SyncButton badge updates immediately after the sale completes.

**Files:**
- Modify: `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:80` (read the TODO comment for context)
- Modify: `apps/pos/src/stores/syncStore.ts` (add an `incrementPendingCount()` action if not present)
- Test: `apps/pos/src/stores/__tests__/syncStore.test.ts` or a focused new test

**Pre-flight (L9 ingress audit):**
1. Every `insertOfflineReceipt` call site (grep `insertOfflineReceipt`) — does each one need the optimistic increment, or only the cashier-facing one?
2. The `refreshFromSQLite` reconciliation path — after the next sync, the in-memory count should reset from SQLite (authoritative); the optimistic value must not drift past a real refresh.
3. Logout / reset paths — clear the in-memory count.

- [ ] **Step 1: Branch + read context.**

  ```bash
  git checkout -b feat/pos-optimistic-pending-count dev
  ```
  Read `offlineReceiptRepository.ts:75-95` (around the TODO) and `syncStore.ts` to understand the current `pendingReceiptCount` field and `refreshFromSQLite` action.

- [ ] **Step 2: Audit `insertOfflineReceipt` call sites.**

  ```bash
  grep -rn "insertOfflineReceipt" apps/pos/src --include="*.ts" --include="*.tsx"
  ```
  Document each site in the PR body — confirm each one should fire the optimistic increment.

- [ ] **Step 3: Write the failing test.**

  ```typescript
  // apps/pos/src/stores/__tests__/syncStore.test.ts (extend)
  it('T2.2 follow-up: pendingReceiptCount increments optimistically on insert', () => {
    useSyncStore.setState({ pendingReceiptCount: 0 } as never);
    useSyncStore.getState().incrementPendingCount();
    expect(useSyncStore.getState().pendingReceiptCount).toBe(1);
    useSyncStore.getState().incrementPendingCount();
    expect(useSyncStore.getState().pendingReceiptCount).toBe(2);
  });

  it('T2.2 follow-up: refreshFromSQLite resets pendingReceiptCount to the SQLite-authoritative value', async () => {
    useSyncStore.setState({ pendingReceiptCount: 5 } as never); // optimistic value
    // Mock SQLite to return 3 unsynced rows.
    // ... use the existing test harness's SQLite mock
    await useSyncStore.getState().refreshFromSQLite();
    expect(useSyncStore.getState().pendingReceiptCount).toBe(3);
  });
  ```

- [ ] **Step 4: Run the failing test.**

  ```bash
  cd apps/pos && pnpm test src/stores/__tests__/syncStore.test.ts
  ```
  Expected: FAIL — `incrementPendingCount` not defined.

- [ ] **Step 5: Implement.** Add `incrementPendingCount` to `syncStore.ts`:

  ```typescript
  // In SyncActions interface:
  incrementPendingCount: () => void;

  // In create() body:
  incrementPendingCount: () => {
    set((s) => ({ pendingReceiptCount: s.pendingReceiptCount + 1 }));
  },
  ```

  Then wire `insertOfflineReceipt` (in `offlineReceiptRepository.ts:80` area) to call it after the SQL insert succeeds. The TODO anchor block should be removed; reference T2.2 PR #91 Step 5.3 in the replacement comment.

- [ ] **Step 6: Run tests.**

  ```bash
  cd apps/pos && pnpm test
  ```
  Expected: all pass, +2 new tests.

- [ ] **Step 7: Run preflight.**

  ```bash
  cd apps/pos && pnpm typecheck && pnpm lint
  ```

- [ ] **Step 8: Commit + PR.**

  ```bash
  git add apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts \
          apps/pos/src/stores/syncStore.ts \
          apps/pos/src/stores/__tests__/syncStore.test.ts
  git commit -m "feat(pos): optimistic +1 pendingReceiptCount on offline-receipt insert (T2.2 anchor closure)"
  git push -u origin feat/pos-optimistic-pending-count
  gh pr create --base dev --title "feat(pos): optimistic pendingReceiptCount on insert" \
    --body "$(cat <<'EOF'
  ## Summary

  Closes the `TODO(go-live-followup):` anchor at `offlineReceiptRepository.ts:80` (PR #91 Step 5.3 sweep). The SyncButton badge now updates immediately after a sale completes instead of waiting for the next `refreshFromSQLite` tick.

  ## Pre-flight downstream audit

  - `insertOfflineReceipt` call sites: [list each call site from grep + confirm each should fire the increment].
  - `refreshFromSQLite` reconciliation: SQLite is authoritative — the optimistic value is overwritten on the next refresh, so drift is bounded by the sync tick interval.
  - Logout / `syncStore.reset()`: clears `pendingReceiptCount` to 0 via the existing reset path.

  ## Test plan

  - [x] +2 syncStore tests (optimistic increment; refreshFromSQLite reset).
  - [x] `pnpm typecheck` — 0 errors.
  - [x] `pnpm lint` — 41 warnings (baseline).
  - [x] `pnpm test` — all pass.
  EOF
  )"
  ```

- [ ] **Step 9: Codex review + merge.** Expect 1-2 rounds.

**Acceptance criteria:** SyncButton badge increments visibly within one render frame of a completed sale. Tests pass. TODO anchor at `offlineReceiptRepository.ts:80` removed.

**Expected Codex rounds:** 1-2.

---

### Item 5: `CompanyRecoveryScreen` fold into `BootstrapErrorScreen`

**Why:** T2.4 Day 2 (PR #108) deferred this fold as a "low-risk first cut" per the T2.4 kickoff. The two screens share the same failed-phase + retry + sign-out + use-cached-data pattern. Folding them removes ~80 lines of duplicate UI code and gives the cashier a consistent recovery UX across every bootstrap-time failure. Deferred at PR #108 to keep the diff small; landing now while the bootstrap surface is fresh in cache.

**Files:**
- Modify: `apps/pos/src/App.tsx` (remove `CompanyRecoveryScreen` function, simplify the `companies.length === 0` branch)
- Modify: `apps/pos/src/components/BootstrapErrorScreen.tsx` (handle the empty-companies case)
- Modify: `apps/pos/src/stores/bootstrapStore.ts` (the `fetching-companies` no-op phase may need to fire the recovery fetch when companies are empty, since CompanyRecoveryScreen no longer mounts to do it)
- Modify: `apps/pos/src/stores/authStore.ts:255` (`fetchCompanies` — verify behavior unchanged)
- Modify: `apps/pos/src/components/__tests__/BootstrapErrorScreen.test.tsx`
- Modify: `apps/pos/src/stores/__tests__/bootstrapStore.test.ts`

**Pre-flight (L8 cross-screen ownership + L9 ingress):**
1. **Ownership transfer.** Today `CompanyRecoveryScreen` owns the auto-fetch on mount + typed error banner. Post-fold, `BootstrapErrorScreen` owns it via the bootstrap state machine. The `fetching-companies` phase body is currently a no-op (PR #109 r3 fix); to make BootstrapErrorScreen drive the fetch, the phase needs to call `authStore.fetchCompanies()` when companies are empty AND surface failures back to the store.
2. **AppRouter render branches.** The `isAuthenticated && companies.length === 0` branch at `App.tsx:106-108` either (a) becomes a render branch that drives `bootstrapStore.start()` if not already running, or (b) is removed entirely if BootstrapErrorScreen's precedence handles it. Decide which.
3. **i18n keys.** Existing `auth.companyRecovery.*` keys can be removed; or kept as-is and BootstrapErrorScreen's `auth.bootstrap.phase.fetching-companies` becomes the canonical label. Decide.
4. **Tests.** Companion fold-aware tests on both bootstrapStore (fetching-companies phase now does work) and BootstrapErrorScreen (empty-companies branch).

- [ ] **Step 1: Branch + map the render-precedence change.**

  ```bash
  git checkout -b feat/pos-bootstrap-companyrecovery-fold dev
  ```

  Re-read `App.tsx:106-108` + `App.tsx:169-245` + `bootstrapStore.ts::fetching-companies` case (around line 157) + `BootstrapErrorScreen.tsx` to map the current ownership boundary.

- [ ] **Step 2: Write the failing test for `fetching-companies` actually fetching when companies are empty.**

  ```typescript
  // bootstrapStore.test.ts (extend)
  it('Fold: fetching-companies phase fetches when cache is empty (post CompanyRecoveryScreen removal)', async () => {
    mockedAuth = { ...mockedAuth, companies: [] };
    fetchCompanies.mockResolvedValue(undefined);

    const p = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await p;

    expect(fetchCompanies).toHaveBeenCalledTimes(1);
  });

  it('Fold: fetching-companies phase surfaces failures via bootstrap error state', async () => {
    mockedAuth = { ...mockedAuth, companies: [] };
    fetchCompanies.mockRejectedValue(
      Object.assign(new Error('companies down'), { name: 'ApiRequestError' }),
    );

    const p = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await p;

    expect(useBootstrapStore.getState().phase).toBe('error');
    expect(useBootstrapStore.getState().error?.phase).toBe('fetching-companies');
  });
  ```

  Note: this CHANGES the existing PR #109 r3 contract (fetching-companies no-op). The change is intentional: CompanyRecoveryScreen owned the fetch before; now bootstrapStore does. Update or remove the old "never calls fetchCompanies" test from `bootstrapStore.test.ts` accordingly.

- [ ] **Step 3: Run the failing tests.**

  ```bash
  cd apps/pos && pnpm test src/stores/__tests__/bootstrapStore.test.ts
  ```
  Expected: 2 new tests FAIL.

- [ ] **Step 4: Implement the phase change.** In `apps/pos/src/stores/bootstrapStore.ts` `runPhase('fetching-companies')`:

  ```typescript
  case 'fetching-companies':
    // Fold (post-PR #109 r3 reversal): CompanyRecoveryScreen folded into
    // BootstrapErrorScreen; bootstrap is now the sole owner of the empty-
    // companies fetch. The race that PR #109 r3 closed is also closed
    // here because there's no second fetcher anywhere — CompanyRecoveryScreen
    // is gone.
    if (useAuthStore.getState().companies.length > 0) {
      return;
    }
    await withTimeout(phase, useAuthStore.getState().fetchCompanies(), timeoutMs);
    return;
  ```

- [ ] **Step 5: Update the existing "never calls fetchCompanies" tests** in `bootstrapStore.test.ts` — change to "calls fetchCompanies only when cache is empty" (the PR #106 r6 P2 original contract).

- [ ] **Step 6: Add the empty-companies branch to `BootstrapErrorScreen`.** When `error.phase === 'fetching-companies'`, render a slightly different message ("No companies available — try refreshing or sign out") and the same Retry/Sign-out affordances. The Use-Cached-Data button isn't rendered because fetching-companies is non-recoverable (recoverability matrix from PR #106 r7).

- [ ] **Step 7: Remove `CompanyRecoveryScreen` from `App.tsx`.** Delete:
  - the entire `function CompanyRecoveryScreen() {...}` block (App.tsx:169-245)
  - the `companies.length === 0` render branch (App.tsx:106-108)

  AppRouter's existing flow then drives bootstrap → if fetching-companies fails, `bootstrapPhase === 'error'` → `<BootstrapErrorScreen />` precedence catches it.

- [ ] **Step 8: Update `App.tsx` imports** — remove `serializeErrorForLog` if no other consumer remains; keep `SAFE_ERROR_NAMES` (still imported elsewhere).

- [ ] **Step 9: Remove obsolete i18n keys** at `apps/pos/src/locales/en/common.json` and `fr/common.json` (the `auth.companyRecovery` block). Keep `auth.bootstrap` as the canonical recovery surface.

- [ ] **Step 10: Update BootstrapErrorScreen tests** for the new empty-companies branch and any phase-specific copy changes.

- [ ] **Step 11: Run full preflight.**

  ```bash
  cd apps/pos && pnpm typecheck && pnpm lint && pnpm test
  ```
  Expected: all gates green; test count UP by ~2 (new fold tests) and DOWN by however many tests targeted the deleted CompanyRecoveryScreen.

- [ ] **Step 12: Commit + PR.**

  ```bash
  git add apps/pos/src/App.tsx \
          apps/pos/src/components/BootstrapErrorScreen.tsx \
          apps/pos/src/components/__tests__/BootstrapErrorScreen.test.tsx \
          apps/pos/src/stores/bootstrapStore.ts \
          apps/pos/src/stores/__tests__/bootstrapStore.test.ts \
          apps/pos/src/locales/en/common.json \
          apps/pos/src/locales/fr/common.json
  git commit -m "refactor(pos): fold CompanyRecoveryScreen into BootstrapErrorScreen (T2.4 follow-up)"
  git push -u origin feat/pos-bootstrap-companyrecovery-fold
  gh pr create --base dev --title "refactor(pos): fold CompanyRecoveryScreen into BootstrapErrorScreen" \
    --body "[Include the L8 cross-screen ownership audit + L9 ingress-site audit in the PR body — both apply.]"
  ```

- [ ] **Step 13: Codex review + merge.** Expect 2-3 rounds — Codex will likely flag any subtle interaction between the bootstrap state machine's `fetching-companies` phase (now actively fetching again) and the dep-change effect's guards added in PR #108 r2/r4.

**Acceptance criteria:** CompanyRecoveryScreen no longer exists. Bootstrap state machine drives the empty-companies fetch and surfaces failures via BootstrapErrorScreen. All existing recovery flows still work (cold boot with empty companies, mid-shift companies revocation, retry, sign-out). Lint + test baselines preserved.

**Expected Codex rounds:** 2-3.

---

### Item 6: Per-phase `AbortController` for "Use cached data"

**Why:** Deferred from T2.4 Day 2 review trail (`docs/superpowers/reviews/2026-05-11-pos-t2.4-day2-codex-rounds.md` §Day 3 follow-ups). Today when the cashier clicks "Use cached data" on `BootstrapErrorScreen`, `skipWithCache()` runs `runFromPhase(nextPhase, ...)` — but the failed phase's in-flight init promise continues until it completes or times out (15s). The cashier sees the recovery proceed, but the abandoned init may still mutate stores (e.g., terminalStore.initialize writing to the in-memory snapshot). `withTimeout` already accepts an `AbortSignal`; the gap is that the existing `authStore.initialize` / `terminalStore.initialize` / `operatorStore.checkHasPins` don't accept signals.

**Files:**
- Modify: `apps/pos/src/stores/bootstrapStore.ts` (add per-phase `AbortController`, fire abort on `skipWithCache` and `reset`)
- Modify: `apps/pos/src/stores/authStore.ts` (`initialize` accepts `{ signal?: AbortSignal }` option; pipe through `checkSession` → `apiGet`)
- Modify: `apps/pos/src/stores/terminalStore.ts` (`initialize` accepts `{ signal? }`)
- Modify: `apps/pos/src/stores/operatorStore.ts` (`checkHasPins` accepts `{ signal? }`)
- Modify: `apps/pos/src/stores/__tests__/bootstrapStore.test.ts`
- Modify: `apps/pos/src/lib/bootstrap/__tests__/withTimeout.test.ts` (assert abort wiring end-to-end)

**Pre-flight (L9 ingress audit):**
1. Every `initialize` call site for the three stores — does adding an optional signal break any caller? (Optional → no.)
2. `apiGet`/`apiPost`/`fetchWithTimeout` — already accept `{ signal? }` per PR #84 (T0.3). Verify; otherwise add.
3. The pre-aborted-signal short-circuit (PR #106 r2 P2) and drain-on-pre-abort (PR #106 r3) still apply for the new phase-aborts.
4. `bootstrapStore.reset()` (the logout path) should abort any in-flight phase too — closes the residual race called out in PR #108 r5 P1's rationale.

- [ ] **Step 1: Branch + audit the three stores' `initialize` signatures.**

- [ ] **Step 2: Write the failing test.**

  ```typescript
  // bootstrapStore.test.ts (extend)
  it('Per-phase AbortController: skipWithCache aborts the failed phase\'s in-flight init', async () => {
    let abortFired = false;
    initializeTerminal.mockImplementation((opts?: { signal?: AbortSignal }) => {
      opts?.signal?.addEventListener('abort', () => { abortFired = true; });
      return new Promise(() => {}); // never resolves
    });

    const startPromise = useBootstrapStore.getState().start();
    // Advance to where fetching-terminal is in-flight, then trigger error.
    // ... use the existing test harness's timer advance pattern.

    // After phase errors and cashier clicks Use Cached Data:
    // Force the error state manually for the test, then call skipWithCache.
    // ... assert abortFired === true
  });
  ```

  Note: the test mechanics need to match the existing `bootstrapStore.test.ts` mock harness. Adapt as needed.

- [ ] **Step 3: Implement `bootstrapStore` AbortController wiring.**

  ```typescript
  // bootstrapStore.ts internal state
  let currentAbortController: AbortController | null = null;

  async function runPhase(phase: RunnablePhase, timeoutMs: number, signal: AbortSignal): Promise<void> {
    switch (phase) {
      case 'authenticating':
        await withTimeout(phase, useAuthStore.getState().initialize({ signal }), timeoutMs, signal);
        return;
      // ... etc
    }
  }

  async function runFromPhase(startPhase, retryCount, set, get) {
    currentAbortController = new AbortController();
    const signal = currentAbortController.signal;
    try {
      // ... existing loop, passing signal to runPhase
    } finally {
      currentAbortController = null;
    }
  }

  // skipWithCache + reset both abort the controller before continuing.
  ```

- [ ] **Step 4: Add optional `{ signal? }` to the three stores' `initialize` methods + `checkHasPins`.** Pipe through to `apiGet`/`apiPost` (already signal-aware per T0.3).

- [ ] **Step 5: Run tests.** Iterate until green.

- [ ] **Step 6: Run preflight.**

- [ ] **Step 7: Commit + PR.**

  ```bash
  git checkout -b feat/pos-bootstrap-abort-on-skip dev
  # ... commits ...
  gh pr create --base dev --title "feat(pos): per-phase AbortController for bootstrap skip-with-cache + reset"
  ```

- [ ] **Step 8: Codex review + merge.** Expect 2-3 rounds — Codex tends to flag concurrency invariants here (pre-aborted signal handling, double-abort, abort during retry race).

**Acceptance criteria:** Clicking "Use cached data" aborts the in-flight failed-phase promise; the abandoned init no longer mutates stores. `bootstrapStore.reset()` (logout) aborts in-flight phases. Existing tests still pass; +2-3 new tests for the abort wiring.

**Expected Codex rounds:** 2-3.

---

### Item 7: `MenuTenantMultiCategoryFixture` seeder (C2 audit-phase smoke)

**Why:** C2 Day 3 deferred this as audit-phase smoke. The fixture lets the audit phase + future regression tests verify the C2 end-to-end behavior: a sellable cross-listed in 2 categories produces two cart-addable rows with distinct composite ids, distinct category prices, and the receipt-line `menu_category_id` round-trips through sync correctly.

**Files:**
- Create: `apps/api/database/seeders/MenuTenantMultiCategoryFixture.php` (Laravel seeder)
- Modify: `apps/api/database/seeders/DatabaseSeeder.php` or equivalent to register the fixture (only if it should run as part of the default seed)
- Create: a Pest/PHPUnit test that consumes the fixture and asserts the structure (`apps/api/tests/Feature/POS/MenuTenantMultiCategoryFixtureTest.php`)

**Pre-flight (L1 cross-tenant audit):**
1. The fixture creates a Menu-tenant company with the `Menu` module enabled (so productStore takes the Menu fetch branch).
2. The fixture creates one sellable (e.g., "Coca") and two menu categories (e.g., "Drinks" + "Lunch combos") cross-listing the sellable with different prices.
3. The fixture verifies it does NOT pollute non-Menu tenant fixtures (e.g., DemoTenantSeeder, CoffeeShopSeeder, ParapharmacySeeder).

- [ ] **Step 1: Branch + study existing Menu-tenant seeders.**

  ```bash
  git checkout -b feat/api-menu-multi-category-fixture dev
  find apps/api/database/seeders -name "*Menu*" -o -name "*CoffeeShop*"
  grep -rn "menu_category_id\|MenuCategoryItem::create" apps/api/database/seeders | head -10
  ```

  Read `CoffeeShopSeeder.php` for the existing Menu-tenant pattern.

- [ ] **Step 2: Author the fixture.** Mirror `CoffeeShopSeeder` shape:

  ```php
  <?php
  // apps/api/database/seeders/MenuTenantMultiCategoryFixture.php

  declare(strict_types=1);

  namespace Database\Seeders;

  use App\Modules\Company\Domain\Company;
  use App\Modules\Menu\Domain\MenuCategory;
  use App\Modules\Menu\Domain\MenuCategoryItem;
  use App\Modules\Product\Domain\Product;
  use App\Modules\Tenant\Domain\Tenant;
  use Illuminate\Database\Seeder;

  /**
   * C2 Day 3 audit-phase smoke fixture — a Menu-tenant catalog with one
   * sellable ("Coca") cross-listed in two menu categories at different
   * prices ("Drinks" @ 3.00, "Lunch combos" @ 1.50). Used to verify
   * end-to-end:
   *  - productStore flatten emits two composite-id rows for the sellable.
   *  - cart addItem keeps the two rows as distinct lines.
   *  - sync wire payload carries menu_category_id per line.
   *  - pos_receipt_lines persists the menu_category_id column populated.
   *  - refund flow (when server-recall lands) can reconstruct the composite.
   */
  class MenuTenantMultiCategoryFixture extends Seeder
  {
      public function run(): void
      {
          $tenant = Tenant::factory()->create(['name' => 'C2 Menu Test Tenant']);
          $company = Company::factory()->for($tenant)->create([
              'name' => 'C2 Test Shop',
              'enabled_modules' => ['Menu'], // adjust to match the actual module-enable mechanism
          ]);

          $cola = Product::factory()->for($company)->create([
              'name' => 'Coca',
              'sku' => 'COCA-CAT2',
              'barcode' => '5449000000996',
          ]);

          $drinks = MenuCategory::factory()->for($company)->create(['name' => 'Drinks']);
          $combos = MenuCategory::factory()->for($company)->create(['name' => 'Lunch combos']);

          MenuCategoryItem::factory()->create([
              'menu_category_id' => $drinks->id,
              'sellable_type' => Product::class,
              'sellable_id' => $cola->id,
              'price' => '3.00',
          ]);

          MenuCategoryItem::factory()->create([
              'menu_category_id' => $combos->id,
              'sellable_type' => Product::class,
              'sellable_id' => $cola->id,
              'price' => '1.50',
          ]);
      }
  }
  ```

  Adjust field names + factories to match the actual codebase shape (verify against existing `CoffeeShopSeeder`).

- [ ] **Step 3: Write the failing test.**

  ```php
  <?php
  // apps/api/tests/Feature/POS/MenuTenantMultiCategoryFixtureTest.php

  use Database\Seeders\MenuTenantMultiCategoryFixture;
  use App\Modules\Menu\Domain\MenuCategoryItem;
  use Illuminate\Foundation\Testing\RefreshDatabase;

  uses(RefreshDatabase::class);

  it('creates one sellable cross-listed in two menu categories with distinct prices', function () {
      $this->seed(MenuTenantMultiCategoryFixture::class);

      $cocaLines = MenuCategoryItem::query()
          ->whereHas('sellable', fn ($q) => $q->where('name', 'Coca'))
          ->get();

      expect($cocaLines)->toHaveCount(2);
      expect($cocaLines->pluck('price')->sort()->values()->all())->toEqual(['1.50', '3.00']);
      expect($cocaLines->pluck('menu_category_id')->unique())->toHaveCount(2);
  });
  ```

- [ ] **Step 4: Run the test.**

  ```bash
  cd apps/api && ./vendor/bin/phpunit tests/Feature/POS/MenuTenantMultiCategoryFixtureTest.php
  ```
  Expected: PASS.

- [ ] **Step 5: Run backend preflight.**

  ```bash
  cd apps/api && ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse
  ```

- [ ] **Step 6: Commit + PR.**

  ```bash
  git add apps/api/database/seeders/MenuTenantMultiCategoryFixture.php \
          apps/api/tests/Feature/POS/MenuTenantMultiCategoryFixtureTest.php
  git commit -m "feat(api): MenuTenantMultiCategoryFixture seeder for C2 audit-phase smoke"
  git push -u origin feat/api-menu-multi-category-fixture
  gh pr create --base dev --title "feat(api): MenuTenantMultiCategoryFixture seeder (C2 audit smoke)"
  ```

- [ ] **Step 7: Codex review + merge.** Expect 1-2 rounds.

**Acceptance criteria:** Seeder runs cleanly via `php artisan db:seed --class=MenuTenantMultiCategoryFixture`. Test passes. No pollution of other tenants' fixtures.

**Expected Codex rounds:** 1-2.

---

### Item 8: In-flight cart-line migration for pre-C2 terminals (kickoff Risk #3)

**Why:** This is the highest-leverage remaining C2 item. Pre-C2 Menu-tenant terminals (production installs before PR #107 shipped) have cart lines persisted in `held_transactions` (and any other on-disk cart cache) with **bare `sellable_id` in `cartLine.product.id`**. Post-upgrade, the productStore returns **composite IDs** (`${sellable_id}_${menu_category_id}`). On boot, the existing cart-line lookup against `useProductStore.getState().getById(...)` will MISS because the held cart line's id is the bare sellable, not the composite. The kickoff (`docs/superpowers/plans/2026-05-10-pos-c2-menu-tenant-desync-kickoff.md` Risk #3) prescribes **dump-and-warn**: on boot, scan all on-disk carts, detect any bare-sellable cart line on a Menu tenant, drop the cart, and surface a one-time banner to the cashier explaining the in-progress order was lost (rare in practice; pre-launch the only affected terminals are dev installs).

**This is a one-shot, post-deploy migration. After it runs once per terminal, it never fires again.**

**Files:**
- Create: `apps/pos/src/lib/migration/c2BareCartLineDump.ts` (the migration logic)
- Modify: `apps/pos/src/App.tsx` or wherever the bootstrap state machine's `ready` transition lands (one-shot fire on app start, post-bootstrap)
- Modify: `apps/pos/src/lib/db/repositories/heldTransactionRepository.ts` (audit reads + bulk-delete helper)
- Create: a state mechanism for "migration already ran" so it doesn't fire repeatedly. Options:
  - SQLite migration version: bump v30 → v31, run the migration as part of the schema migration.
  - localStorage / Tauri Store flag: `c2_bare_cart_dump_ran: true`.
  - Recommend the SQLite version approach (atomic with schema; survives reset).
- Create: a UI banner component for the one-time warning (re-use `TrainingModeBanner` shape).
- Create: i18n keys for the banner.
- Test: `apps/pos/src/lib/migration/__tests__/c2BareCartLineDump.test.ts`

**Pre-flight (L1 cross-tenant audit + L8 ownership):**
1. **Tenant classes.** Migration MUST only fire for Menu tenants. Standard-retail tenants have bare-sellable `cartLine.product.id` by design and would be incorrectly dumped if the gate is wrong. Use the same `hasModule(config, 'Menu')` check used by productStore.
2. **Cart-line storage surfaces.** Audit:
   - `held_transactions` SQLite table (the obvious one)
   - Any in-memory Zustand cart state persisted via Tauri Store (`StorageKeys.CART` or similar — grep)
   - Any other surface that persists `cartLine.product.id` raw
3. **Detection rule.** A cart line is "pre-C2 bare" if BOTH (a) the tenant is Menu, AND (b) `cartLine.product.id` does NOT contain the C2 composite delimiter `_` between two UUID-shaped halves (or simpler: does NOT match the composite pattern `${UUID}_${UUID}`). Be precise — a malformed id should default to "keep" (false negative is better than data loss).
4. **Banner placement.** Banner component renders adjacent to `TrainingModeBanner` in the Header. Auto-dismiss after a cashier acknowledges (state in Tauri Store).
5. **L8 — co-owners of the cart state.** `cartStore` (in-memory) and `heldTransactionRepository` (SQLite) both touch cart-line state. Migration must run BEFORE any cart hydration from disk (so the cart is never re-populated with stale bare-id lines).
6. **L9 — every ingress that reads on-disk carts.** Grep `getAllHeldTransactions`, `loadHeldTransaction`, etc. — ensure none execute before the migration's dump completes.

- [ ] **Step 1: Branch + audit cart-storage surfaces.**

  ```bash
  git checkout -b feat/pos-c2-bare-cart-migration dev
  grep -rn "held_transactions\|heldTransaction\|CART\b" apps/pos/src --include="*.ts" --include="*.tsx" | head -25
  ```

- [ ] **Step 2: Decide on the "migration ran" persistence.** Recommend SQLite migration v31 that adds a row to a `pos_migrations` table (or a flag in an existing `metadata` table). Document the choice in the PR body.

- [ ] **Step 3: Write the failing tests.**

  ```typescript
  // apps/pos/src/lib/migration/__tests__/c2BareCartLineDump.test.ts
  import { describe, it, expect, beforeEach } from 'vitest';
  import { runC2BareCartLineDump } from '../c2BareCartLineDump';

  describe('C2 bare cart-line migration', () => {
    it('dumps held transactions whose lines carry bare sellable_id on a Menu tenant', async () => {
      // Setup: SQLite has 2 held_transactions:
      //   t1 — 1 line with bare-sellable product_id (pre-C2)
      //   t2 — 1 line with composite product_id (post-C2)
      // Tenant is Menu-mode.
      const result = await runC2BareCartLineDump({ isMenuTenant: true, /* mocks */ });

      expect(result.dumpedCount).toBe(1); // t1 dumped
      expect(result.kept).toEqual(['t2']);
      // The "migration ran" flag is set so a subsequent call is a no-op.
      expect(result.alreadyRan).toBe(false);

      const second = await runC2BareCartLineDump({ isMenuTenant: true, /* mocks */ });
      expect(second.alreadyRan).toBe(true);
      expect(second.dumpedCount).toBe(0);
    });

    it('is a no-op for non-Menu tenants (bare sellable_id is correct for them)', async () => {
      const result = await runC2BareCartLineDump({ isMenuTenant: false, /* mocks */ });
      expect(result.dumpedCount).toBe(0);
    });

    it('detects bare sellable_id only when the line is on a Menu tenant — composite ids and modifier-bearing lines are preserved', async () => {
      // Edge cases: ensure composite-id lines + lines with modifiers + lines
      // with composite_item_id (vs product_id) are NOT incorrectly dumped.
    });
  });
  ```

- [ ] **Step 4: Implement `runC2BareCartLineDump`.** The function:
  1. Reads the migration-ran flag from SQLite. If set, returns `{ alreadyRan: true, dumpedCount: 0 }`.
  2. Checks if the tenant is Menu (via the productStore config or the auth/company state).
  3. If non-Menu, sets the flag and returns `{ alreadyRan: false, dumpedCount: 0 }` (no work).
  4. If Menu, reads all held transactions, scans each line's `product.id`:
     - Composite pattern (`${UUID}_${UUID}` with `_` delimiter): keep.
     - Bare UUID without `_`: dump the transaction.
  5. Bulk-deletes the dumped transactions.
  6. Sets the migration-ran flag.
  7. Returns `{ alreadyRan: false, dumpedCount: N, kept: [...] }`.
  8. Triggers the one-time banner via a Tauri Store flag the banner reads.

- [ ] **Step 5: Create the banner component.** Mirror `TrainingModeBanner.tsx`:

  ```typescript
  // apps/pos/src/components/C2MigrationBanner.tsx
  import { useTranslation } from 'react-i18next';
  import { useState, useEffect } from 'react';
  import { AlertCircle } from 'lucide-react';
  import { getStoredValue, setStoredValue, StorageKeys } from '@/lib/storage';

  export function C2MigrationBanner() {
    const { t } = useTranslation('common');
    const [show, setShow] = useState(false);

    useEffect(() => {
      void (async () => {
        const pending = await getStoredValue<boolean>(StorageKeys.C2_MIGRATION_BANNER);
        if (pending === true) setShow(true);
      })();
    }, []);

    const dismiss = async () => {
      await setStoredValue(StorageKeys.C2_MIGRATION_BANNER, false);
      setShow(false);
    };

    if (!show) return null;

    return (
      <div role="status" /* ... copy + dismiss button ... */ >
        {t('c2Migration.banner')}
        <button onClick={dismiss}>{t('c2Migration.dismiss')}</button>
      </div>
    );
  }
  ```

- [ ] **Step 6: Wire into the bootstrap-ready flow.** In `App.tsx`, after `bootstrapPhase === 'ready'` and BEFORE any cart-hydration UI mounts, fire `runC2BareCartLineDump` once. Use a `useRef` guard to ensure it only fires once per mount even on dep-change re-renders.

  ```typescript
  // App.tsx::AppRouter (snippet)
  const migrationRanRef = useRef(false);
  useEffect(() => {
    if (bootstrapPhase !== 'ready' || migrationRanRef.current) return;
    migrationRanRef.current = true;
    void runC2BareCartLineDump({ /* deps */ });
  }, [bootstrapPhase]);
  ```

- [ ] **Step 7: Add i18n keys** to `apps/pos/src/locales/en/common.json` and `fr/common.json`:

  ```json
  "c2Migration": {
    "banner": "An in-progress order from before the last update was cleared. Please re-enter it if needed.",
    "dismiss": "Got it"
  }
  ```
  French translation: provide.

- [ ] **Step 8: Run tests + preflight.**

  ```bash
  cd apps/pos && pnpm typecheck && pnpm lint && pnpm test
  ```

- [ ] **Step 9: Manual smoke.** Before opening the PR:
  1. Spin up a Menu tenant locally; create a held transaction with a fake bare-id line via SQLite CLI.
  2. Reload the POS — confirm the held transaction is dumped and the banner appears.
  3. Dismiss the banner; reload — confirm it doesn't reappear and no more dump runs.
  4. Repeat on a non-Menu tenant — confirm no dump fires and no banner appears.

- [ ] **Step 10: Commit + PR.**

  ```bash
  git add apps/pos/src/lib/migration/c2BareCartLineDump.ts \
          apps/pos/src/lib/migration/__tests__/c2BareCartLineDump.test.ts \
          apps/pos/src/components/C2MigrationBanner.tsx \
          apps/pos/src/components/__tests__/C2MigrationBanner.test.tsx \
          apps/pos/src/App.tsx \
          apps/pos/src/lib/storage.ts \
          apps/pos/src/locales/en/common.json \
          apps/pos/src/locales/fr/common.json
          # plus the SQLite migration v31 file
  git commit -m "feat(pos): one-shot C2 bare cart-line migration (dump-and-warn for pre-C2 Menu terminals)"
  git push -u origin feat/pos-c2-bare-cart-migration
  gh pr create --base dev --title "feat(pos): C2 bare cart-line migration (kickoff Risk #3)" \
    --body "[Pre-flight audit per L1 + L8 + L9 sections of the handoff plan.]"
  ```

- [ ] **Step 11: Codex review + merge.** Expect 3-5 rounds — Codex will likely flag the L1 cross-tenant gate (Menu-only), the L9 ingress audit (every on-disk cart surface, ordering vs. cart hydration), and the idempotency guard. Apply STOP-3 past round 5.

**Acceptance criteria:** Pre-C2 held transactions on Menu tenants are dumped on first post-deploy boot; banner appears once; non-Menu tenants unaffected; migration is idempotent (second boot is a no-op); no cart-hydration path runs before the migration completes.

**Expected Codex rounds:** 3-5.

---

## Done criteria (whole plan)

- [ ] Items 1-8 above all merged to `dev`.
- [ ] PR #92 body updated with each closure (strikethrough + reference to the merging PR).
- [ ] The remaining `// TODO(go-live-followup):` anchors in `apps/pos/src` are either (a) closed by Item 4, or (b) explicitly tagged as "performance overhaul scope" or "design/UX scope" in the anchor comment.
- [ ] No new `// TODO(go-live-followup):` anchors introduced that aren't in this plan.
- [ ] Memory notes updated to reflect each closure (one note per shipped PR, indexed in `MEMORY.md`).

After this plan completes, the POS application is **production-ready** modulo performance work (pagination, virtualization, image caching — separate workstream) and design/UX polish (stranded-receipt admin UI, SyncButton state transition animations — separate workstream).

---

## Explicitly OUT of scope

The following are deferred to other workstreams and **must not be touched by this plan**:

1. **Performance work.** Pagination (T2.1 — Phase 3 paginated catalog warmup for 5000 SKUs), virtualization, image caching, memoization passes, SyncScheduler instantiation, background sync. Tracked in `project_pos_performance.md` memory note. New branch: `feat/pos-performance` (not yet created).
2. **Design / UX polish.** Stranded-receipt operator/admin UI, SyncButton state-transition animations, any other Tier 3 cosmetic items from `pos-roadmap.md:82-85`.
3. **Audit-phase gates.** T2.3 / Phase 6 release-gate items: preflight battery, Slow-3G scripted smoke, Graphify run, security audit, doc realignment. Audit phase owns these; the PR #92 un-draft trigger.
4. **Tenant-isolation sweep.** `feat/tenant-isolation-sweep-execution` branch — a parallel workstream that lands via §17 PR before PR #92 un-drafts.
5. **Per-merchant signed installer** (T3.3), **phone-tether wizard** (T3.1) — future enterprise / post-go-live items.

---

## Cross-references

- PR #92 (DRAFT release vehicle): https://github.com/otospexsolutions/erp/pull/92
- POS roadmap (source of truth for the shipping cadence): `docs/superpowers/plans/2026-04-30-pos-roadmap.md`
- T2.4 kickoff: `docs/superpowers/plans/2026-05-10-pos-t2.4-bootstrap-state-machine-kickoff.md`
- C2 kickoff: `docs/superpowers/plans/2026-05-10-pos-c2-menu-tenant-desync-kickoff.md`
- T2.4 Day 1 review trail (L1 origin): `docs/superpowers/reviews/2026-05-10-pos-t2.4-bootstrap-store-day1-codex-rounds.md`
- T2.4 Day 2 review trail (L8 origin): `docs/superpowers/reviews/2026-05-11-pos-t2.4-day2-codex-rounds.md`
- C2 Day 2 review trail (L9 origin): `docs/superpowers/reviews/2026-05-11-pos-c2-day2-codex-rounds.md`
- C2 Day 3 review trail (L9 validation): `docs/superpowers/reviews/2026-05-11-pos-c2-day3-codex-rounds.md`
- Phase 6 manual smoke checklist (audit-phase reference): `docs/superpowers/plans/2026-05-09-pos-phase-6-manual-smoke-checklist.md`
- POS performance overhaul (next workstream): memory note `project_pos_performance.md`

---

## Self-review notes (for Opus reviewing this plan)

1. **Spec coverage:** every item identified in the prior session brief is represented: T1.6 (Item 2), CompanyRecoveryScreen fold (Item 5), AbortController (Item 6), MenuTenantMultiCategoryFixture (Item 7), in-flight cart migration (Item 8), FullReceiptResponse TS type (Item 3), optimistic pendingReceiptCount (Item 4), roadmap reconciliation (Item 1). Other `// TODO(go-live-followup):` anchors (image preloading manifest, batch receipt push, full offline cold-start, stranded-receipt admin UI, SyncButton transitions) are explicitly out of scope per the OUT-OF-SCOPE section.
2. **Placeholder scan:** Item 7 contains "adjust to match the actual module-enable mechanism" — Codex should verify the actual `enabled_modules` field name from existing seeders before authoring. Item 8 contains "(or simpler: does NOT match the composite pattern `${UUID}_${UUID}`)" — Codex should commit to one detection strategy in the implementation.
3. **Type consistency:** the helper exports referenced (`canonicalizeMenuCatalog`, `parseMenuCompositeId`, `runC2BareCartLineDump`) and the standing review-discipline rules (L1, L8, L9, STOP-3) are referenced consistently throughout.
4. **Ordering:** items are ordered smallest-to-largest by Codex review-round expectation (1 → 3-5). Items 1-4 are bounded and fast; Items 5-6-7-8 are progressively larger. No item depends on another; they can ship in any order.
