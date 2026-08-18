# M0b adversarial review — round 1

**Range reviewed:** `d682b38ec9761a917b9716428091a482745795f6..ceadc5ee6` (5 commits, 17 test files + 3 docs files, **zero production files** — confirmed via `git diff --stat`).
**Lenses:** `frontend-conventions`, `general`. Backend-facing standing checks (migrations, Horizon queue coverage, constructor injection, scale resolver) **do not apply** — the diff touches no PHP and no migrations. Rule 19 applies only as a read-only observation (see P3-10).

---

## Register

**1. [P1 — CONFIRMED] Milestone exit criterion not met at HEAD; the completion claim does not reproduce.**
`docs/handoff/progress/ui-wave0.progress.yaml:146` and `docs/handoff/HANDBACK-ui-wave0-2026-08-11.md:96` state "4,224 passed, 5 failed … the only failing files are the three enumerated exception candidates." I ran the same documented command at HEAD:

```
npx vitest run --maxWorkers=4
 Test Files  7 failed | 658 passed (665)
      Tests  9 failed | 4220 passed | 1 skipped | 3 todo (4233)
```

Four files failed **beyond** the three enumerated exceptions: `src/features/document-ingestions/__tests__/ReviewIngestionPage.test.tsx`, `src/features/partners/PartnerForm.test.tsx`, `src/features/pos/pages/PosHubPage.test.tsx`, `src/features/purchases/supplier-invoices/SupplierInvoiceDetailPage.test.tsx`. I re-ran all four in isolation — **all 4 pass (45/45)** — and the full-run errors are `Test timed out in 5000ms` ×2 and two `Unable to find role="option"` async-population misses, so they are load-induced flakes, not defects. But the M0b entry's own exit wording is "the remaining full apps/web pnpm test set green," and it is not green; the flake set is nowhere enumerated (handback:98 mentions "five additional timing/contention failures" without naming a single file). **Failure scenario:** M1's gate runs the same command, sees N red files, and has no reviewed baseline to diff against — a genuine regression from T4's `canAccessModule` fail-closed change or T15's `/expenses` nav change lands inside an unnamed flake set and passes the gate. Since "later milestones inherit the same reviewed exceptions," the inherited set must name these four (or the gate command must be pinned to a concurrency at which the suite is deterministic).

**2. [P2 — CONFIRMED] Both non-Arabic exceptions are assigned to closed lanes, so neither defect has a forward owner.**
The M0b amendment requires "an enumerated exception list **with owning lanes**." `HANDBACK…md:73` assigns the pagination-meta regression to `CODEX-replenishment-followups-2026-07-12.md` Wave B — I read that document; it is a completed 2026-07-12 handover, not an open lane. `HANDBACK…md:84` assigns the tenant-invalidation regression to merged commit `cc1332fd5` ("sweep tenantScopedKey-wrapped invalidation filters to bare literal prefixes"). A merged commit is the *cause*, not an owner. **Failure scenario:** M0b closes, the wave merges, and both one-line production fixes are orphaned with no ticket, no lane, and no owner acceptance on record — the exception register becomes a permanent parking lot. Each needs either a live lane/ticket or an explicit owner acceptance-as-standing-gap.

**3. [P2 — CONFIRMED] The tenant-isolation exception is under-characterised, and its root cause is a specific, nameable miss in `cc1332fd5`.**
`apps/web/src/components/organisms/AddQuickProductModal/AddQuickProductModal.tsx:209` invalidates with the bare prefix `['products']`; because `tenantScopedKey` appends tenant/company as *suffixes*, this matches every tenant's slot. Reproduced: `SharedSingletons.tenantScope.test.tsx > scopes modal invalidations (.001-.003)` fails `expected true to be false` on the tenant-B slot. `cc1332fd5`'s own message records that it deliberately kept "4 sites … exact-key/tenant precision pinned by existing tenantScope tests" — AddQuickProductModal was not one of them, i.e. the codemod ran over a site that a tenantScope test had already pinned. **Failure scenario:** with `['products','tenant-B','company-2']` cached, a quick-product create under tenant-A marks tenant-B's slot invalidated; on tenant switch-back React Query refetches that slot under whichever session is then active and can repopulate a tenant-B-keyed entry from a different tenant's response. The handback describes this only as "would weaken the existing tenant boundary" (`:82`) — for a launch-program tenancy boundary the parent needs it flagged as a tenant-isolation defect with the `cc1332fd5` exception-list omission named, so the fix is the same one-line pattern the other 4 sites already use.

**4. [P3 — CONFIRMED] Resume state is stale relative to HEAD.**
`ui-wave0.progress.yaml:42,44` record `updated: 3e87358c5` / `commit: 3e87358c5…`, but HEAD is `ceadc5ee6` (two later M0b commits, `Phase 0.0b.4` among them). The brief's stated resume semantics are "a fresh session resumes from the YAML"; a crash here resumes two commits behind and would re-derive already-recorded evidence.

