# Adversarial Design Review: Per-Branch / Per-Establishment Program

**Reviewed spec:** `docs/superpowers/specs/2026-06-04-per-branch-program-design.md`  
**Compared against:** P0 tax-ID spec, research docs 06/07/08/09a/09b/09c, and current code in `apps/api`.  
**Posture:** adversarial. The spec is directionally right, but it is not implementation-ready.

## Verified Load-Bearing Claims

- `journal_entries.location_id` exists as nullable FK + `(company_id, location_id)` index: `apps/api/database/migrations/tenant/2025_12_27_150002_add_location_id_to_journal_entries_table.php:23-37`.
- `JournalEntry::$fillable` does not include `location_id`: `apps/api/app/Modules/Accounting/Domain/JournalEntry.php:49-67`.
- Current GL hash excludes `location_id`: `apps/api/app/Modules/Accounting/Application/Services/GeneralLedgerHashService.php:54-76`.
- Current GL report services do not filter/read `journal_entries.location_id`: trial balance joins entries but filters only status/date/company at `apps/api/app/Modules/Accounting/Application/Services/Reports/TrialBalanceService.php:182-193`; general ledger filters company/status/date/account/partner only at `apps/api/app/Modules/Accounting/Application/Services/Reports/GeneralLedgerReportService.php:204-220`.
- Core invoice/POS/COGS posting paths do not write `location_id`: invoice `apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:112-123`; credit note `apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:228-239`; COGS `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:848-857`; POS payment `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1232-1241`; POS account charge `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1306-1315`.
- WAC is company-wide today: `recordPurchase()` explicitly blends against company-owned quantity at `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:174-229`; sale reads `products.cost_price` at `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:355-394`; `products.cost_price` is a product column/cast, not location-scoped, at `apps/api/database/migrations/tenant/2026_05_30_000000_widen_wac_cost_columns_to_scale_6.php:39-43` and `apps/api/app/Modules/Product/Domain/Product.php:133-138`.

## Findings

### BLOCKER 1 - P2/P2.5 undercount the actual GL posting and purpose-resolution surface

The spec says P2 writes branch at "every posting site" and names `AccountingService` plus about four `GeneralLedgerService` sites (`docs/superpowers/specs/2026-06-04-per-branch-program-design.md:51-53`). That is not enough for either the ledger dimension or `findByPurposeForLocation()`. Current production code has many more `JournalEntry::create()` and `SystemAccountPurpose` resolution paths: manual journal entries at `apps/api/app/Modules/Accounting/Presentation/Controllers/JournalEntryController.php:88-105`; opening balances at `apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:74-89` and inventory opening at `apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php:325-337`; supplier/customer advances, payments, writeoffs, vouchers, expenses, POS tolerance, COGS, and inventory writeoff throughout `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:217-225`, `:285-294`, `:423-432`, `:505-514`, `:574-583`, `:652-661`, `:747-756`, `:956-965`, `:1168-1177`, `:1412-1421`, `:1482-1491`.

