All gates re-run independently. Register below.

---

# M0b adversarial review — round 2

**Range:** `d682b38ec9761a917b9716428091a482745795f6..87cbda211` (8 commits). Code diff = **17 files, all `*.test.ts(x)`/`tools/__tests__/*.mjs`; zero production files** (`git diff --name-only`). Non-code: 4 docs (brief, handback, progress YAML, round-1 register + owner ruling).

**Lenses.** `frontend-conventions` — applied (query-key helpers, canonical mocks, permission fixtures, i18n). `general` — applied. **Not applicable, stated explicitly:** Rule 19 money/quantity in *changed* code (no production code touched; one pre-existing `parseFloat` recorded below), constructor injection / `app()`, migrations, Horizon queue coverage, en+fr strings (no user-facing string added), backend tenancy middleware — the diff contains no PHP, no migration, no route.

**Red-first inversion.** For a tests-only milestone the evidence is *failing-at-pinned-base*: 16 of the 17 changed files appear in the recorded 19-file base inventory (`ui-wave0.progress.yaml:141`). See P3-5 for the 17th.

## Independent gate reproduction (I ran all four)

| Gate | Claim | My result |
|---|---|---|
| `pnpm test -- --maxWorkers=1` (665 files) | 4,224 passed / 5 failed / 1 skipped / 3 todo, failures only in the 3 exception files | **4,224 passed / 5 failed / 1 skipped / 3 todo — failing files exactly `tools/__tests__/offset-pagination-meta-consolidation.test.mjs`, `src/components/__tests__/SharedSingletons.tenantScope.test.tsx`, `src/__tests__/i18n/arLocaleCoverage.test.ts`. Exact match.** |
| Changed subset | "17 files and 168 tests" | **17 passed (17) / 168 passed (168). Exact match.** |
| `pnpm typecheck` | pass | exit 0 |
| `pnpm lint` | pass, 0 errors | exit 0 — 6,530 warnings/0 errors; TanStack keys 0 new/0 stale; design-system 738 acknowledged/0 new/0 stale; quantity 0/0/0 |

**Round-1 P1 is CLOSED.** The completion claim now reproduces byte-for-byte at the pinned command. Round-1 P2-2/P2-3 and P3-4…P3-10 are addressed (details in the residuals below where anything survives).

---

## Register

**1. [P2 — CONFIRMED] The deterministic gate command was fixed in the evidence, not in the exit criterion the later milestones actually read.**
`docs/handoff/progress/ui-wave0.progress.yaml:39` still states M0b's exit as "the remaining full apps/web **pnpm test** set green", and M8's gate item (5) at `:126` says "pnpm typecheck/lint/test green in apps/web". The `--maxWorkers=1` pin appears only in an evidence bullet (`:147`) and `HANDBACK…md:103`. Round-1 finding 1 offered two remedies — pin the concurrency **or** enumerate the four load-induced flakes (`ReviewIngestionPage.test.tsx`, `PartnerForm.test.tsx`, `PosHubPage.test.tsx`, `SupplierInvoiceDetailPage.test.tsx`); the executor did the first only in prose and the second not at all — `HANDBACK…md:105` still says "five additional timing/contention failures" without naming a file. **Failure scenario:** the M8 whole-branch gate is run as its own text specifies (`pnpm test`, default concurrency), returns 4–7 red files, and the executor/reviewer has no pinned command and no enumerated flake baseline to diff against — a genuine regression from T4's `canAccessModule` fail-closed change or T15's `/expenses` nav change is absorbed into "load flakes". One-line fix: write `--maxWorkers=1` into the M0b and M8 exit text. Per the harness's own severity ladder this is close-before-merge, not milestone-blocking.

**2. [P3 — CONFIRMED] Both non-Arabic exceptions still have no forward owner artifact — only an instruction to the parent.**
`HANDBACK…md:70,79` and `ui-wave0.progress.yaml:144-145` now correctly demote the closed lanes to "Historical lane" and add "Forward owner: the parent terminal audit must route this to a live production-fix lane before merge; it is not accepted as a standing gap." That is an honest disposition an autonomous executor can make, and it is durable (it lives in `findings:`, which a resume reads). It is not what round-1 finding 2 asked for (a live lane/ticket or explicit owner acceptance). **Residual scenario:** the terminal audit skips the findings list and two one-line production fixes — one of them a tenant-isolation defect — merge unowned.