**5. [P3 — CONFIRMED] Uncited assertion relaxation.**
`apps/web/src/features/treasury/hooks/__tests__/cashPositionAndMovementsTenantScope.test.tsx:107,111` replaces `toHaveBeenCalledTimes(1)` with a captured `callsBeforeGatedRender`. The change is *justified* — `useViewScope` is unmocked here, so `useScopedLocations` issues its own call through the same `mockApiGet` — but it is the one corrected expectation in the whole diff carrying no lane-citation comment (the nearby comment covers only the key/request shape), and it now tolerates any future growth in pre-gate fetch count.

**6. [P3 — CONFIRMED] Comment misdescribes what the assertion pins.**
`apps/web/src/features/pos/pages/POSPage/POSPage.test.tsx:112-113` cites "quantities render at product precision" and pins `'2.0000'`. The fixture product carries no `quantity_decimals`, so `getQuantityDecimals` (`apps/web/src/lib/quantityScale.ts:9-16`) returns the storage-scale default of 4. The test therefore pins the *fallback*, not unit precision — a real piece-unit product renders `2`. The alignment to production is correct; the citation is not.

**7. [P3 — CONFIRMED] Cross-tenant decoy keys left in the legacy shape.**
`apps/web/src/features/expenses/__tests__/tenantScope.test.tsx:432` and `:450` still seed tenant-B as `['expenses','list',undefined,'tenant-B','company-1']` — a shape `useExpenses` can no longer produce (`useExpenses.ts:54-58` now emits `[…,{location_ids},{locScope},tenant,company]`). The tenant-A side was updated, the decoy was not. Both tests remain non-vacuous (verified: a wrong key yields `undefined`, which fails `toEqual([])`), but the isolation proof no longer mirrors production key shapes.

**8. [P3 — CONFIRMED] Evidence arithmetic excludes the one file it claims to have repaired.**
`HANDBACK…md:93` reports the repaired subset as "52 suites and 159 tests". The 17 changed files actually run **168** tests (I ran them: 17 passed / 168 passed). 159 is exactly 168 minus `PartnerForm.test.tsx`'s 9 tests — the file the same section describes as the repaired hydration race, and the file that flaked in my full run (finding 1).

**9. [P3 — CONFIRMED] M0b has no committed authorisation record.**
M0b appears nowhere in the r4 brief; it was inserted into the YAML by the executor citing an "Owner amendment 2026-08-18". `grep -rn "2026-08-18" docs/handoff/*.md` returns only the executor's own handback prose. Brief integrity itself is **clean** — HEAD's `CODEX-DISPATCH-ui-wave0-2026-08-11.md` is byte-identical to the copy on `codex/ui-wave0-2026-08-11-pre-repin`, so the executor did not reshape its own acceptance criteria. Also note the commit series `Phase 0.0b.<seq>` deviates from `AGENTS.md`'s numeric `Phase <major.minor.patch>`; authorised in the M0b entry, recorded here only for the terminal audit.

**10. [P3 — CONFIRMED, out of M0b scope] Rule 19 violation adjacent to exception 3.**
`AddQuickProductModal.tsx:201,203` uses `parseFloat(data.sale_price)` and `parseFloat(data.tax_rate)`. Pre-existing at base and untouchable under M0b's tests-only constraint — flagged so whoever eventually fixes finding 3 fixes both in the same visit.

---

## Bypasses attempted that FAILED to find a defect

- **Smuggled production change:** all 17 code files are `*.test.ts(x)` or under `__tests__/`; the only non-test files are 3 docs. Clean.
- **Invented expectations:** every changed key/request assertion verified against production — `locationScopedKey.ts:11-19`, `useExpenses.ts:54-72`, `useCashPosition.ts:61-72`, `ZReportListPage.tsx:29-39` (and `ShiftHistoryPage.tsx:27` correctly left un-scoped), `useAnalytics.ts:41-126`, `finance/api.ts:220-225`, `useOwnerReports.ts:47-86`. All match; the old assertions are provably unsatisfiable.
- **Weakened permission fixtures:** `permissionsMap.generated.ts:61` does grant `document-ingestions.view` to `accountant`; `:74` matches the sorted list; the `fullRoles`-only loop over create/update/delete/export plus the `not.toContain` guard survives intact (`usePermissions.expenseRecurrences.test.ts:18-28`).
- **Broken invalidation predicate under the new key shape:** `features/expenses/_invalidation.ts` matches on `k[0]`, `k[1]` and the last two positions — segment-count agnostic, so the inserted `{locScope}` cannot break it.
- **Child-component stubs hiding untested code:** `RebalancingView.test.tsx` and `StatementReconciliationChips.test.tsx` both exist; no assertions were deleted, only mocks added.
- **`.only` / `.skip` smuggled in:** none in the diff.
- **Exception 2 overstated:** the AST scan reports exactly one production duplicate — `features/treasury/statements/api.ts:112` — matching the handback verbatim.
- **Independent gates:** `pnpm typecheck` passes; `pnpm lint` exits 0 (0 errors, 6530 pre-existing warnings; TanStack keys 0 new/0 stale, design-system 738 acknowledged/0 new/0 stale, quantity audit 0/0/0).

Finding 1 blocks: the milestone's own stated exit condition is unsatisfied at HEAD and its evidence does not reproduce.

VERDICT: CHANGES-REQUIRED
