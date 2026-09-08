# [inventory-counting] Dashboard card "Terminés ce mois" drills down to an all-time finalized list

**Filed:** 2026-09-08 · **Origin:** PR #218 gate r1 MAJOR-1 (`docs/superpowers/reviews/2026-09-08-dhouha-pr-218-gate-r1-inventory.md`), declared out of scope at gate r2 · **Severity:** Major (wrong drill-down, no data at rest affected) · **Regression:** no — base behaves identically.

The `completed_this_month` counter in `apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:181-185` counts `status = finalized AND finalized_at IS NOT NULL AND MONTH(finalized_at) = current month`, but its card links to `?status=finalized` (`apps/web/src/features/inventory-counting/pages/CountingDashboardPage.tsx:109-115`), which `index()` resolves at `:248-250` to every finalized counting ever recorded. A card reading "3" therefore opens a list of N — the same displayed-vs-applied deception PR #218 fixed for the other three cards; #218 deliberately left this one.

## Fix

Add a `finalized_this_month` alias branch in `index()` mirroring the counter's three predicates at `:182-184`, point the card's `href` at it, add the alias to `STATUS_OPTIONS` / `resolveStatusFromParams` in `CountingListPage.tsx:19-55` with fr/en/ar labels, and extend `tests/Feature/Inventory/CountingIndexStatusFilterTest.php` with (a) a finalized-last-month row excluded, (b) a finalized-this-month row included, (c) the existing second-company absence loop covering the new branch.

**Acceptance:** for every one of the four dashboard cards, `list(card.href).total == card.value` on the same fixture.

## Carried minors from the same gates (same follow-up lane)

- `CountingListPage.tsx:174,265` — `t('loading')` / `t('view')` render raw keys under the `inventory` namespace (pre-existing; same class as the `allStatuses`/`actionsLabel` fix in #218 fix round 1).
- Alias vocabulary (`active`/`overdue`) duplicated between `InventoryCountingController.php:242,246` and the FE `CountingStatusFilter` union; hand-rolled `CountingStatus` in `types.ts:16-27` shadows the generated DTO (conventions/11).
- Unknown filter values swallowed silently (no `meta.applied_status`); pre-existing PG-only `ilike` and unvalidated `sort_by`/`sort_dir` at controller `:254,258-260`.
- Back/Forward desync of URL vs shown filter (state seeded once, pushing `setSearchParams`).