**3. [P3 — CONFIRMED] M0b's authority record is executor-transcribed and it relaxed M0b's own exit criterion.**
`docs/handoff/reviews/ui-wave0/OWNER-RULING-2026-08-18-M0b.md` (new in `61d9dce8a`) transcribes owner messages the branch cannot corroborate. The relaxation is material: the M0b entry as first written at `3dcb31d72` said "record it as a finding and **STOP that item as blocked_review**" and "Exit requires **full** apps/web pnpm test green"; it now permits an enumerated exception list. **Verified clean, so this is a confirm-only note, not an integrity finding:** the brief blob at HEAD is byte-identical to `codex/ui-wave0-2026-08-11-pre-repin` (`cef4bc0cdf8e8ce3a43aaf8e04f75ebd02dea0aa` both sides), and `git diff pre-repin:…progress.yaml 3dcb31d72:…progress.yaml` changes only `base_sha`, the M0b insert, and the status/blockers reset — **M1–M8 acceptance text is untouched**. The parent is the only party that can confirm the transcription is faithful.

**4. [P3 — CONFIRMED] Resume state lags HEAD by one commit, structurally.**
`ui-wave0.progress.yaml:43,45` record `updated/commit: 61d9dce8a`, HEAD is `87cbda211` (the sync commit itself). Round-1 P3-4 (two commits behind) is improved; the residual is inherent to using a separate sync commit rather than amending. No action needed unless the parent wants the pointer amended into its own commit.

