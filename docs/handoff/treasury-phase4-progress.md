# Treasury Phase ④ — Expense Depth Progress

## Setup — 2026-07-13

- Branch: `feat/treasury-phase4-expense-depth`
- Base: `94a7c08cc0f4bde729a5dd8cf4c23388641840b5` (`origin/dev`)
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase4`
- Dependencies: real `composer install` in `apps/api`; root `pnpm install --frozen-lockfile`.
- Binding inputs read in required order: spec Rev 2.1, plan Rev 2, spec review, Fable W1 plan review, Opus W2–W4 plan review.
- Plan review: no unresolved contradiction found; all review findings are reconciled in plan Rev 2.
- Deviations: none.

## Task 1 — VAT fields on expense metadata — 2026-07-13

- Files:
  - `apps/api/database/migrations/tenant/2026_07_14_100000_add_vat_fields_to_expense_metadata.php`
  - `apps/api/app/Modules/Expense/Domain/ExpenseMetadata.php`
  - `apps/api/tests/Feature/Expense/ExpenseVatSchemaTest.php`
- RED: `php artisan test tests/Feature/Expense/ExpenseVatSchemaTest.php` exited 1 with 2 failed tests (2 assertions): the VAT column was absent and `vat_rate` was dropped during mass assignment.
- GREEN: `php artisan test tests/Feature/Expense/ExpenseVatSchemaTest.php --display-warnings` exited 0 with 2 passed tests (4 assertions).
- Implementation deviations: none. No `document_tax_details` migration or modification was added.
- Environment note: standalone `php artisan migrate --env=testing --force` reached the repository's named `central` PostgreSQL connection and failed because the local `root` role is unavailable. The focused test's `RefreshDatabase` path successfully applied the migration under the PHPUnit SQLite test configuration.

## Task 2 — Expense request partner + VAT format validation — 2026-07-13

- Files:
  - `apps/api/app/Modules/Expense/Presentation/Requests/ExpenseRequest.php`
  - `apps/api/tests/Feature/Expense/ExpenseRequestVatValidationTest.php`
- RED: `php artisan test tests/Feature/Expense/ExpenseRequestVatValidationTest.php --display-warnings` exited 1 with 4 failed and 1 passed (5 assertions): the endpoint returned 201 for a cross-company partner and each invalid VAT-format/range payload.
- GREEN: the same focused command exited 0 with 5 passed tests (13 assertions).
- Implementation deviations: none. Task 3 service/persistence semantics were not implemented.

## Task 3 — VAT-aware ExpenseService totals and merged guards — 2026-07-13

- Files:
  - `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php`
  - `apps/api/tests/Feature/Expense/ExpenseServiceVatTest.php`
  - `docs/handoff/treasury-phase4-progress.md`
- RED: `php artisan test tests/Feature/Expense/ExpenseServiceVatTest.php --display-warnings` exited 1 with 11 failed and 3 passed (22 assertions). Failures directly showed missing VAT subtotal/tax persistence, document-date and partner passthrough, merged/equality/currency-grid guards, stored linked-cost-kind rejection, and console-safe company resolution.
- Review-fix RED: the same focused command exited 1 with 3 failed and 14 passed (30 assertions), proving that EUR subminor VAT, zero-decimal-currency fractions, and digits beyond `$scale + 1` could bypass the initial grid comparison.
- GREEN: the final focused command exited 0 with 17 passed tests (30 assertions) in 11.21s.
- Expense cutoff: `php artisan test tests/Feature/Expense --display-warnings` initially exited 0 with 56 passed tests (239 assertions); the fresh final rerun after the grid-edge fix exited 0 with 59 passed tests (242 assertions) in 61.51s.
- Scoped verification:
  - `./vendor/bin/phpstan analyse app/Modules/Expense/Application/Services/ExpenseService.php --no-progress` exited 0 with no errors; this includes the configured `ForbidHardcodedBcmathScale` rule.
  - `./vendor/bin/pint --test app/Modules/Expense/Application/Services/ExpenseService.php tests/Feature/Expense/ExpenseServiceVatTest.php` exited 0 (`{"result":"pass"}`).
  - `php -l` on both changed PHP files exited 0 with no syntax errors.
  - `git diff --check` exited 0.
- Invariants: create resolves `Company` from explicit `company_id`; update resolves it from the stored document; all scale lookups receive that explicit currency; VAT math remains string/bcmath-based; a full fractional-digit check rejects every nonzero digit beyond the currency grid before exact-zero normalization or strict formatting; zero VAT becomes the VAT-less shape; update guards use merged total/VAT and stored expense kind; clearing VAT clears the full metadata trio.
- Implementation deviations: none. Posting, settlement, linked-cost capitalization, treasury movement, and fiscal surfaces were not changed. The broad repository preflight was intentionally not run per the Task 3 brief.

### Task 3 post-commit review fix — effective linked-cost VAT trio

- Accepted finding: the initial invariant shape omitted `vat_rate` and `vat_deductible_percent`, allowing rate-only or deductible-percent-only linked-cost create/update payloads to bypass the stored-kind guard and be silently cleared.
- RED: `php artisan test tests/Feature/Expense/ExpenseServiceVatTest.php --display-warnings` exited 1 with 4 failed and 17 passed (34 assertions). Rate-only and deductible-percent-only cases failed on both valid linked-cost create and stored-kind update.
- GREEN: the focused command exited 0 with 21 passed tests (38 assertions) in 10.21s. Each new case pins the exact linked-cost `DomainException` message.
- Expense cutoff: `php artisan test tests/Feature/Expense --display-warnings` exited 0 with 63 passed tests (250 assertions) in 39.99s.
- Scoped verification: focused PHPStan exited 0 with no errors; focused Pint exited 0 (`{"result":"pass"}`).
- Implementation: `assertVatInvariants()` now receives the effective merged VAT trio. Any non-null trio field on a linked-cost create or stored-kind update throws before persistence can clear it. Generic zero VAT still normalizes the entire trio to null.
- Review disposition: the VAT-less-total finding was not implemented. The binding brief states, **"When VAT is present, total and vat_amount must be ON THE CURRENCY GRID,"** and separately requires zero VAT to normalize to null for the VAT-less backward-compatible path. Rejecting VAT-less EUR `119.005` here would add an unplanned breaking validation change outside Task 3.

## Task 4 — Expense VAT split posting and input-VAT declaration wiring — 2026-07-13

- Files:
  - `apps/api/app/Shared/Domain/ExpenseVatSplit.php`
  - `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`
  - `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php`
  - `apps/api/tests/Feature/Accounting/ExpenseVatPostingTest.php`
  - `docs/handoff/treasury-phase4-progress.md`
- RED: `php artisan test tests/Feature/Accounting/ExpenseVatPostingTest.php --display-warnings` exited 1 with 7 failed and 2 passed (13 assertions). The failures directly showed the legacy gross-only debit instead of the VAT split and the absent `document_tax_details` row; VAT-less posting and the supplier-balance regression were already green.
- GREEN: the focused command exited 0 with 9 passed tests (29 assertions). Coverage pins unpaid/paid 100%, 80% remainder math and matching tax detail, 1-millime half-up, 0% with no 4456 line, byte-identical VAT-less shape/no tax row, EUR scale, supplier balance post-to-settle, and reconcile checks #1–#4 over a purpose-resolved repository GL account.
- Relevant regression cutoff: `php artisan test tests/Feature/Expense/ExpenseServiceVatTest.php tests/Feature/Expense/ExpenseSettlementTest.php tests/Feature/Accounting/AccountingTenantIsolationTest.php tests/Feature/Treasury/ReconcileTreasuryTest.php --display-warnings` exited 0 with 79 passed tests (240 assertions).
- Scoped verification:
  - PHPStan over the shared helper, both modified services, and the new feature test exited 0 with no errors.
  - Pint `--test` over the same four files exited 0 (`{"result":"pass"}`).
  - `git diff --check` exited 0.
- Invariants: both GL and tax-detail persistence consume `App\Shared\Domain\ExpenseVatSplit`; the helper uses `scale + 2` intermediates and one `CurrencyScale::bcround` at the posting boundary; GL uses the explicit expense currency scale and the remainder method; deductible VAT at zero omits 4456; the AP/Cash credit remains gross total; VAT-less posting preserves its exact two-line payload; tax detail is written in the existing posting transaction immediately after the JE is posted.
- Reconcile harness note: this `TenantScopedCommand` run returns no captured `Artisan::output()`. The test therefore pins exit 0 plus authoritative cash-line matching, movement ordinal/balance continuity, Posted JE linkage, unfrozen/unflagged repository state, and absence of cash/portfolio drift audit events.
- Deliberate architecture edge: `ExpenseService` writes `DocumentTaxDetail` directly as accepted by the binding plan; the shared math remains in `Shared\Domain` to avoid an Accounting-to-Expense dependency.
- Implementation deviations: none. Settlement, linked-cost posting/capitalization, `TreasuryMovementService`, fiscal surfaces, and migrations were not changed. Broad preflight/full repository suites were intentionally not run per the Task 4 brief.

### Task 4 post-commit review fix — collision-safe TVA detail identity

- Accepted finding: `firstOrCreate` keyed only on `document_id + tax_type` could reuse an unrelated stacked percentage detail and silently drop the expense TVA defaults, breaking the required equality between the 4456 debit and declared deductible VAT.
- RED: the focused posting suite exited 1 with 1 failed and 9 passed (31 assertions). The new fixture pre-seeded an unrelated percentage detail and proved posting left only that row instead of creating the required TVA snapshot.
- GREEN: the focused suite exited 0 with 10 passed tests (40 assertions). `firstOrCreate` now identifies an already-correct TVA row by the complete required semantic/calculated identity: document, type, name, rate, base, and deductible amount. Sequence, tax code, fixed amount, and stamp-duty flag remain creation defaults; no tax-code identity convention was introduced.
- Test strengthening: the EUR case now uses `0.01 VAT × 50%`, proving scale-2 half-up produces `0.010` at rest rather than a scale-3 `0.005`; the VAT-less regression now compares every deterministic line business attribute and order, excluding only IDs/timestamps that are nondeterministic.
- Idempotency: the collision test proves the upstream Posted-state guard rejects a replay before another TVA detail can be created, and the detail count remains exactly two (the unrelated row plus the required TVA row).
- Scoped regression cutoff: the required four-file command exited 0 with 79 passed tests (240 assertions). Its first run had one transient pre-existing `ReconcileTreasuryTest` one-millime tolerance failure; that case passed alone, the full reconcile file passed 20/20, and the exact four-file command passed on immediate rerun. No Treasury code or test was changed.
- Scoped PHPStan and Pint over the modified service/test exited 0; final fresh verification is recorded in the Task 4 report.
- Scope: no changes to GL split math, settlement, linked costs, treasury services, fiscal surfaces, or migrations.

## Task 5 — Supplier picker + VAT expense entry/detail — 2026-07-13

- Files:
  - `apps/web/src/features/expenses/components/organisms/ExpenseFormFields.tsx`
  - `apps/web/src/features/expenses/components/organisms/ExpenseFormFields.test.tsx`
  - `apps/web/src/features/expenses/components/organisms/ExpenseFormFields.linkedCost.test.tsx`
  - `apps/web/src/features/expenses/pages/ExpenseDetailPage.tsx`
  - `apps/web/src/features/expenses/pages/ExpenseDetailPage.test.tsx`
  - `apps/web/src/features/expenses/components/PayExpenseDialog.test.tsx`
  - `apps/web/src/features/expenses/types/index.ts`
  - `apps/web/src/locales/{en,fr,ar}/expenses.json`
  - `apps/api/app/Modules/Expense/Presentation/Resources/ExpenseResource.php`
  - `apps/api/app/Modules/Expense/Presentation/Controllers/ExpenseController.php`
  - `apps/api/app/Modules/Document/Domain/Document.php`
  - `apps/api/tests/Feature/Expense/ExpenseShowTest.php`
  - `apps/api/tests/Feature/Expense/ExpenseRequestVatValidationTest.php`
- Frontend RED: the focused Vitest command exited 1 with 4 new failures and 15 existing passes. The three form cases failed on the absent supplier/VAT controls; the detail case failed on the absent partner link and receipt arithmetic.
- Backend contract RED: the focused show/create response command exited 1 with 2 failed tests (4 assertions reached), both at absent `data.partner_id`.
- Frontend GREEN: focused form/detail Vitest exited 0 with 19/19; the full expense feature path exited 0 with 65 passed and 3 todo across 9 files. Existing tenant-scope `act(...)` and Node local-storage warnings remain unchanged.
- Backend GREEN: focused response contract exited 0 with 2 tests and 16 assertions; `php artisan test tests/Feature/Expense --display-warnings` exited 0 with 65 tests and 266 assertions in 38.43s.
- Verification:
  - `pnpm --filter @autoerp/web typecheck` exited 0; final root `pnpm typecheck` is recorded in the Task 5 report.
  - Focused ESLint exited 0 with 0 errors (the touched legacy files/tests still report their existing warning inventory).
  - `pnpm --filter @autoerp/web audit:design-system` reported 753 acknowledged, 0 new, 0 stale.
  - Scoped PHPStan over `Document`, `ExpenseController`, and `ExpenseResource` exited 0 with no errors; scoped Pint over the PHP implementation/tests passed.
  - The required deprecated `npx react-doctor@latest --verbose --diff` invocation compared the full phase branch to `main` and scored 49/100 from 143 branch-wide findings. The authoritative Task 5 scan, `npx react-doctor@latest apps/web --verbose --scope changed --base ef4cea39e --blocking none`, exited 0 at 93/100 with two pre-existing whole-file warnings: the form atoms barrel import and the already-over-300-line detail page. The Task 5 base versions were already 427 and 310 lines respectively, so this is no diagnostic regression.
- Behavior: selecting a supplier writes `partner_id` and snapshots its name into an independently editable `vendor_name`; configured active line-percentage taxes feed the rate select; inclusive VAT suggestion uses only decimal-string helpers; the receipt VAT amount remains editable; deductible VAT defaults to `100`; linked costs render without the VAT block and have their trio cleared; all fields validate inline through RHF; detail shows linked supplier plus net/VAT/rate/deductible/total.
- Necessary plan deviation: Task 2/3 persisted supplier/VAT values but the existing `ExpenseResource` omitted `partner_id`, partner snapshot, `subtotal`, `tax_amount`, `vat_rate`, and deductible percent, leaving the required detail UI disconnected. With task-owner approval, the initial Task 5 commit added focused response-contract tests, minimal resource serialization, and eager loading on shared expense responses. The later approved service update is recorded below; no posting logic or generated package types were touched. The initial commit also widened the global `Document` partner PHPDoc and made the linkable-invoice mapper null-safe; Task 6 independent review proved that global annotation change invalid and reverted it while preserving ExpenseResource's explicit loaded-relation safety.

### Task 5 post-commit review fixes — persistence, isolation, and legacy UX

- Persistence RED/GREEN: two focused `ExpenseService::update()` tests failed because supplier replacement and explicit-null clearing both retained the old UUID; both pass after adding only `partner_id` with `array_key_exists` merge semantics to the document update payload.
- Isolation RED/GREEN: a show fixture with an intentionally inconsistent cross-company partner FK exposed `data.partner_id`; the focused same-company/cross-company/create contracts now pass 3 tests with 20 assertions. A reusable controller relation callback selects only partner `id,name`, constrains the relation to the current company, and `ExpenseResource` derives both supplier fields exclusively from that loaded safe relation.
- Detail RED/GREEN: a real `MemoryRouter` test caught navigation to `/partners/{id}` and legacy `- TND` output. The 19-test detail suite now pins `entityRoutes.supplier()` at `/purchases/suppliers/{id}`, exact dash placeholders without units, and partner-null vendor-snapshot fallback.
- Accessibility RED/GREEN: the canonical picker test failed on an unnamed combobox; the 12-test picker suite passes after connecting label `htmlFor` and input `id` with `useId`.
- Form coverage: the controlled picker mock now reflects its supplied `value`; edit-mode selected supplier and editable vendor snapshot submit together; entering VAT then switching to linked cost submits the cleared optional trio. A historical `7.50` rate initially collapsed to empty and now remains visible/submittable through a decimal-string-normalized translated fallback option. The form suite passes 5/5.
- Fresh regression cutoffs: Expense backend 68 passed/272 assertions; expense frontend plus canonical picker 82 passed/3 todo across 10 files; root typecheck exited 0; focused ESLint exited 0 with no errors; design audit remained 753 acknowledged/0 new/0 stale; scoped PHPStan reported no errors.
- React Doctor pinned to Task 5 base `ef4cea39e` exited 0 at 93/100 with the same two base-existing findings (atoms barrel import and already-large detail component).
- Owner-approved deviation: Task 5 now minimally updates `Document.partner_id` inside `ExpenseService::update` and secures response supplier loading/serialization. Posting, settlement, VAT split math, linked-cost capitalization, Treasury, fiscal behavior, migrations, and generated types are unchanged.

### Task 5 final review fix — explicit supplier clear payload

- RED: the focused edit-mode form test failed because clearing the controlled picker submitted `partner_id: undefined`; PATCH JSON would omit the field and preserve the supplier despite the backend's explicit-null clearing contract.
- GREEN: `CreateExpenseDTO.partner_id` is `string | null` when present, and `PartnerPicker.onChange(null)` writes explicit `null`. The form suite passes 6/6, including create selection remaining a string UUID and edit clear submitting null so `ExpenseService::update()` reaches its tested `array_key_exists` clear path.
- Final verification: frontend expense plus canonical picker 83 passed/3 todo; root typecheck exited 0; focused ESLint had 0 errors; design audit had 0 new/stale; focused backend clear/replace passed 2/2; pinned React Doctor remained 93/100; diff check passed.

## Task 6 — W1 closeout, type generation, preflight, and Gate 1 verification — 2026-07-13

- Verification baseline: branch rebased onto current `origin/dev` `e9c2b581d` by the controller before Task 6.
- Type generation: `CACHE_STORE=array php artisan typescript:transform` exited 0 and transformed 434 PHP types. `packages/shared/types/generated.d.ts` remained byte-clean, so there was no legitimate generated-types commit.
- Preflight: exact `./scripts/preflight.sh` exited 1 at its first unconditional full-repository Pint check. It reported 18 Product/Fiscal/Inventory/Company test files, all byte-identical to `origin/dev`; Phase 4 changes neither those paths nor Pint configuration. Per the task's scope rule, unrelated upstream formatting was not altered.
- Branch-caused preflight/static fix: changed-file PHPStan found the request-test helper's invalid `TestResponse<array<string,mixed>>` generic. Commit `3928e7f7f` changes it to the repository-standard `TestResponse<Response>`. Focused verification passed 6 tests / 21 assertions, PHPStan level 8 with no errors, and Pint.
- Gate 1 backend:
  - Expense: 68 tests / 272 assertions, exit 0; rerun fresh after the PHPDoc commit with the same result.
  - Accounting: 478 tests / 2,156 assertions, 4 skips, exit 0.
  - Treasury: 603 tests / 2,411 assertions, 25 skips, exit 0.
  - `./vendor/bin/pint --dirty`: exit 0.
  - Initial exact `./vendor/bin/phpstan` exhausted its default 512 MB parallel workers after scanning 2,529 files. The initial 2 GB retry completed with 34 errors that were incorrectly attributed to upstream because their downstream paths were unchanged; independent review later proved they were induced by this branch's shared `Document` partner annotation change. The correction and fresh green evidence are recorded below.
- Gate 1 frontend:
  - `pnpm typecheck && pnpm lint`: exit 0; ESLint 0 errors (existing warnings only), TanStack audit 0 violations, design audit 753 acknowledged / 0 new / 0 stale, custom ESLint rules green.
  - Required Vitest scope: 14 files, 90 passed / 3 todo, exit 0.
  - Direct design-system and TanStack audit commands both exited 0.
- Pinned Gate 1 proofs:
  - paid-VAT authoritative reconcile fixture: 1 test / 12 assertions, exit 0;
  - VAT-less byte-shape regression: 1 test / 2 assertions, exit 0;
  - console-shape create with cleared context: 1 test / 2 assertions, exit 0.
- Inviolate port: `git diff --exit-code origin/dev..HEAD -- apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php` exited 0 (empty).
- Task 6 report: `.superpowers/sdd/task-6-report.md`.
- Deviations: no functional plan deviation. Type generation was a no-op, so no empty commit was manufactured. Exact preflight remains non-green only because of proven `origin/dev` formatting drift. Full PHPStan is green after the review correction below.

### Task 6 independent-review fix — restore the shared Document partner contract

- HIGH finding: changing global `Document::$partner_id` and `$partner` PHPDoc to nullable propagated nullable types into every document domain, despite the null exception being specific to expenses. The changed-file PHPStan run missed the regression because all 34 failing consumers were unchanged files.
- RED: fresh `./vendor/bin/phpstan --memory-limit=2G --error-format=json` analyzed 2,529 files and reproduced exactly 34 file errors across 22 consumers. The prior claim that these were origin/dev errors is retracted.
- Root-cause fix: commit `700212281` restores the origin/dev annotations (`string $partner_id`, `Partner $partner`) and restores `partner->name` in the linkable supplier-invoice mapper. ExpenseResource keeps its explicit relation-loaded, company-constrained supplier serialization, so the expense exception remains disclosure-safe without weakening the shared model contract.
- GREEN: fresh `./vendor/bin/phpstan --memory-limit=2G` passed 2,529/2,529 with no errors. The subsequent exact default `./vendor/bin/phpstan` also passed 2,529/2,529 with no errors; no baseline, suppression, or downstream consumer edits were added.
- Regression verification: focused Expense response/isolation tests passed 9 tests / 35 assertions; full Expense passed 68 tests / 272 assertions; scoped Pint passed; `git diff --check` passed; `TreasuryMovementService` remained byte-untouched relative to `origin/dev`.

## Gate 1 — Wave 1 money path — 2026-07-13

- Release-candidate tag: `phase4-gate-1-rc1` at `806fceace`.
- Autonomous review: Fable tier (`claude-fable-5`) over `git diff origin/dev..HEAD`.
- Review file: `docs/handoff/gate-reviews-phase4/GATE-1-rc1.md`.
- Verdict: **APPROVE** — no BLOCKER, HIGH, or MEDIUM findings.
- Non-blocking observations: optional hardening of direct-service numeric-shape guards, cosmetic consistency in the tax-detail metadata access, and suggestion-only frontend rounding/formatting. No Wave 1 contract change was required.
- Artifact note: the isolated reviewer lacked file-write permission, so it emitted the complete review text to stdout; the controller persisted that text without changing its substance.

## Task 7 — Recurring expense template schema — 2026-07-13

- Files:
  - `apps/api/database/migrations/tenant/2026_07_14_110000_create_expense_recurrence_templates.php`
  - `apps/api/database/migrations/tenant/2026_07_14_110100_add_recurrence_template_id_to_expense_metadata.php`
  - `apps/api/app/Modules/Expense/Domain/Enums/RecurrenceFrequency.php`
  - `apps/api/app/Modules/Expense/Domain/Enums/RecurrenceStatus.php`
  - `apps/api/app/Modules/Expense/Domain/ExpenseRecurrenceTemplate.php`
  - `apps/api/tests/Feature/Expense/ExpenseRecurrenceTemplateModelTest.php`
- RED: focused PHPUnit exited 2 with 5 tests, 1 expected schema failure, and 4 expected missing-model errors before any production file existed.
- GREEN: focused PHPUnit exited 0 with 5 tests / 55 assertions. It proves the full schema field list, complete mass-assignment behavior, enum/date/decimal/integer casts, active/three-day defaults, all four nullable default FKs using `SET NULL`, and metadata linkage using `SET NULL`.
- Expense regression: `./vendor/bin/phpunit tests/Feature/Expense` exited 0 with 73 tests / 327 assertions.
- Quality gates: scoped PHPStan level 8 passed with no errors; scoped Pint passed; all six task PHP files passed `php -l`; `git diff --check` passed.
- Migration/schema sanity: the focused `RefreshDatabase` path applied both migrations under the PHPUnit SQLite environment and passed real persistence and FK deletion checks.
- Scope: exactly the Task 7 schema/enums/model slice. No cursor math, CRUD, permissions, generator, notifications, scheduling, frontend, Treasury, fiscal, settlement, posting, or generated-type surface changed.
- Deviations: none.

### Task 7 independent-review fix — metadata model write path

- HIGH finding: the initial test used `DB::table` for the metadata link, hiding that `ExpenseMetadata::$fillable` omitted `recurrence_template_id`; Task 10's required `ExpenseMetadata::update()` call would silently discard it.
- RED: after replacing the bypass with the exact model-update consumer path, focused PHPUnit exited 1 with 1 failure / 5 tests because refresh returned null instead of the template UUID.
- Fix: added the nullable property annotation and fillable entry to `ExpenseMetadata`; no relationship was added because no direct consumer requires one.
- GREEN: focused PHPUnit exited 0 with 5 tests / 56 assertions, proving model persistence and subsequent FK `SET NULL` behavior.
- Fresh verification: full Expense 73 tests / 328 assertions; scoped PHPStan level 8 clean; scoped Pint and diff check clean.

## Task 8 — Origin-anchored recurrence cursor math — 2026-07-13

- Files:
  - `apps/api/app/Modules/Expense/Domain/Services/RecurrenceCursor.php`
  - `apps/api/tests/Unit/Expense/RecurrenceCursorTest.php`
- RED: focused PHPUnit exited 2 with all 7 tests reaching the expected missing `RecurrenceCursor` API before the production class existed. The tests already pinned day-31 clamp recovery, quarterly/yearly cadence, leap-day clamping, inclusive roll-forward boundaries, and all three period-key formats.
- GREEN: focused PHPUnit exited 0 with 7 tests / 11 assertions. Monthly `2026-01-31` advances to `2026-02-28` and then `2026-03-31`, proving candidates are regenerated from the origin rather than advanced from the clamped current value.
- Cursor contract: `next` returns the first origin-cadence occurrence strictly after `current`; `firstOnOrAfter` returns the first occurrence greater than or equal to the supplied date, including before/on-origin and exact later occurrences; period keys are `YYYY-MM`, `YYYY-Qn`, and `YYYY`.
- Purity: static immutable-date math only; no `now()`, `Date`, database, context, mutable clock, or other global dependency.
- Regression: all `tests/Unit/Expense` passed 7 tests / 11 assertions; full Expense feature path passed 73 tests / 328 assertions.
- Quality gates: scoped PHPStan level 8 clean; scoped Pint clean. Final syntax/diff/scope checks are recorded in the Task 8 report.
- Scope: no Task 9+ CRUD, permissions, generation, scheduling, notification, forecast, frontend, Treasury, fiscal, posting, or settlement behavior changed.
- Deviations: none.

## Task 9 — Recurrence CRUD and permission maps — 2026-07-13

- Files:
  - `apps/api/app/Modules/Expense/Presentation/Requests/ExpenseRecurrenceRequest.php`
  - `apps/api/app/Modules/Expense/Presentation/Controllers/ExpenseRecurrenceController.php`
  - `apps/api/app/Modules/Expense/Domain/ExpenseRecurrenceTemplate.php`
  - `apps/api/app/Modules/Expense/routes.php`
  - `apps/api/database/seeders/RolesAndPermissionsSeeder.php`
  - `apps/api/tests/Feature/Expense/ExpenseRecurrenceCrudTest.php`
  - `apps/web/src/hooks/usePermissions.ts`
  - `apps/web/src/hooks/__tests__/usePermissions.expenseRecurrences.test.ts`
- Backend RED: focused PHPUnit exited 2 with 6 tests: five expected 404 failures for the absent routes and one missing-permission error for `expense-recurrences.view`. The test already pinned full CRUD, tenant/company isolation, all four scoped default FKs, validation boundaries, exact role grants/denials, cursor recomputation, and pause/resume semantics.
- Frontend RED: focused Vitest exited 1 with the new permission-map assertion receiving `undefined` for `expense-recurrences.view`.
- Backend GREEN: focused PHPUnit exited 0 with 7 tests / 106 assertions. Manager CRUD is green; accountant and manager receive the full seeded grant set; cashier/operator/viewer receive view only; mutation denies return 403; sibling-company and foreign-tenant templates return no list/show/update/delete disclosure; all four sibling-company FK values return 422.
- Cursor semantics: create computes the first origin-cadence occurrence on/after today; edits to either `frequency` or `start_date` recompute from the origin; resume moves a stale paused cursor directly to the first occurrence on/after today and returns Active without materializing/backfilling missed periods.
- Validation: money values remain decimal strings with at most three places, VAT percentages remain decimal strings with at most two places and a 0–100 bound, dates/enums are validated, merged update dates cannot place `end_date` before `start_date`, and `lead_days` consumes `ExpenseRecurrenceTemplate::MAX_LEAD_DAYS` (`60`). Every optional default FK uses `ScopedExists::tenantAndCompany`.
- Permission alignment: backend and frontend both grant recurrence CRUD plus `expenses.export` to admin/manager/accountant only; cashier/operator/viewer get recurrence view only. The frontend module map also gates the future recurrence route on `.view`.
- Regression and quality evidence:
  - Full Expense feature path: 80 tests / 434 assertions, exit 0.
  - All focused frontend permission tests: 6 files / 28 tests, exit 0.
  - Root `pnpm typecheck`: all participating workspaces exited 0.
  - Scoped PHPStan level 8: no errors; scoped Pint test: pass; focused ESLint: 0 errors.
  - Design audit: 753 acknowledged / 0 new / 0 stale; TanStack key audit: 0 violations; `git diff --check`: exit 0.
  - `php artisan route:list --path=expense-recurrences`: exactly 7 required routes.
  - React Doctor pinned to the Task 9 base `3ad776504` exited 0 at 98/100 with no issues. The deprecated unpinned `--diff` invocation compared the full phase branch to `main` and surfaced 330 unrelated branch-wide issues; the base-pinned Task 9 scan is the relevant regression result.
- Scope: no generation command, scheduler, notifications, forecast projection, Task 12 UI, Treasury, fiscal, posting, settlement, migration, or generated-type behavior changed.
- Deviations: none.

### Task 9 independent-review fixes — terminal end bounds and lifecycle transitions

- Root cause: cursor calculation and status persistence were split across create, update, and resume. None compared the effective cursor to the inclusive end date, generic PUT could write `paused → active` without resume roll-forward, and the dedicated endpoints did not enforce their source states.
- RED: focused PHPUnit exited 1 with 3 failing tests out of 10. Create returned Active for a cursor after the end date; resume returned Active for an expired paused template; and resume on an already-Active template returned 200 instead of 422. The new cases also pin end-date shortening, origin/cadence recomputation, generic PUT resume semantics, terminal Ended behavior, invalid pause/resume source states, and partial-update start/end validation.
- Fix: one controller `deriveLifecycle()` path now owns cursor selection, resume roll-forward, transition validation, terminal Ended behavior, and the inclusive end-date comparison. Create, update, pause, and resume all consume it. A cursor equal to `end_date` remains eligible; only a cursor strictly after it becomes Ended.
- Transition contract: Paused→Active always rolls to the first origin-cadence occurrence on/after today (dedicated endpoint or generic PUT); Active→Paused is allowed; direct Ended is allowed; Ended cannot return to Active or Paused. Dedicated pause requires Active and dedicated resume requires Paused; invalid calls return 422 without changing status or cursor.
- GREEN and regression: focused CRUD passed 10 tests / 139 assertions; full Expense passed 83 tests / 467 assertions; scoped PHPStan level 8 and Pint passed; all frontend permission tests remained green at 28/28; `git diff --check` passed.
- Scope: no Task 10 generation/backfill behavior, permission map, TypeScript, route, request enum rule, Treasury, fiscal, posting, or settlement behavior changed.

## Task 10 — Recurring draft generation command and notifications — 2026-07-13

- Files:
  - `apps/api/app/Modules/Expense/Presentation/Console/GenerateRecurringExpensesCommand.php`
  - `apps/api/app/Modules/Expense/Providers/ExpenseServiceProvider.php`
  - `apps/api/routes/console.php`
  - `apps/api/tests/Feature/Expense/GenerateRecurringExpensesCommandTest.php`
- RED: focused PHPUnit exited 2 with 7 tests: six expected missing-command errors and one expected schedule-list failure before production code existed. The test file already pinned full template passthrough, Draft/unpaid/null-payment semantics, Jan-31 clamp, atomic cursor/end behavior, prefixed period idempotency, fresh-vs-replayed `wasRecentlyCreated`, no duplicate notification, exact notification data, permission/company recipient filtering, missing-author fallback, tenant/company isolation, timezone/MAX-lead boundaries, partial failure exit, schedule shape, and no CompanyContext access.
- Boundary fix: the first implementation run showed that parsing date-only due values in the application timezone skipped a template exactly at company-local `due - lead_days == today`. The command now parses that due date in the company's timezone before the exact lead comparison.
- GREEN: final focused PHPUnit passed 7 tests / 70 assertions. Full Expense passed 90 tests / 537 assertions.
- Generation contract: ordered tenant/company Active scans; shared `MAX_LEAD_DAYS` SQL prefilter; one outer transaction around console-safe `ExpenseService::create`, metadata recurrence link, and origin-anchored cursor advancement; Ended only when next is strictly after the inclusive end; notification after commit and only for a fresh document.
- Actor/recipient isolation: author lookup is tenant-qualified; missing authors fall back to the first lexically ordered Active tenant admin under a temporarily set-and-restored Spatie tenant team. Notifications use `TreasuryAlertRecipients::forCompany(tenant, company, 'expenses.post')`, exclude inactive/sibling memberships, and store exact recurring payload plus the alert-type convention.
- Scheduler: `php artisan schedule:list` shows `30 5 * * * php artisan expenses:generate-recurring`; schedule is `withoutOverlapping` and in-process, with no `runInBackground`.
- Verification: maturity-alert plus Horizon queue regression passed 8 tests / 37 assertions; explicit PHPStan level 8 clean; Pint pass; syntax/diff checks clean; `TreasuryMovementService` and Horizon configuration untouched.
- Existing architecture baseline: the combined console architecture path still flags only `ScanPercentScaleDrift` and `RunEnrichmentCommand` as unclassified. Those files, the test, and deferral fixture are byte-clean against Task 10 base `13388cf94`; the new command extends `TenantScopedCommand`. No unrelated fix was made.
- Necessary file-list deviation: the Expense module provider now registers the command because module console classes are not auto-discovered. This is registration-only; no service or behavior outside Task 10 was added.
- Task report: `.superpowers/sdd/task-10-report.md`.

## Task 11 — Recurring forecast feeds — 2026-07-13

- Files:
  - `apps/api/app/Modules/Accounting/Application/Services/Reports/UpcomingPaymentsService.php`
  - `apps/api/tests/Feature/Accounting/UpcomingPaymentsRecurringTest.php`
- RED: after correcting the command fixture's required `expenses.post` permission, focused PHPUnit exited 1 with all 5 lifecycle tests failing on the absent projected/materialized feeds. Projection assertions received no Money-Out lines, and generated Draft assertions proved the existing posted-only query left the materialization-to-posting gap.
- GREEN: focused PHPUnit passed 6 tests / 46 assertions. The suite pins an active cursor projection, command-driven projection-to-Draft partitioning without a gap or double count, Draft-to-posted-unpaid movement, deliberate deletion semantics after cursor advancement, exact TND string aggregation, cursor iteration, and an occurrence exactly on the inclusive end date.
- Forecast partition: posted unpaid expenses remain unchanged; recurring Draft documents with a non-null metadata template link are concrete document lines due on `document_date`; Active templates project from `next_due_date` through the horizon using origin-anchored `RecurrenceCursor::next`. Cursor advance makes the materialized and projected partitions disjoint.
- Money contract: projection amounts remain decimal strings, are formatted and accumulated with bcmath at the owning company's resolved scale, and combine with document/instrument totals without float conversion.
- Read architecture and scope: the service class documents its existing direct cross-module read convention for `ExpenseMetadata` and `ExpenseRecurrenceTemplate`; projections are read-only and never materialize an expense. Draft lines use the document UUID as the reference until posting assigns a document number.
- Verification: full Accounting feature path passed 484 tests / 2202 assertions (4 existing skips and 1 existing PHPUnit deprecation); full Expense feature path passed 90 tests / 537 assertions; the Task 10 generation command path passed 7 tests / 70 assertions. Scoped PHPStan level 8 and Pint passed; `git diff --check` passed.
- Deletion semantics: deleting a generated Draft intentionally removes that occurrence from the forecast. It is not projected or regenerated because the template cursor already advanced.
- Deviations: none.

## Task 12 — Recurring expense UI and bell notification — 2026-07-13

- Files:
  - `apps/web/src/features/expenses/api/recurrenceApi.ts`
  - `apps/web/src/features/expenses/hooks/useExpenseRecurrences.ts`
  - `apps/web/src/features/expenses/components/organisms/ExpenseRecurrenceForm.tsx`
  - `apps/web/src/features/expenses/pages/RecurringExpensesPage.tsx`
  - expense recurrence types/invalidation predicates, route/sidebar wiring, expense-detail origin chip, bell notification rendering, EN/FR/AR locale resources, and focused tests.
- RED: the initial six-file Vitest run exited 1 with four expected assertion failures and two missing-module suite failures before the recurrence API, hooks, page, route, navigation, notification type, and detail origin surface existed. A separate invalidation regression test then exited 1/4 because exact detail invalidation was absent.
- GREEN: the UI provides localized cadence, next-due hierarchy, precision-safe currency display, status, permission-gated CRUD, pause/resume, and a W1-aligned supplier/VAT/payment form backed by `react-hook-form`. All seven exact `/expense-recurrences` operations are represented by the API client. Queries use tenant/company suffix scoping; mutations use scoped list/detail predicates and refresh the upcoming-payments forecast prefix.
- Navigation and notification contract: `/expenses/recurring` is lazy-loaded before the expense `:id` route and gated by exact `expense-recurrences.view`; the sidebar link shares that gate. `expense.recurring.generated` resolves nested EN/FR/AR title/message keys, formats the decimal-string amount through `formatCurrency`, and preserves the expense deep link. Generated expense details display a recurrence-origin chip when `metadata.recurrence_template_id` is present.
- Focused verification: six files passed 83/83 tests. Fresh widened verification passed 20 files, 151 tests, and 3 existing todo tests. Existing `tenantScope.test.tsx` act warnings remain non-failing and were not introduced by Task 12.
- Quality gates:
  - `pnpm typecheck`: exit 0.
  - `pnpm lint`: exit 0 with 0 errors and existing repository warnings; TanStack audit 0 violations, design audit 753 acknowledged / 0 new / 0 stale, and custom ESLint rules green.
  - Scoped Task 12 ESLint: 0 errors and 0 warnings.
  - React Doctor pinned to Task 12 base `9faaa374e`: 93/100 with **No issues found**. The score remained 93 after all diagnostics were removed.
  - `git diff --check`: exit 0.
- Scope: frontend Task 12 only. No backend, generation, forecast, Treasury, fiscal, settlement, posting, migration, or Task 13+ behavior changed.
- Deviations: one additional focused component file (`ExpenseRecurrenceForm.tsx`) was extracted from the page to satisfy React Doctor maintainability guidance and then migrated to the repository's required `react-hook-form` convention. No functional contract changed.

### Task 12 independent-review fixes — recurrence origin, VAT invariants, and mutation safety

- Expense origin serialization: `ExpenseResource` now always includes nullable `metadata.recurrence_template_id`. RED was 2 expected failures in the 4-test show suite for the missing null shape and missing real linked UUID; GREEN was 4 tests / 20 assertions using persisted recurrence metadata.
- Inclusive VAT UI: the W1 decimal-string inclusive-VAT calculation was extracted to one shared helper and reused by the recurrence form at the active currency scale. RED was 1 failed computed-VAT assertion; GREEN covered changing and clearing the rate, exact `19.000` payload output from `119.000 @ 19%`, and representation of a historical `7.50%` rate. The W1 field suite remained green at 6/6.
- Server recurrence invariants: create and effective merged PUT values now use the owning-company currency scale and string/BCMath comparisons to reject off-grid totals/VAT, incomplete positive-VAT tuples, and `vat_amount >= amount` before persistence. VAT-less create/update values normalize all three VAT fields to null, including rate-only partial PUTs and legacy stored `0.000` tuples. The initial RED had 4 focused failures; the added partial-PUT RED persisted `7.50` over a null VAT amount. GREEN included 13 assertions for the null/legacy-zero edge and a generation-poison regression proving invalid templates cannot block later valid generation.
- Mutation rejection safety: page pause/resume/delete handlers now consume rejected mutation promises while hooks retain the error toast. The RED suite exposed an unhandled rejected delete promise despite 13 passing assertions; GREEN passed 13/13 without an unhandled rejection.
- Delete cache precision: delete now forwards its mutation variable into the existing tenant/company-scoped exact-detail invalidator. RED was 1 failure in the 6-test hook suite; GREEN was 6/6 and proves another recurrence ID and another tenant do not match.
- Final backend verification: focused resource/CRUD/generation passed 26 tests / 285 assertions; full Expense passed 96 tests / 599 assertions; scoped PHPStan level 8 and Pint passed; `TreasuryMovementService` remained byte-untouched; `git diff --check` passed.
- Final frontend verification: focused recurrence/page/W1 suites passed; widened expense/notification/route/sidebar coverage passed 20 files / 157 tests with 3 existing todo tests; root typecheck passed. Full lint passed with 0 errors and existing warnings; TanStack audit reported 0 violations, design audit reported 753 acknowledged / 0 new / 0 stale, and custom ESLint rule tests passed. Scoped review-file ESLint passed with 0 warnings. React Doctor pinned to Task 12 commit `508729847` reported **No issues found** (84/100 under v0.7.7).
- React Doctor follow-up: extracting the W1 helper brought its existing atoms barrel import into the changed scope. The canonical rule check confirmed a true positive; direct atom imports removed the only diagnostic without changing behavior.
- Deviations: none. The backend additions close independently reviewed Task 10/12 integration gaps; they do not alter recurrence generation, cursor semantics, posting, Treasury, fiscal, settlement, or forecast contracts.

## Gate 2 RC1 verification — 2026-07-13

- Backend: Expense passed 96 tests / 599 assertions; Accounting passed 484 tests / 2202 assertions with 4 existing skips and 1 existing deprecation; Unit/Expense passed 7 tests / 11 assertions.
- Full PHPStan first exhausted its configured 512 MB parallel-worker limit after scanning 2536 files and produced no code diagnostic. The exact rerun with `--memory-limit=1G` completed 2536/2536 with no errors. Dirty Pint passed.
- Gate-specific command coverage: `expenses:generate-recurring` appears in `schedule:list` at 05:30; its replay/no-double-notify path passed 7 tests / 70 assertions. `TreasuryMovementService` remains byte-identical to `origin/dev`.
- Frontend: typecheck and full lint exited 0. The expense/notification/permission matrix passed 16 files / 106 tests with 3 existing todo tests. Design audit remained 753 acknowledged / 0 new / 0 stale; TanStack query-key audit remained 0.
- Task 12 independent re-review: APPROVE after commits `508729847..670619180`; no remaining money-path BLOCKER/HIGH and no Fable escalation trigger before the formal Gate 2 Opus review.
- Deviations: none.

## Gate 2 RC1 verdict — 2026-07-13

- General Opus lane: **APPROVE**, no BLOCKER/HIGH/MEDIUM. Artifact: `docs/handoff/gate-reviews-phase4/GATE-2-rc1.md`.
- Tenancy/authz Opus lane: **APPROVE**, no BLOCKER/HIGH/MEDIUM and no money-path authorization break. Artifact: `docs/handoff/gate-reviews-phase4/GATE-2-tenancy-authz-rc1.md`.
- Non-blocking observations: bare upcoming-payments prefix may over-refetch; primary same-tenant author lookup does not require active status; two INFO-level dedicated negative-test gaps. No data leak, IDOR, permission divergence, or money-path failure was found.
- Fable escalation: not triggered because neither Opus lane found or remained uncertain about a money-path BLOCKER/HIGH, and Wave 2 records no money-path plan deviation.

## Task 13 — Expense analytics endpoint — 2026-07-13

- Files:
  - `apps/api/app/Modules/Expense/Application/DTOs/AnalyticsFilters.php` and the five `ExpenseAnalytics*Data` response DTOs;
  - `apps/api/app/Modules/Expense/Application/Services/ExpenseAnalyticsService.php`;
  - `apps/api/app/Modules/Expense/Presentation/Controllers/ExpenseAnalyticsController.php`;
  - `apps/api/app/Modules/Expense/Presentation/Requests/ExpenseAnalyticsRequest.php`;
  - `apps/api/app/Modules/Expense/routes.php`;
  - `apps/api/database/migrations/tenant/2026_07_14_120000_add_expense_analytics_documents_index.php`;
  - `apps/api/tests/Feature/Expense/ExpenseAnalyticsTest.php`.
- RED: focused PHPUnit exited 1 with 8/8 expected 404 failures and 8 assertions before any endpoint production file or route existed. The tests already pinned the default six-month/status window, explicit status/category filters, inclusive date boundaries, exact tiles/category/matrix/vendor strings, equal-length prior-period percentage, empty/zero behavior, a pre-W1 legacy row, sibling-company and explicit tenant isolation, partner-vs-snapshot vendor grouping, canonical validation, permission denial, and route order.
- GREEN: focused PHPUnit passed 8 tests / 60 assertions. Full Expense feature regression passed 104 tests / 659 assertions with 0 errors, 0 failures, and 0 skips.
- Query contract: current/prior tiles, categories, category-month matrix, and vendors each use one database `GROUP BY` query. Every query filters `documents.tenant_id`, `documents.company_id`, Expense type, status, inclusive date window, soft-delete state, and optional category. Category and partner joins are also tenant/company constrained. PostgreSQL month buckets use `to_char(document_date, 'YYYY-MM')`; SQLite alone wraps its permissive time-suffixed test date storage in `date(...)` and uses `strftime`, leaving production predicates/index use unchanged.
- Money contract: aggregate SQL values are cast to text, then normalized at the explicit owning-company currency scale. All arithmetic is BCMath; no float conversion is used. `share_percent` has the reviewed zero-total guard and two-decimal rounding, while equal-length prior-period percentage is null when the previous total is zero. Legacy `subtotal == total` / null-tax rows continue to sum by stored gross `total`.
- Index decision: live PostgreSQL `pg_indexes` inventory showed `idx_documents_company_type_status` and `idx_documents_company_date` as separate indexes, but no equivalent `(company_id, type, status, document_date)` index. The tenant migration therefore adds `idx_documents_company_type_status_date`; focused/full `RefreshDatabase` runs applied it successfully under SQLite.
- Verification: scoped PHPStan level 8 passed with no errors; dirty Pint formatted the task files and the final scoped check passed; `git diff --check` passed. `php artisan route:list --path=api/v1/expenses/analytics -vv` showed exactly one GET route with `Authorize:expenses.view`, and source registration is above `expenses/{id}`.
- Scope: Task 13 only. No Task 14 export, frontend Task 15, Treasury, fiscal, posting, settlement, recurrence, or forecast behavior changed.
- Deviations: none. The driver-specific SQLite date/month expressions are the plan-required portability implementation and do not alter PostgreSQL semantics.

### Task 13 independent-review fix — prevent malformed partner disclosure

- HIGH finding: although the `partners` join was correctly tenant/company scoped, the initial vendor grouping key and selected `partner_id` came from `documents.partner_id`. A malformed/legacy company-A expense pointing at a company-B partner therefore hid the sibling name but still returned its UUID beside company A's vendor snapshot.
- RED: the new malformed two-companies-one-tenant regression failed 1/1 with 3 assertions because `top_vendors[].partner_id` returned the sibling UUID instead of null.
- Fix: the vendor grouping key and selected partner ID now derive from the successfully company-scoped joined `partners.id`. When that join misses, both identity and label fall back to `expense_metadata.vendor_name`; no sibling identifier or name survives. Existing same-company partner grouping and snapshot-only grouping remain covered.
- GREEN: the exact regression passed 1 test / 5 assertions; the full analytics file passed 9 tests / 65 assertions; full Expense passed 105 tests / 664 assertions with no errors, failures, or skips. Scoped production PHPStan and scoped Pint passed; the fix diff check was clean.
- Deviations: none.

## Task 14 — Permission-gated streamed CSV export — 2026-07-13

- Files:
  - `apps/api/app/Modules/Expense/Application/Queries/ExpenseIndexQuery.php`;
  - `apps/api/app/Modules/Expense/Presentation/Controllers/ExpenseExportController.php`;
  - `apps/api/app/Modules/Expense/Presentation/Controllers/ExpenseController.php`;
  - `apps/api/app/Modules/Expense/routes.php`;
  - `apps/api/tests/Feature/Expense/ExpenseExportTest.php`.
- RED: focused PHPUnit exited 1 with 5/5 failures before production code existed. Four requests were swallowed by `expenses/{id}` and returned 404; the deny path also returned 404 instead of the required permission 403. The parity case additionally exposed the pre-existing PostgreSQL-only `ilike` operator under SQLite. The tests already pinned the complete 45-row export despite `per_page=20`, UTF-8 BOM, exact column order, filename, raw decimal strings, VAT fields, status/category/date/search parity with the JSON index, inclusive `date_to`, sibling-company/type exclusion, malformed cross-company partner suppression, legacy null stability, and permission denial.
- Shared query contract: `ExpenseIndexQuery` now owns the Expense type, explicit company scope, status/category/date/search filters for both `ExpenseController::index` and export. `whereLike(..., caseSensitive: false)` preserves PostgreSQL `ILIKE` semantics while making the same search executable in SQLite. The inclusive `date_to` predicate uses an index-friendly exclusive next-day upper bound, covering SQLite's time-suffixed Eloquent test dates without changing the production date-column result.
- Export contract: `GET /api/v1/expenses/export` is registered above `expenses/{id}`, inside the existing authenticated tenant middleware stack, and gated by `expenses.export`. It uses `response()->streamDownload`, emits the UTF-8 BOM and one header row, then iterates the full company-scoped query with `cursor()` regardless of pagination parameters. The filename is `expenses-{date_from}-{date_to}.csv` for filtered dates (`all` for an omitted endpoint bound).
- Data isolation and money: Expense type and owning company are mandatory predicates. Partner/category joins are company-scoped; a malformed sibling-company partner ID cannot disclose its UUID or name and falls back to the stored vendor snapshot. Subtotal, tax/VAT, total, and deductible-percent cells remain strings; the metadata decimal cast normalizes the test database's DECIMAL representation without float conversion. Missing legacy metadata/tax/percent/receipt/category fields serialize as stable empty CSV cells.
- GREEN and verification: focused export/list/show coverage passed 11 tests / 53 assertions. Full Expense feature regression passed 110 tests / 695 assertions. Scoped PHPStan level 8 passed with no errors; scoped Pint passed; `git diff --check` passed. `php artisan route:list --path=api/v1/expenses/export -vv` showed exactly one GET route with the full authenticated tenant middleware chain and `Authorize:expenses.export`.
- Scope: Task 14 only. No Task 15 frontend, analytics aggregation, recurrence, Treasury, fiscal, posting, settlement, migration, or generated-type behavior changed.
- Deviations: none.

## Task 15 — Expense analytics UI, list depth, and CSV download — 2026-07-13

- Files: expense analytics API/types/query hook and CSV download helper; `ExpenseAnalyticsPage`; expense-list filters, tiles, and actions; expense route/sidebar wiring; EN/FR/AR locale resources; and focused API, hook, page, route, sidebar, permission, download, and locale tests.
- TDD evidence: the initial focused page/API run failed on the absent analytics client/page, date-to filter, tiles, and export behavior. The route/hook/navigation run then failed on the absent tenant-scoped key and route/sidebar surfaces. A final parity RED proved the dedicated analytics page queried posted data while exporting all statuses; GREEN now sends explicit `status=posted` to both operations.
- List binding: the expense list, tiles, and CSV export now start coherently at posted status. Choosing explicit All removes status from the list/export filters and sends the analytics endpoint's explicit `status=all` sentinel; omitted analytics status still retains the documented posted default. Search continues to filter the list and its CSV export, while analytics deliberately excludes search and displays a localized explanatory caption. Category plus both inclusive date bounds bind list, tiles, and export.
- Dedicated analytics page: `/expenses/analytics` is lazy-loaded before `expenses/:id` and gated by exact `expenses.view`; the Treasury sidebar item uses the existing expense module permission map. The page starts explicitly at posted status and keeps its analytics/export filters identical across status, category, `date_from`, and `date_to`.
- Reporting surface: four summary tiles use the shared company currency formatter; category mix, top vendors, and monthly totals use the existing `DataTable`; and the category-by-month ledger is horizontally scrollable, RTL-safe, logically sticky, and token-styled. Matrix amounts remain decimal strings, and monthly aggregation uses `big.js` without float conversion. The localized legacy-net caption preserves the Task 13 reporting contract.
- Export contract: the authenticated Axios client requests `/expenses/export` as a blob, the action is rendered only for `expenses.export`, and the helper honors the server filename, clicks a temporary object URL, removes the anchor, and revokes the URL. List export forwards the full list filter set, including search; analytics export forwards the exact visible report filters. Failures surface a localized toast.
- Localization: navigation and complete analytics resources were added for English, French, and Arabic, with a leaf-completeness regression test.
- Verification:
  - Fresh widened Vitest: 16 files passed, 147 tests passed, and 3 existing todos; the pre-existing tenant-scope React `act(...)` warnings remain non-failing.
  - Root `pnpm typecheck`: all participating workspaces passed.
  - Full web lint: exit 0 with 0 errors and existing repository warnings; TanStack audit 0 violations, design audit 753 acknowledged / 0 new / 0 stale, and custom ESLint rules green.
  - Scoped Task 15 ESLint: 0 errors and 0 warnings.
  - React Doctor pinned to Task 15 base `a9e0ee4ea`: **No issues found** (84/100 under v0.7.7).
  - Locale JSON validation and `git diff --check`: pass.
- Scope: Task 15 delivery. The independent-review repair below adds only the necessary analytics `all` status contract; no Task 16, Gate 3, CSV stream, recurrence, Treasury, fiscal, posting, settlement, migration, or generated-type behavior changed. Type generation remains assigned to Task 17.
- Deviations: none.

### Task 15 independent-review fixes — status parity, analytics invalidation, and feedback states

- RA-M2 parity: the initial frontend regression failed because the list queried `{}` while tiles implicitly reported posted data. The backend regression separately received 422 for `status=all`. The analytics request now accepts one shared `AnalyticsFilters::ALL_STATUSES` sentinel, the service conditionally omits only its status predicate for that sentinel, and omitted/empty status remains posted. The list starts posted; explicit All omits list/export status and sends `all` to analytics. GREEN: focused UI parity 1/1; backend analytics 10 tests / 69 assertions, including posted-plus-draft aggregation and invalid-status validation.
- Analytics invalidation: the first mutation regression proved create refetched the list but left analytics at one fetch. A dedicated tenant/company-scoped analytics predicate now refreshes all money-affecting expense mutations (create, update, delete, post, pay) and all category label/grouping mutations (create, rename/update, delete). GREEN: 8/8 mutation cases; sibling-company and foreign-tenant analytics entries remain uninvalidated.
- Embedded summary feedback: three focused RED cases found no accessible loading status, permanent-error feedback, or retry action. The list summary now uses an `aria-live` status with existing tokens and the shared `QueryError`/retry pattern; the expense table remains usable beneath either state. GREEN: 3/3.
- Final verification:
  - Widened expense/notification/permission/route/sidebar Vitest: 23 files, 192 passed, 3 existing todos; pre-existing tenant-scope `act(...)` warnings remain non-failing.
  - Root `pnpm typecheck`: pass.
  - Full web lint: 0 errors with existing repository warnings; TanStack audit 0 violations; design audit 753 acknowledged / 0 new / 0 stale; custom rules pass. Scoped production/page ESLint: 0 errors and 0 warnings.
  - Scoped backend PHPStan level 8 and Pint: pass.
  - React Doctor pinned to Task 15 commit `790087799`, including current changes: **No issues found** (84/100 under v0.7.7).
  - `git diff --check`: pass.
- Scope: necessary Task 15 review repair only. No Task 16 or Gate 3 work started.

## Gate 3 — 2026-07-13

- Autonomous Opus review: **APPROVE**, rc1. Artifact: `docs/handoff/gate-reviews-phase4/GATE-3-rc1.md`.
- All money-path, tenancy, route-order, streamed-export, analytics/list parity, mutation invalidation, accessibility, i18n, permission-map, and tenantScopedKey checks passed. Findings were LOW/INFO only (analytics-page All selector UX, pre-existing list-label refresh behavior, Content-Disposition fallback, and explanatory MoM labeling).
- Verification before gate: analytics 10 tests / 69 assertions; widened frontend 23 files / 192 passed / 3 existing todos; root typecheck, full web lint, TanStack/design audits, scoped PHPStan/Pint, React Doctor, and diff check green.

## Task 16 — Outbound direction guards (complete)

- Files: `apps/api/app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php`, `apps/api/app/Modules/Treasury/Application/Services/InstrumentRemittanceService.php`, `apps/api/tests/Feature/Treasury/OutboundInstrumentGuardTest.php`.
- RED: focused guard suite had 5 failures across the five Outbound guard assertions before implementation; receive/cancel and inbound-clear regressions passed.
- GREEN: focused guard suite `7 tests, 24 assertions`; combined Treasury regression `51 tests, 188 assertions`.
- Scoped PHPStan reported no errors; Pint and `git diff --check` passed.
- Contract: the identical `DomainException` guard is immediately after `findOrFail` in custodyTransfer, deposit, clear, and bounce, and is the first check in `assertEligible`. `receive()`, `cancel()`, and `updateDetails()` remain direction-neutral by deliberate scope; inbound deposit→clear remains green.
- Deviations: none.

## Task 17 — Phase closeout — 2026-07-13

- Files:
  - `packages/shared/types/generated.d.ts` (regenerated by the required transform)
  - `docs/handoff/HANDOFF-outbound-instruments-2026-07-13.md`
  - `docs/handoff/treasury-phase4-deploy-checklist.md`
  - `docs/handoff/treasury-phase4-progress.md`
- Type generation: `cd apps/api && CACHE_STORE=array php artisan typescript:transform` — exit 0; transformed 441 PHP types; generated declarations added the current Expense analytics DTOs and recurrence enums (32 lines).
- i18n audit: Phase ④ additions are present in EN/FR/AR with matching leaf sets: `common.json` navigation 2/2/2, `expenses.json` 77/77/77, and nested notification keys `types.expense.recurring.generated` plus `messages.expense.recurring.generated` 2/2/2. Existing unrelated locale drift is outside this phase.
- Frontend audits: `node tools/audit-design-system.mjs` — baseline 753 acknowledged, 0 new, 0 stale; `node tools/audit-tanstack-keys.mjs` — 0 violations. Expense `_invalidation.ts` audit confirms tenant/company suffix checks for expense list, analytics, category list, recurrence list, and recurrence detail predicates.
- Focused frontend verification: `pnpm vitest run src/features/expenses/__tests__/tenantScope.test.tsx src/features/expenses/hooks/useExpenseRecurrences.test.tsx src/features/expenses/pages/ExpenseAnalyticsLocales.test.ts` — 3 files, 31 tests passed; existing React `act(...)` warnings remain non-failing.
- Preflight: `./scripts/preflight.sh` — exit 1 at the initial Pint check because 18 pre-existing unrelated Product/Fiscal/Inventory/Company test files require formatting; no Phase ④ file was reported. The exact preflight output is retained in `/tmp/task17-preflight.log` during this run; no environment workaround or source mutation was applied.
- Closeout docs: the outbound handoff records the exact §12 first-bullet deferral and the five Phase ④ inbound-lifecycle guards; the deploy checklist reproduces spec §10 verbatim, including tenant migration, permission reseed/cache reset, scheduler verification, `VatDeductible` presence verification, and no Horizon change.
- Deviations: none from the Phase ④ plan. Preflight remains blocked by repository-wide pre-existing formatting drift as documented above; Gate 4 is intentionally not run in Task 17.