The purpose resolver surface is also broader than the spec implies: `GeneralLedgerService::getAccountByPurpose()` is used across the full posting surface at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:63-65`, `:414-415`, `:836-837`, `:969-970`, `:1227`, `:1290-1297`, `:1385-1406`, `:1472-1473`; `AccountingService` has a separate duplicate resolver at `apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:126-140` and `:331-352`; `InventoryOpeningService` calls `Account::findByPurposeOrFail()` directly at `apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php:321-323`; `PartnerBalanceService` and `ChartOfAccountsService` also query purpose-bearing accounts at `apps/api/app/Modules/Accounting/Application/Services/PartnerBalanceService.php:165-199` and `apps/api/app/Modules/Accounting/Application/Services/ChartOfAccountsService.php:70-72`.

Impact: P2.5 cannot be safely implemented by threading `location_id` through only the named four-ish sites. Branch-expanded class 6/7 accounts would still receive parent postings from unconverted services, causing silent report loss via the roll-up trap. The spec needs a generated inventory of every production `JournalEntry::create()`, every `JournalLine::create()`, and every purpose lookup, with explicit per-phase ownership.

### BLOCKER 2 - P2.5 is not "strictly additive" or independently shippable until retroactivity is decided

The spec calls P2.5 "strictly additive" and shippable after P2 (`docs/superpowers/specs/2026-06-04-per-branch-program-design.md:56-59`), but retroactivity is still an open gate (`docs/superpowers/specs/2026-06-04-per-branch-program-design.md:83-87`). That is not a harmless open item. `AccountHierarchyService::calculateSubtotals()` overwrites a parent balance with only the sum of children at `apps/api/app/Modules/Accounting/Domain/Services/AccountHierarchyService.php:160-175`; `TrialBalanceService` then feeds account balances into that tree at `apps/api/app/Modules/Accounting/Application/Services/Reports/TrialBalanceService.php:116-129` and `:182-205`.

If an existing company has direct postings on a parent P&L account and the toggle later creates branch children under that parent, those parent postings disappear from hierarchical TB/P&L/BS totals unless migrated into a child or protected by a no-existing-postings precondition. Doc 09b correctly flags this at `docs/superpowers/research/2026-06-04-branch-tax-id-09b-subaccount-mechanics-and-design.md:85-90`; the program spec does not promote it to a phase gate. Required fix: make one of these explicit before approval: enable-only-before-first-posting, create an HQ/legacy child and migrate balances/lines, or block expansion per account with existing direct lines.

### MAJOR 1 - The spec has no crisp invariant for header vs line `location_id`

The spec says to adopt `journal_entries.location_id`, then says line-level is chosen and `journal_lines.location_id` should be added "where required" (`docs/superpowers/specs/2026-06-04-per-branch-program-design.md:51-52`). Current code has only header `journal_entries.location_id`; `JournalLine` has no location property/fillable/cast at `apps/api/app/Modules/Accounting/Domain/JournalLine.php:14-46`. Doc 07 says the load-bearing model is a journal-line/ledger-entry dimension, especially for inter-branch entries (`docs/superpowers/research/2026-06-04-branch-tax-id-07-multibranch-accounting-modeling.md:349-355`).

Impact: reports and posting rules can diverge. If both columns exist, the spec must define the invariant: line-level is canonical; header is nullable convenience only when all lines share one location; mixed-location entries must have null/header-derived policy; reports filter lines, not just headers. Without that, P2 can ship data that P3 cannot interpret correctly.

### MAJOR 2 - "GL hash unaffected" is true but incomplete as a fiscal/integrity claim

`GeneralLedgerHashService` hashes entry number, date, company, total debit, and total credit only (`apps/api/app/Modules/Accounting/Application/Services/GeneralLedgerHashService.php:54-76`). It excludes `location_id`, but also excludes line account ids, source ids, descriptions, and any future line-level location. The Eloquent observers prevent post-hash updates through normal model writes (`apps/api/app/Modules/Accounting/Domain/Observers/JournalEntryObserver.php:27-35`; line observer registered at `apps/api/app/Providers/AppServiceProvider.php:109`), so adding a non-hashed location is non-breaking to existing chain verification. But if branch attribution becomes audit-relevant, the spec must explicitly decide whether branch location remains outside the GL hash forever or gets a versioned/hash-v2 treatment.

Impact: the current "non-breaking" phrasing in `docs/superpowers/specs/2026-06-04-per-branch-program-design.md:27` and `:54` reads like "there is no integrity concern." The real statement is narrower: adding location will not invalidate old hashes, but the current GL hash will not attest branch attribution. That should be a conscious compliance decision, especially once branch P&L is user-facing.

### MAJOR 3 - Event-sourcing scope is under-characterized; POS is not the only cost-null writer

Doc 09c correctly identifies POS `issueStock()` as the high-volume null-cost path: it writes `location_id`, quantity, and reference fields but no `unit_cost`, `total_cost`, or averages at `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:900-918`. But the current tree has additional stock movement writers with the same gap: generic stock adjustments create movements without cost fields at `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:611-622`; POS void returns omit costs at `apps/api/app/Modules/POS/Application/Services/ReceiptVoidService.php:160-175`; POS returns omit costs at `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1019-1032`; inventory opening creates movement rows without cost fields even though it later updates product cost at `apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php:279-315`.

Also, Doc 09c says no `recordCostAdjustment` is present (`docs/superpowers/research/2026-06-04-branch-tax-id-09c-per-branch-valuation-event-sourcing.md:15`), but the current code has `recordCostAdjustment()` at `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:646-731`, and stock transfers call it at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:506-521`.

Impact: P2's "populate unit_cost + avg_cost_after on every stock_movements writer" is the right target, but its research evidence is stale/incomplete. The implementation plan must enumerate every writer, not just POS sale, or "replayable historical per-branch valuation" remains false.

### MAJOR 4 - OwnerReportScope cleanup is security-significant, not just taxonomy cleanup

The spec says P1 should "scope/clean OwnerReportScope" because it mixes `parent_company_id` and `allowed_location_ids` (`docs/superpowers/specs/2026-06-04-per-branch-program-design.md:43`). Current code expands the reporting scope to root company plus child companies at `apps/api/app/Modules/Accounting/Application/Services/Reports/OwnerReportScope.php:34-40`, then evaluates locations across those companies and falls back to the root membership when the child company has no membership at `apps/api/app/Modules/Accounting/Application/Services/Reports/OwnerReportScope.php:62-86`.