**5. [P3 — CONFIRMED] One changed file has no recorded red-at-base evidence, and is described as "deterministic" without a run to support it.**
`apps/web/src/features/partners/PartnerForm.test.tsx:329-331` was repaired in `3e87358c5`, but `PartnerForm.test.tsx` appears in **neither** recorded base inventory (run 1's 20 files nor the 19-file repeat, `ui-wave0.progress.yaml:141`). `HANDBACK…md:105` calls it "the one **deterministic** partner hydration race"; the only recorded observation of it failing is round-1's reviewer seeing it flake at 4 workers and pass in isolation — i.e. non-deterministic. The repair itself is sound (waiting on `getByLabelText(/^Name/)` hydration instead of the always-present button is a real race fix, and the subsequent `getByRole('button', {name:/add bank account/i})` still throws loudly if the button is absent). **Failure scenario:** the milestone's "every changed test was red at the pinned base" property is not provable from the branch for this file, and the word "deterministic" overstates the evidence.

**6. [P3 — CONFIRMED] Context mocks are shape-incomplete.**
`apps/web/src/features/treasury/treasury.test.tsx:37-42` and `apps/web/src/features/treasury/__tests__/TreasuryTenantScope.test.tsx:46-51` replace the whole `CompanyConfigContext` module with `{ config: { country_code: 'TN' } }`, omitting `isLoading`, `error` and `hasModule` from `CompanyConfigContextValue` (`apps/web/src/contexts/CompanyConfigContext.tsx:28-33`). Green today; a future component in either tree that calls `hasModule(...)` fails with a `TypeError` rather than a meaningful assertion. `vi.mock` factories are not type-checked, so `pnpm typecheck` will not catch it.

**7. [P3 — CONFIRMED, no action] POSPage still pins the storage-scale fallback, correctly labelled now.**
`apps/web/src/features/pos/pages/POSPage/POSPage.test.tsx:112-113` pins `'2.0000'`; the fixture (`:44-58`) carries no `quantity_decimals`, so `getQuantityDecimals` (`apps/web/src/lib/quantityScale.ts:9-16`) returns the scale-4 default. Round-1 P3-6 is closed — the comment now says "missing metadata uses four-decimal storage precision". Genuine unit-precision behaviour is covered independently at `apps/web/src/features/pos/molecules/CartLineItem/CartLineItem.test.tsx:53`, so no coverage is lost.

**8. [P3 — CONFIRMED, out of M0b scope, carried forward] Rule 19 violation co-located with exception 3.**
`apps/web/src/components/organisms/AddQuickProductModal/AddQuickProductModal.tsx:201,203` — `parseFloat(data.sale_price)` / `parseFloat(data.tax_rate)`. Pre-existing at the pinned base and untouchable under the tests-only constraint; now recorded in `HANDBACK…md:86` so the same visit that fixes line 209 fixes both.

---

## Exception classifications — all three independently CONFIRMED as real production defects

The owner ruling requires the bridge to verify every classification. I did, against code, not the handback:

1. **`arLocaleCoverage.test.ts`** — reproduced (3 failed / 7 passed); owner-ruled defect owned by `CODEX-DISPATCH-arabic-i18n-backfill-2026-08-10.md`. Correct: no test-only edit exists that does not mask the English fallback.
2. **`offset-pagination-meta-consolidation.test.mjs`** — `apps/web/src/features/treasury/statements/api.ts:111-113` declares `meta: { current_page; last_page; per_page; total }` inline instead of reusing the shared `OffsetPaginationMeta`. Real production drift; test is correct and non-vacuous.
3. **`SharedSingletons.tenantScope.test.tsx`** — `apps/web/src/components/organisms/AddQuickProductModal/AddQuickProductModal.tsx:209` calls `invalidateQueries({ queryKey: ['products'] })`. `locationScopedKey`/`tenantScopedKey` append tenant/company as **suffixes** (`apps/web/src/lib/locationScopedKey.ts:11-16`), so a bare `['products']` prefix matches every tenant's slot. Real cross-tenant invalidation defect; classification correct and the launch-program characterisation in `HANDBACK…md:88` is accurate.

None of the three invalidates T1–T15, so continuing to M1 is correct.

---

## Bypasses I tried that FAILED to find a defect

- **Smuggled production change:** `git diff --name-only base..HEAD` → 17 `*.test.*` files + 4 docs. Nothing else.
- **Deleted assertions / disabled tests:** 11 removed `expect(` vs 11 added; **0** removed `it(`/`test(`/`describe(`; **0** added `.skip`/`.only`/`.todo`.
- **Weakened permission fixture:** `usePermissions.expenseRecurrences.test.ts:11-17` inlines the sorted 6-role list that `permissionsMap.generated.ts:74` actually emits (spread order would not match), and the create/update/delete/export loop plus `not.toContain(viewOnlyRoles)` guard at `:19-29` survives intact.
- **Bogus role substitution:** `SupplierInvoiceListPage.test.tsx:288` swaps `['purchases']`→`['accountant']`. `'purchases'` occurs **0 times** in `permissionsMap.generated.ts` — it was never a role, so the old fixture was invalid, not the permission map. `'accountant'` does hold `document-ingestions.view` (`permissionsMap.generated.ts:61`).
- **Invented key/request shapes:** every changed expectation traced to production — `useExpenses.ts:54-58`, `useAgedPayables.ts:16`, `useAgedReceivables.ts:16`, `useCashPosition.ts:61-72`, `ZReportListPage.tsx:33,39` (and `ShiftHistoryPage.tsx:27` correctly still tenant-only), `useAnalytics.ts:40-50`, `finance/api.ts:218-224`, `useOwnerReports.ts:47-128`, `locationScopedKey.ts:11-16`. All match.
- **Mocks aimed at non-existent modules (silent no-ops):** `RebalancingView` (`StockByLocationPage.tsx:13`), `StatementReconciliationChips` (`ExpenseDetailPage.tsx:20`), `useTransactionLocations` (`CreateTransferDialog.tsx:9`), `useViewScope` (`useOwnerReports.ts:3`, `useExpenses.ts:6`) — every mock path matches a real import.
- **Reshaped cross-tenant decoys made vacuous (round-1 P3-7 fix):** `expensesInvalidationPredicate` matches on `k[0]`, `k[1]` and the last two positions only (`features/expenses/_invalidation.ts:16-30`) — segment-count agnostic, so the new `{location_ids}/{locScope}` decoy still exercises the tenant gate; a tenant-blind predicate still fails.
- **Relaxed call-count assertion (round-1 P3-5 fix):** `cashPositionAndMovementsTenantScope.test.tsx:107-114` captures `callsBeforeGatedRender` after unmount and asserts equality after the gated render — the "no fetch without tenant" property is preserved exactly; only the incidental "exactly 1 pre-gate call" is no longer pinned.
- **Brief / acceptance-criteria tampering:** brief blob identical to `…-pre-repin`; YAML diff vs pre-repin touches only `base_sha`, the M0b insert and the status reset.
- **Executor evidence inflation:** every headline number re-derived by me and matching (4,224/5/1/3; 17/168; typecheck 0; lint 0 errors, 0 new/0 stale on all three audits).

**Assessment.** Round-1's blocking P1 is closed and now reproduces exactly at the pinned command; all round-1 P2s and P3s are addressed or honestly dispositioned. One P2 remains (gate command not written into the durable exit text) — close-before-merge, not milestone-blocking under the harness's own ladder — plus seven P3 notes. Milestone scope held: zero production files, no assertion or test deleted, no test disabled, all three exception classifications independently verified against code. Set `M0b status: passed`, `fix_rounds: 2`, and carry finding 1 into M8.

VERDICT: ACCEPT
