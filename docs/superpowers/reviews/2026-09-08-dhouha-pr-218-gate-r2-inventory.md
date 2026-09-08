# Gate r2 — PR #218 "liste des comptages : le filtre affiché ment (status=active hors vocabulaire)"

- Reviewer: `inventory-costing-reviewer` (adversarial, code-grounded), targeted re-gate after fix round 1
- Date: 2026-09-08
- Checkout: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-218` (branch `gate/pr-218`, HEAD `b2545a454`)
- Fix commits reviewed: `8ba4ddf66` (test), `7727b6092` (manifest note), `b2545a454` (i18n) on top of `09927c932`; base `origin/dev` `cdedc2830`
- Diff read: `git diff 09927c932..b2545a454` — 5 files, +93/-8. Nothing outside the three findings was touched.
- r1 records: `2026-09-08-dhouha-pr-218-gate-r1-inventory.md`, `2026-09-08-dhouha-pr-218-gate-r1-frontend.md`

## VERDICT: MERGE-WITH-FIXES

All three assigned findings are genuinely closed and proven, not asserted. The single remaining item is **documentation, not code**: r1 MAJOR-1 was neither implemented nor declared — the PR body still carries the claim "parité carte↔liste **exacte**", which is false for card 3 of 4. That claim must be corrected (and the follow-up filed) before merge; no source change is required.

---

## 1. MAJOR-2 (second-company scoping) — CLOSED, and proven live

`apps/api/tests/Feature/Inventory/CountingIndexStatusFilterTest.php:257-323` — `test_second_company_rows_never_leak_on_any_branch`.

Every element the fix directive asked for is present and real:
- **Second company in the SAME tenant**: `:259-269` creates `companyB` with `'tenant_id' => $this->tenant->id` (the same tenant built at `:50-55`). Not a second tenant — the weaker, correct fixture for a company-scope pin.
- **Acting user member of both**: `:271-275` inserts a `UserCompanyMembership` for `$this->adminUser` on `companyB`, beside the company-A membership at `:82-86`. So membership cannot be what excludes B; only `CompanyContext` (set to A at `:88`) can.
- **B rows match every branch**: `$bActiveOverdue` (`:285-289`, `Count1InProgress` + `scheduled_end = now()->subDay()`) matches `active` AND `overdue` AND the unfiltered list; `$bFinalized` (`:290`) matches `status=finalized` and the unfiltered list. The `makeCounting` helper was widened with an optional `?Company $company` (`:91-97`) — default unchanged, so the seven pre-existing tests are behaviourally untouched.
- **Absence asserted on every branch**: the loop at `:293-307` covers `status=active`, `overdue=true`, `status=overdue`, `status=finalized`, `status=bogus`, and the empty query — i.e. all three `if/elseif` branches of `InventoryCountingController::index()` (`:241-250`) plus the tolerated-unknown fall-through. Each assertion carries a per-query message.
- **Positive controls**: `:310-315`, one per branch, so a scope so tight it returns nothing cannot pass.
- **Fixture-existence control**: `:319-322` asserts `InventoryCounting::forCompany($companyB->id)->count() === 2` — kills the "the rows were never created" false green.

**Ran it (this file only, from the worktree's `apps/api`):**
```
./vendor/bin/phpunit tests/Feature/Inventory/CountingIndexStatusFilterTest.php
OK (8 tests, 59 assertions)     # r1 was 7 tests / 28 assertions
```

**Liveness mutation (performed and reverted).** Replaced `InventoryCounting::forCompany($companyId)` with `InventoryCounting::query()` at `app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:226`, then ran the new test alone:
```
1) …::test_second_company_rows_never_leak_on_any_branch
company B leaked on 'status=active'
Failed asserting that an array does not contain '01a07f9f-…'.
…CountingIndexStatusFilterTest.php:305
```
Restored with `git checkout --` and re-verified `:226` reads `InventoryCounting::forCompany($companyId)`; `git status --porcelain` is clean. The test is a real tripwire, not decoration.

`./vendor/bin/pint --test` on the file: `{"result":"pass"}`. PHPStan does not cover it (`apps/api/phpstan.neon:6-7` analyses `app/` only) — same as every other test in the repo, not a regression.

Informational (no action): the loop omits the combined `status=finalized&overdue=true` shape, but that resolves to the same first branch already covered by `overdue=true`, so coverage is complete in mechanism terms.

## 2. MINOR-1 (manifest raise note) — CLOSED

`apps/api/tests/feature-lane-manifest.json:837` (`lanes.Inventory.raise_note_2026_09_07_pr218`) and `:1052` (`gated_ceiling_raise_note_2026_09_07_pr218`).

The fabricated vocabulary is gone. The note now reads: "`status=active` alias resolving to the three `count_N_in_progress` statuses via scopeActive(); `overdue=true` / `status=overdue` = active AND past scheduled_end, with overdue winning precedence over an exact status; exact `CountingStatus` values filtering verbatim; unknown values tolerated -> unfiltered list; plus a second-company pin…".

Checked term by term against the code, not against the note's own claims:
- `count_1_in_progress` / `count_2_in_progress` / `count_3_in_progress` are the real enum values (`app/Modules/Inventory/Domain/Enums/CountingStatus.php:11,13,15`) and exactly the trio in `scopeActive` (`app/Modules/Inventory/Domain/InventoryCounting.php:196-202`).
- "overdue winning precedence" matches `InventoryCountingController.php:241` (`$overdueRequested || $statusInput === 'overdue'` is the FIRST branch) and is pinned at test `:205-223`.
- "unknown tolerated → unfiltered" matches the absent `else` at `:248-250` and test `:236-247`.
- "second-company pin" now exists (test `:257`). No claim in the note is unbacked.

JSON validity + arithmetic + checker, run in the worktree:
```
python3 -c "json.load(open('tests/feature-lane-manifest.json'))"   # parses; gated_ceiling 1249, debt_ceiling 1
php tools/feature-lane-manifest-check.php
tests/Feature lane manifest OK — 1513 Feature classes in 74 groups; …
  ⚠ PARKED …: 70 group(s) / 1249 class(es)