Impact: if `parent_company_id` is reserved for true multi-company groups, root-company membership can implicitly authorize child-company locations for owner reports. That may be intended for group reporting, but it is the wrong primitive for branches of one legal entity. P1 must decide and test the two axes separately: legal-entity group reporting vs location/branch filtering inside one company. This is not just wording.

### MAJOR 5 - P3 cannot be the first place GL report location semantics are tested

P2's deliverable is "branch-tagged ledger" (`docs/superpowers/specs/2026-06-04-per-branch-program-design.md:48-54`), while P3 later teaches reports to filter/group by location (`docs/superpowers/specs/2026-06-04-per-branch-program-design.md:61-64`). Current GL report services are company/account/partner/date based: trial balance query at `apps/api/app/Modules/Accounting/Application/Services/Reports/TrialBalanceService.php:182-205`, profit/loss query at `apps/api/app/Modules/Accounting/Application/Services/Reports/ProfitLossService.php:225-227`, balance sheet query at `apps/api/app/Modules/Accounting/Application/Services/Reports/BalanceSheetService.php:270-272`, and general-ledger query at `apps/api/app/Modules/Accounting/Application/Services/Reports/GeneralLedgerReportService.php:204-220`.

Impact: P2 cannot be proven with only "posting-site coverage" tests. It needs at least a test-only or internal query asserting the line/header location invariant and a minimal branch P&L/GL slice, otherwise P2 can populate columns in a way P3 later discovers is unusable. Either move the minimal read model into P2 or downgrade P2's deliverable.

### MINOR 1 - Selective sub-account expansion is legally permissible, not legally proven as the default set

The spec's default set is class 6/7 + liaison, while VAT and balance-sheet accounts stay single (`docs/superpowers/specs/2026-06-04-per-branch-program-design.md:21`, `:34`, `:58`). That is internally consistent with Doc 09a's rule of thumb for P&L vs balance-sheet accounts (`docs/superpowers/research/2026-06-04-branch-tax-id-09a-subaccount-practice.md:160-165`). But Doc 09a also says ordinary P&L subdivision is permitted, not required, in Tunisia and that France normally uses analytic dimensions rather than GL subaccounts (`docs/superpowers/research/2026-06-04-branch-tax-id-09a-subaccount-practice.md:65-70`, `:85-95`, `:184-188`).

Impact: keep this as a default-off, accountant-confirmed preset. Do not implement "all class 6/7 auto-expanded" as a hard product default without the accountant gate already listed in the spec.

### MINOR 2 - Current valuation MVP is only a current snapshot, not a valuation ledger

Current per-branch valuation as `stock_levels.quantity * products.cost_price` is feasible: `stock_levels` is unique by tenant/product/location at `apps/api/database/migrations/tenant/2025_11_30_110000_create_inventory_tables.php:16-29`, and `products.cost_price` is company-wide at `apps/api/app/Modules/Product/Domain/Product.php:133-138`. But this is only "now". It is not a point-in-time valuation, not replayable, and not a replacement for the snapshot/event plan. The spec mostly says this at `docs/superpowers/specs/2026-06-04-per-branch-program-design.md:63`, but its testing strategy "valuation reconciliation" at `docs/superpowers/specs/2026-06-04-per-branch-program-design.md:80-81` should pin the scope: current branch valuation sum equals current company valuation; historical valuation requires completed movement costs plus snapshots.

### NIT 1 - P0/program wording can be read as a type-gating contradiction

P0 intentionally adds nullable tax identity fields to all locations with no type-gating (`docs/superpowers/specs/2026-06-04-branch-tax-id-design.md:34-38`, `:44-54`), while the program says warehouses/offices/mobile have "no establishment identity" and branch means sellable location (`docs/superpowers/specs/2026-06-04-per-branch-program-design.md:20`). This is reconcilable: columns are universal; establishment meaning and UI affordances apply only to `LocationType::Shop`. Add that sentence to avoid implementers adding hard validation that P0 explicitly rejected.

## Verdict

**NEEDS-REWORK**

The architecture direction is sound: branch-as-location, company-wide WAC, dimension-first ledger, optional selective subaccounts. But the spec is not safe to approve until it fixes the posting/resolution inventory, promotes retroactivity to a P2.5 gate, defines line-vs-header location invariants, and tightens event-sourcing/report testability.

## Finding Counts

- BLOCKER: 2
- MAJOR: 5
- MINOR: 2
- NIT: 1