EXIT=0
```
The fix round changed **only the two note strings** (`git diff 09927c932..b2545a454 -- tests/feature-lane-manifest.json`): no ceiling, no `classes`, no lane disposition moved. Nothing was weakened to buy the green.

## 3. FE MAJOR-1 (raw i18n keys on the repaired control) — CLOSED

- `apps/web/src/locales/fr/inventory.json` / `en/inventory.json` / `ar/inventory.json` each gained exactly two **top-level** keys: `allStatuses` and `actionsLabel` (fr "Tous les statuts"/"Actions", en "All statuses"/"Actions", ar "كل الحالات"/"الإجراءات"). All three parse as valid JSON.
- **Key-set delta computed against `09927c932`** (flattened, per locale): `added ['actionsLabel','allStatuses'] / removed [] / changed []` in fr, en and ar. No existing key was moved, renamed or re-valued.
- The consumer reads exactly those names under the `inventory` namespace: `apps/web/src/features/inventory-counting/pages/CountingListPage.tsx:58` binds `useTranslation('inventory')`; `:40` `statusOptionLabelKey` returns the literal `'allStatuses'` for the `all` option, rendered at `:148` (`t(statusOptionLabelKey(status))` inside the `STATUS_OPTIONS.map`); `:215` renders `t('actionsLabel')`. Top-level key + bound namespace ⇒ resolves without needing the absent `fallbackNS` (`apps/web/src/lib/i18n.ts:494-495` sets `fallbackLng: 'en'`, `defaultNS: 'common'`, no `fallbackNS`).
- **No new i18n gap**: `pnpm audit:i18n:local` still reports exactly one gap, `ar|sales|missing|documents.dueDateBeforeIssue` — the pre-existing FE r1 M2 item, untouched by this PR (`sales.json` is not in the diff). The two new keys are at full fr/en/ar parity, so they add nothing to the audit.
- Vitest not re-run: the FE tests mock `react-i18next` to echo keys (`CountingListPageStatusFilter.test.tsx:10-14` per FE r1), so a locale-only addition cannot move 79/79 in either direction. No reason to doubt the reported result.

**New (small, pre-existing, NOT introduced here) — MINOR-1r2.** The same defect class survives twice on the same page: `CountingListPage.tsx:174` `t('loading')` and `:265` `t('view')` also resolve to nothing in `inventory.json` (both exist in `common.json`, unreachable without `fallbackNS`). Both are present verbatim on base `cdedc2830` (`:138` and `:229` there), so this is not a regression and not in the r1 finding's literal scope — but the fix closed the two keys that were named rather than the defect class on the page it was already editing. One-line follow-up, do not block on it.

## 4. r1 MAJOR-1 ("Terminés ce mois" → all-time finalized) — STILL OPEN AND STILL UNDECLARED

Re-verified at HEAD: the counter is month-scoped (`InventoryCountingController.php:181-185`: `where('status', Finalized)` + `whereNotNull('finalized_at')` + `whereMonth('finalized_at', now()->month)`), while the card's drill-down is `href="/inventory/counting/list?status=finalized"` (`apps/web/src/features/inventory-counting/pages/CountingDashboardPage.tsx:109-115`), which the new code resolves to every finalized counting ever (`:248-250`).

Nothing records the deferral:
- The fix round touched no docs and no PR body (`git diff 09927c932..b2545a454 --stat` = 5 files, none of them markdown).
- `gh pr view 218` — body `updatedAt 2026-09-07T13:21:20Z`, i.e. before the fix round. Its "Signalements HORS SCOPE" list has four entries and **none is this one**, while the Fix section still asserts "**miroir byte-for-byte** du compteur dashboard" and the review section reports the inventory gate as "**MERGE**" (r1 was MERGE-WITH-FIXES). The body also still says "7 tests / 28 assertions"; it is now 8 / 59.
- `grep -rn "finalized_this_month" docs apps` → no match anywhere in the repo.

**Follow-up ticket text to file (verbatim, for the orchestrator):**

> **[inventory-counting] Dashboard card "Terminés ce mois" drills down to an all-time finalized list**
> The `completed_this_month` counter in `apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:181-185` counts `status = finalized AND finalized_at IS NOT NULL AND MONTH(finalized_at) = current month`, but its card links to `?status=finalized` (`apps/web/src/features/inventory-counting/pages/CountingDashboardPage.tsx:109-115`), which `index()` resolves at `:248-250` to every finalized counting ever recorded. A card reading "3" therefore opens a list of N — the same displayed-vs-applied deception PR #218 fixed for the other three cards; #218 deliberately left this one (declared out of scope at gate r2, 2026-09-08).
> Fix: add a `finalized_this_month` alias branch in `index()` mirroring the counter's three predicates at `:182-184`, point the card's `href` at it, add the alias to `STATUS_OPTIONS`/`resolveStatusFromParams` in `CountingListPage.tsx:19-55` with fr/en/ar labels, and extend `tests/Feature/Inventory/CountingIndexStatusFilterTest.php` with (a) a finalized-last-month row excluded, (b) a finalized-this-month row included, (c) the existing second-company absence loop covering the new branch. Acceptance: for every one of the four dashboard cards, `list(card.href).total == card.value` on the same fixture.
> Origin: gate r1 MAJOR-1, `docs/superpowers/reviews/2026-09-08-dhouha-pr-218-gate-r1-inventory.md`. Severity: Major (wrong drill-down, no data at rest affected). Not a regression — base behaves identically.

---

## Regression sweep on the fix round (nothing new introduced)

- **Production source untouched.** The three commits change one test file, one manifest, three locale JSONs. Zero PHP under `app/`, zero `.tsx`. The controller at `:222-263` is byte-identical to `09927c932` (verified after reverting my mutation).
- **Precision contract (rule 19): not engaged.** No money, no quantity, no `bcformat`, no scale resolution, no `(float)`/`parseFloat` in any added line. The only numeric literal added is `assertSame(2, …)` (a row count).
- **Stock/costing paths: untouched.** No stock movement, WAC, batch/FEFO, opening-balance or lock-order code is reachable from this diff.
- **Second-of-everything (conventions/09):** `inventory_countings` is a transactional document, not a catalogue table (`CATALOGUE_TABLES` in `tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php`), so the mandatory trio does not apply; the applicable rule 1 ("a list in company B never returns company A's row") is now pinned. No unique key, no migration, no schema change in the diff.
- **One surface per concept (conventions/11):** unchanged by the fix round. r1 MINOR-2 (the `'active'`/`'overdue'` alias vocabulary living in both `InventoryCountingController.php:242,246` and the FE `CountingStatusFilter` union) and FE r1 m2 (the hand-rolled `CountingStatus` shadowing the generated DTO) remain open follow-ups, correctly out of this fix round's scope.
- **Data-meaning tests:** the added test asserts row IDs returned per branch and a fixture row count — meaning, not status codes. Correct shape.

## Findings

1. `[Important] apps/web/src/features/inventory-counting/pages/CountingDashboardPage.tsx:109-115` + `apps/api/…/InventoryCountingController.php:181-185,248-250` — the "Terminés ce mois" card still drills down to all-time finalized, and the PR body's "parité carte↔liste **exacte**" claim (and the four-item HORS SCOPE list that omits it) is therefore false as written — a reviewer or tester reading the body will believe all four cards were verified — fix: paste the ticket text above into a new issue and add one HORS SCOPE bullet to the PR body linking it; while editing, correct "7 tests / 28 assertions" → "8 / 59" and the inventory gate verdict "MERGE" → "MERGE-WITH-FIXES (r1) / MERGE-WITH-FIXES (r2)".
2. `[Minor] apps/web/src/features/inventory-counting/pages/CountingListPage.tsx:174,265` — `t('loading')` and `t('view')` still render raw keys (present in `common.json`, unreachable: no `fallbackNS` at `apps/web/src/lib/i18n.ts:494-495`); pre-existing on base `cdedc2830:138,229`, same defect class as the two keys just fixed — fix: add `loading` and `view` to `src/locales/{fr,en,ar}/inventory.json` in the same follow-up that carries FE r1 m1-m7.
3. `[Minor, informational] apps/api/tests/Feature/Inventory/CountingIndexStatusFilterTest.php:293-300` — the leak loop omits the combined `status=finalized&overdue=true` shape; it exercises the same first branch as `overdue=true` (`InventoryCountingController.php:241`), so coverage is complete in mechanism terms — no action required.
4. `[Carried, unchanged] r1 MINOR-2/-3/-4 and FE r1 m1-m7` — alias vocabulary duplicated across layers, unknown filter values swallowed with no `meta.applied_status`, and the pre-existing `ilike`/unvalidated `sort_by` at `InventoryCountingController.php:254,258-260`. All correctly untouched by this fix round; file as follow-ups.

## What to fix before merge
Nothing in code — add the "Terminés ce mois" bullet + ticket link to the PR body (and correct its now-stale test count and gate verdict), then merge; FE r1 M2 (`ar/sales.json`) still needs to land first for a green web lint, and re-run the flaky `pos-test` job.
