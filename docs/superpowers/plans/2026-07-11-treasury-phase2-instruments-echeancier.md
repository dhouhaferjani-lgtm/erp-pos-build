# Treasury Phase ② — Instrument Portfolio + Échéancier — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Spec:** `docs/superpowers/specs/2026-07-10-treasury-phase2-instruments-echeancier-design.md` (**Rev 2** — read it first; §7 posting tables, §6 lock order, and §20 reconciliation are binding). **Spec review:** `docs/superpowers/specs/reviews/2026-07-11-treasury-phase2-adversarial-review.md`.
> **Status:** Rev 1 — awaiting plan adversarial review, then OWNER GATE before any dispatch.

**Goal:** Give chèques/traites an accounting-visible lifecycle (GL at every stage per PCG/PCE), one portfolio regardless of capture point (web or POS), a remise-en-banque bordereau, impayé depth, and an échéancier that answers "what clears when" — with cash moving ONLY at clearing/dishonor through the Phase-① port.

**Architecture:** Account-as-state GL-transit model. Instrument value lives in country-mapped transit accounts (FR 5112/413/5113 ↔ TN 5312/413/5313, resolved by a new `InstrumentAccountResolver`); a single context-free `InstrumentLifecycleService` owns every transition (status guard under row lock → document locks → `postEntryNow` → port `record()` → mutate → append `instrument_events`); deferred tenders stop moving repository balance at receipt (debit-line swap); the POS bridges mint instruments idempotently from `has_maturity` tender legs.

**Tech Stack:** Laravel 12 / PHP 8.2 strict, PostgreSQL 16 (db-per-tenant), PHPUnit (by path, pgsql leg for triggers), React 19 / TanStack Query 5 / Vitest, Playwright.

## THE GLOBAL LOCK ORDER (spec §6, review T1/T13 — binding on every flow this plan touches)

> **instrument row(s), id-sorted → document row(s), id-sorted → GL company advisory lock (`postEntryNow`) → repository row (the port).**

The B2B payment flow already locks documents before GL (`PaymentController.php:670-674` → `:781/:794`); the lifecycle service and the bounce path MUST match it. No flow may take the GL advisory lock and then lock an instrument or document row. `PaymentController` locks a linked instrument BEFORE `postEntryNow`. Slip operations lock their instruments id-sorted.

## Executor notes

- **Worktree:** `feat/treasury-instruments` off dev via superpowers:using-git-worktrees at execution start. Commit every green step; **never push — owner promotes** (rule 21). Worktree backend gotchas apply (symlinked vendor runs stale code; `typescript:transform` needs `CACHE_STORE=array`; tests BY PATH).
- **Verified line references** (from the 2026-07-10/11 survey + review) may drift a few lines — re-grep before editing, don't trust blindly.
- **Migrations** are tenant migrations (`apps/api/database/migrations/tenant/`), re-runnable (`hasColumn`/`hasTable` guards, `DROP TRIGGER/FUNCTION IF EXISTS`), applied with `php artisan tenants:migrate`.
- **Test helpers** referenced in examples are defined in the task that first uses them or replaced with existing factory/seeder patterns — verify a factory exists before calling `::factory()`.
- **pgsql-only artifacts** (partial unique indexes, triggers, `balance_due` trigger assertions): tag those tests for the pgsql CI leg (`treasury-spine-pgsql` job — Task 28 extends its path list); sqlite runs skip them via `@requires` / driver checks, or maintain state imperatively as `PaymentController` does.
- **JE builders:** new instrument entry builders go in `GeneralLedgerService` next to the POS builders (`createPOSPaymentEntry` `:2565+`) — the established pattern; every one stamps `source_type`, `source_id`, and `journal_code => JournalCode::Effets->value`, and is posted with `postEntryNow()` (never afterCommit — spine BLOCKER-1).

## Global Constraints

- Constructor injection only, `private readonly`, never `app()` (rule 13).
- No float on money: `bcadd`/`bcsub`/`bccomp` + `CurrencyScale::bcformatStrict` at `$scaleResolver->getScale($currency)`; **explicit currency in every projection/console context** (rules 19/20).
- Strict typing: no `mixed`/`any`; jsonb payloads get PHP DTOs (rule 3). Enums for every status/type/direction column (rule 9).
- Routes: `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]` + `can:<perm>` (rule 12, current 4-tuple).
- TS types via `php artisan typescript:transform`; never hand-edit `packages/shared/types` (rule 7).
- `apiGet`/`apiPost` already unwrap; paginated `{data,meta}` uses `api.get` (rule 14). Tenant query keys via `tenantScopedKey([...])`.
- FE text via `t()` en+fr (rule 11); money via `formatCurrency`/`<MoneyInput>`; design tokens only in new directories (rule 18).
- Amounts `decimal(15,3)`; UUID route params `Str::isUuid()`-guarded → 404.
- Projection tests `app(CompanyContext::class)->clear()` before `apply()` (rule 20).
- NEVER run the full PHPUnit suite; vitest by path; kill hung vitest worker pools.
- **The §5.5 reservation:** only Phase-② code posts to the portfolio purpose accounts — enforced by an architecture test from Task 1 onward.

## File Structure

**New:**
- Migrations: `*_add_instrument_portfolio_columns_to_payment_instruments.php`, `*_add_instrument_kind_to_payment_methods.php`, `*_create_instrument_events.php`, `*_create_instrument_events_immutability.php`, `*_create_instrument_remittances.php`, `*_add_dishonored_at_to_payments.php`, `*_add_instrument_alert_days_to_country_payment_settings.php`
- `apps/api/app/Modules/Treasury/Domain/Enums/{InstrumentDirection,InstrumentKind,InstrumentOrigin,DishonorRouting,InstrumentEventType,RemittanceType,RemittanceStatus,RemittanceLineStatus}.php`
- `apps/api/app/Modules/Treasury/Domain/{InstrumentEvent,InstrumentRemittance,InstrumentRemittanceLine}.php`
- `apps/api/app/Modules/Treasury/Domain/Events/InstrumentReceived.php`
- `apps/api/app/Modules/Treasury/Application/Services/{InstrumentAccountResolver,InstrumentLifecycleService,InstrumentRemittanceService}.php`
- `apps/api/app/Modules/Treasury/Application/DTOs/{InstrumentEventPayload,ReceiveInstrumentData,ClearInstrumentData,BounceInstrumentData}.php`
- `apps/api/app/Modules/Treasury/Presentation/Controllers/{InstrumentRemittanceController,MaturingInstrumentsController}.php`
- `apps/api/app/Modules/Treasury/Presentation/Console/InstrumentMaturityAlertsCommand.php`
- FE: `apps/web/src/features/treasury/{RemittanceListPage,RemittanceDetailPage,RemittanceCreatePage}.tsx`, hooks `useMaturingInstruments.ts`, `useRemittances.ts`, `useInstrumentEvents.ts`; Overview `EcheancierPanel.tsx`

**Modified:**
- `apps/api/app/Modules/Treasury/Domain/{PaymentInstrument,PaymentMethod}.php`, `Enums/InstrumentStatus.php`
- `apps/api/app/Modules/Treasury/Presentation/Controllers/{PaymentInstrumentController,PaymentController,MultiPaymentController,PaymentMethodController}.php`, `Presentation/routes.php`
- `apps/api/app/Modules/Treasury/Application/Services/PaymentRefundService.php`
- `apps/api/app/Modules/Treasury/Application/Projections/{TreasuryReceiptBridge,TreasuryDepositBridge,TreasuryAccountPaymentBridge}.php`
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php` (instrument entry builders, POS cash-account override), `Domain/Enums/JournalCode.php`
- `apps/api/app/Modules/Accounting/Application/Services/Reports/UpcomingPaymentsService.php`
- `apps/api/app/Modules/Treasury/Presentation/Console/ReconcileTreasuryCommand.php` (check #4)
- `apps/api/database/seeders/{TunisiaChartOfAccountsSeeder,FranceChartOfAccountsSeeder,GenericChartOfAccountsSeeder,PaymentMethodSeeder,RolesAndPermissionsSeeder}.php`
- `apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php`
- `apps/api/routes/console.php` (scheduler)
- FE: `PaymentForm.tsx`, `InstrumentListPage.tsx`, `InstrumentDetailPage.tsx`, `TreasuryOverviewPage.tsx`, `hooks/usePermissions.ts`, `routes/index.tsx`, `Sidebar.tsx`, locales `en|fr/treasury.json`

---

## WAVE A — Foundation (schema, enums, accounts; zero behavior change)

### Task 1: Portfolio purpose accounts + `InstrumentAccountResolver` + `JournalCode::Effets`

**Files:**
- Modify: `apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php`, `FranceChartOfAccountsSeeder.php`, `GenericChartOfAccountsSeeder.php` (mirror the 6580/7580 tolerance-account precedent, keyed by `code`)
- Modify: `apps/api/app/Modules/Accounting/Domain/Enums/JournalCode.php`
- Create: `apps/api/app/Modules/Treasury/Application/Services/InstrumentAccountResolver.php`
- Test: `apps/api/tests/Feature/Treasury/InstrumentAccountResolverTest.php`, `apps/api/tests/Architecture/PortfolioAccountReservationTest.php`

**Interfaces:**
- Produces: enum `InstrumentAccountPurpose: string { ChecksToCollect, EffectsReceivable, EffectsInCollection, EffectsDiscounted, InstrumentBankFees, VatRecoverableOnFees, DoubtfulReceivables }` (Treasury Domain Enums); `InstrumentAccountResolver::resolve(InstrumentAccountPurpose $purpose, string $companyId): string` (account uuid) + `resolveOrFail(...)` throwing `MissingInstrumentAccountException` (checked: interactive callers convert to 422 pre-transaction, projections let it propagate — spec §5.5/F4); `JournalCode::Effets = 'EF'` with `fromSourceType()` mapping `'instrument'`, `'instrument_remittance'` → `Effets`.
- Seeded codes (spec §3): TN `5312, 5313, 5314` (parent 531), `6275` (parent 627 family — verify TN parent code in seeder structure), `43666`, `416`; FR `5112, 5113, 5114` (parent 511), `627`, `44566`, `416`. Generic mirrors FR.

- [ ] **Step 1: Failing tests.** (a) Resolver test: seed a TN company chart → `resolve(ChecksToCollect)` returns the account whose `code='5312'`; FR company → `'5112'`; missing account → `MissingInstrumentAccountException`. (b) `JournalCode::fromSourceType('instrument')` === `Effets` (and `'instrument_remittance'`); unmapped types still fall to `Misc`. (c) Architecture test: grep-based assertion that no file outside `app/Modules/Treasury/` + `GeneralLedgerService` instrument builders references the purpose account codes / resolver (start permissive: assert the resolver is the only `where('code', '5312'|'5112'...)` call site).
- [ ] **Step 2: Run — expect FAIL** (`cd apps/api && ./vendor/bin/phpunit tests/Feature/Treasury/InstrumentAccountResolverTest.php`).
- [ ] **Step 3: Implement.** Seeder arrays gain the accounts (idempotent — the seeders `updateOrCreate` by code; confirm exact mechanism against the 6580 block before copying). Resolver = constructor-injected query service mapping purpose→code per company country (read the company's chart the same way the tolerance flow does — grep `6580` in `RepositoryAdjustmentController`/its service for the resolution pattern and mirror it). Add the enum case + mapping.
- [ ] **Step 4: Run — expect PASS.** Re-run seeder twice in the test → no duplicates.
- [ ] **Step 5: Commit** `feat(treasury): portfolio purpose accounts (TN/FR) + InstrumentAccountResolver + EF journal code`.

### Task 2: `payment_instruments` portfolio columns + enums + model rework

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_12_100000_add_instrument_portfolio_columns_to_payment_instruments.php`
- Create: `apps/api/app/Modules/Treasury/Domain/Enums/{InstrumentDirection,InstrumentKind,InstrumentOrigin,DishonorRouting}.php`
- Modify: `apps/api/app/Modules/Treasury/Domain/PaymentInstrument.php`, `apps/api/app/Modules/Treasury/Domain/Enums/InstrumentStatus.php`
- Test: `apps/api/tests/Feature/Treasury/PaymentInstrumentPortfolioColumnsTest.php`

**Interfaces:**
- Produces (spec §5.1): columns `direction varchar(10) default 'inbound'`, `kind varchar(10)` (backfill existing rows: `cheque` when the method's code contains CHECK/CHEQUE else `other` — brownfield staging only, no production tenants), `origin varchar(5) default 'web'`, `bank_id uuid null` (**indexed, NO FK** — T15), `idempotency_key varchar null` + **pgsql partial unique index** `WHERE idempotency_key IS NOT NULL` (precedent: `2026_07_08_150000_add_idempotency_key_to_payments.php`), `remittance_id uuid null` (FK added in Task 5's migration ordering — leave plain uuid here, constrain there), `needs_details boolean default false`, `dishonor_routing varchar(12) null`. Enums: `InstrumentDirection {Inbound='inbound', Outbound='outbound'}`, `InstrumentKind {Cheque='cheque', Effet='effet', Other='other'}`, `InstrumentOrigin {Web='web', Pos='pos'}`, `DishonorRouting {RePresent='re_present', Receivable='receivable', Doubtful='doubtful'}`.
- Model: casts for all new enums; `payment(): BelongsTo` relation (column exists, relationless today — `PaymentInstrument.php:83`); `remittance(): BelongsTo`; scope `scopePendingPortfolio` (status in Received/Deposited/Bounced).
- `InstrumentStatus` guard changes (spec §6): `canDeposit()` gains `Bounced`; `canBounce()` gains `Cleared`; `isTerminal()` drops `Cleared`; docblock marks `InTransit/Clearing/Expired/Collected` reserved-dormant.

- [ ] **Step 1: Failing test** — create an instrument via the model with the new fields; assert enum casts round-trip; assert `InstrumentStatus::Bounced->canDeposit()` true, `Cleared->canBounce()` true, `Cleared->isTerminal()` false; duplicate `idempotency_key` insert throws (pgsql), two NULL keys coexist.
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Implement** migration (hasColumn-guarded; raw `CREATE UNIQUE INDEX ... WHERE idempotency_key IS NOT NULL` inside `DB::getDriverName()==='pgsql'` guard) + enums + model.
- [ ] **Step 4: Run — expect PASS** (pgsql assertions under the pgsql env; see Executor notes).
- [ ] **Step 5: Commit** `feat(treasury): payment_instruments portfolio columns (direction/kind/origin/idempotency/needs_details/dishonor_routing)`.

### Task 3: `payment_methods.instrument_kind` + method-config guards

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_12_100100_add_instrument_kind_to_payment_methods.php`
- Modify: `apps/api/app/Modules/Treasury/Domain/PaymentMethod.php`, `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentMethodController.php`, `apps/api/database/seeders/PaymentMethodSeeder.php`
- Test: `apps/api/tests/Feature/Treasury/PaymentMethodInstrumentKindTest.php`

**Interfaces:**
- Produces (spec §5.4): nullable `instrument_kind varchar(10)` cast to `InstrumentKind`; seeder maps CHECK/CHEQUE→`cheque`, TRAITE/PDC/BILL_OF_EXCHANGE→`effet` (grep the seeder's method codes first — use the ACTUAL seeded codes); controller store/update validation: 422 when `has_maturity=true` and no `instrument_kind`; 422 when `has_maturity=true` AND `PaymentInstrumentKind::requiresInstrumentForMethodCode($code)` is true (voucher-collision guard, F7 — import from `App\Modules\POS\Domain\Enums`).

- [ ] **Step 1: Failing test** — creating a maturity method without kind → 422 `{error:{errors}}` envelope; with kind → 201 and cast round-trips; a maturity method whose code is a voucher code → 422; seeder run twice → TRAITE row has `instrument_kind='effet'`, no dupes.
- [ ] **Step 2: Run — FAIL.**
- [ ] **Step 3: Implement** (migration guarded; validation added to BOTH store and update inline `$request->validate` blocks — `PaymentMethodController.php:70-74/107-111`).
- [ ] **Step 4: Run — PASS.**
- [ ] **Step 5: Commit** `feat(treasury): payment_methods.instrument_kind + maturity/voucher config guards`.

### Task 4: `instrument_events` table + immutability trigger + model + DTO

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_12_100200_create_instrument_events.php`, `2026_07_12_100300_create_instrument_events_immutability.php`
- Create: `apps/api/app/Modules/Treasury/Domain/InstrumentEvent.php`, `Domain/Enums/InstrumentEventType.php`, `Application/DTOs/InstrumentEventPayload.php`
- Test: `apps/api/tests/Feature/Treasury/InstrumentEventsImmutabilityTest.php`

**Interfaces:**
- Produces (spec §5.2): table `instrument_events` (columns per spec: id/tenant/company/instrument_id/event_type/from_status/to_status/from_repository_id/to_repository_id/remittance_id/journal_entry_id/movement_id/payload jsonb/occurred_at/created_by/created_at; indexes `(instrument_id, created_at)`, `(tenant_id, company_id)`); enum `InstrumentEventType {Created, DetailsUpdated, CustodyTransferred, Remitted, Cleared, Bounced, RePresented, Cancelled}`; `InstrumentEventPayload` DTO (readonly; nullable fields: detailsDiff array, dishonorRouting, feeAmount, feeVatAmount, reason, alertKey) with `toArray()/fromArray()`; pgsql BEFORE UPDATE/DELETE/TRUNCATE trigger **in the `DROP ... IF EXISTS` re-runnable form** (copy `2026_07_08_100200_create_repository_movements_immutability.php`, apply the N6 lesson).
- Model: `HasUuids`, guarded, casts (`event_type` enum, `payload` → array consumed via the DTO), relations `instrument()`, `journalEntry()`, `movement()`.

- [ ] **Step 1: Failing test** — insert an event row; UPDATE throws (pgsql), DELETE throws; migration runs 3× cleanly (re-runnable).
- [ ] **Step 2: Run — FAIL.** · **Step 3: Implement.** · **Step 4: Run — PASS (pgsql).**
- [ ] **Step 5: Commit** `feat(treasury): instrument_events append-only lifecycle log + immutability trigger`.

### Task 5: `instrument_remittances` + lines + numbering

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_12_100400_create_instrument_remittances.php`
- Create: `apps/api/app/Modules/Treasury/Domain/{InstrumentRemittance,InstrumentRemittanceLine}.php`, `Domain/Enums/{RemittanceType,RemittanceStatus,RemittanceLineStatus}.php`
- Test: `apps/api/tests/Feature/Treasury/InstrumentRemittanceSchemaTest.php`

**Interfaces:**
- Produces (spec §5.3): `instrument_remittances` (id/tenant/company/number unique per company/`remittance_type` {collection,discount}/`instrument_kind`/bank_repository_id FK payment_repositories/status {draft,remitted,closed}/remitted_at/journal_entry_id null/created_by/notes/timestamps) + `instrument_remittance_lines` (id/remittance_id FK/instrument_id FK/amount decimal(15,3)/line_status {pending,cleared,bounced}/cleared_at/bounced_at; **unique `(remittance_id, instrument_id)`**); this migration also adds the FK `payment_instruments.remittance_id → instrument_remittances` (deferred from Task 2).
- Numbering: `InstrumentRemittance::allocateNumber(string $companyId): string` → `REM-{YYYY}-{0001}`; allocation takes `pg_advisory_xact_lock(hashtextextended('remittance_number:'.$companyId, 0))` then `max()+1` within the year — same discipline as the spine's JE-number race fix (spec §19.1; on sqlite the advisory lock no-ops, which is fine for single-threaded tests).

- [ ] **Step 1: Failing test** — schema + relations round-trip; `allocateNumber` twice → `REM-2026-0001`, `REM-2026-0002`; two concurrent allocations (pgsql, two connections) never collide; duplicate `(remittance_id, instrument_id)` line throws.
- [ ] **Step 2: Run — FAIL.** · **Step 3: Implement.** · **Step 4: Run — PASS.**
- [ ] **Step 5: Commit** `feat(treasury): instrument_remittances + lines schema, gapless per-company numbering`.

---

## WAVE B — The lifecycle service (net-new; no HTTP callers yet)

### Task 6: `InstrumentLifecycleService` core — receive / custodyTransfer / cancel + GL builders (receipt & cancel)

**Files:**
- Create: `apps/api/app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php`, `Application/DTOs/ReceiveInstrumentData.php`
- Modify: `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php` (add `createInstrumentCancellationEntry`)
- Create: `apps/api/app/Modules/Treasury/Domain/Events/InstrumentReceived.php`
- Test: `apps/api/tests/Feature/Treasury/InstrumentLifecycleReceiveTest.php`

**Interfaces:**
- Produces — **all entrypoints context-free (explicit ids/currency; F6), caller owns NO transaction (the service opens its own `DB::transaction`)**:
  - `receive(ReceiveInstrumentData $data): PaymentInstrument` — creates the instrument (`Received`) + `Created` event. `ReceiveInstrumentData` (readonly): tenantId, companyId, paymentMethodId, kind (`InstrumentKind`), direction, origin, reference, amount (numeric-string), currency, repositoryId, partnerId?, drawerName?, maturityDate?, receivedDate, bankId?, bankName?, bankBranch?, bankAccount?, idempotencyKey?, needsDetails, createdBy?. **Creates NO JE itself** — receipt-side GL belongs to the payment flow (§7/§8); a manually registered instrument (no payment) likewise posts nothing until remise/settlement (it has no Cr-side yet — document this in the method docblock).
  - `custodyTransfer(string $instrumentId, string $toRepositoryId, ?string $userId): void` — guard `canTransfer()`, updates `repository_id`, `CustodyTransferred` event, **no GL, no movement**.
  - `cancel(string $instrumentId, ?string $userId, string $reason): void` — guards (spec §12.4): status `Received` AND (no linked payment OR payment dishonored/reversed); when a receipt JE exists for it (payment-linked), posts contre-passation via `createInstrumentCancellationEntry` (mirror image of the original receipt debit: interactive B2B → Dr 411 / Cr P-or-R; POS-origin → Dr ProductRevenue / Cr P-or-R — the caller passes which shape via an enum arg `CancellationShape {B2b, PosRevenue}`); `Cancelled` event.
  - `updateDetails(string $instrumentId, array $changes, ?string $userId): PaymentInstrument` — `Received`-only, whitelisted fields (spec §9 completion), `DetailsUpdated` event with diff payload, clears `needs_details` when reference+maturity present.
  - Lock discipline inside every method: `PaymentInstrument::query()->lockForUpdate()->findOrFail()` FIRST, then any GL, per the global lock order.

- [ ] **Step 1: Failing tests** — receive persists instrument+event atomically (event row has from_status null → to_status received); custodyTransfer from `Deposited` → domain exception; cancel of a payment-linked non-dishonored instrument → exception; cancel of an unlinked Received instrument → status Cancelled + event, no JE; cancel with `CancellationShape::B2b` on a payment-linked-but-reversed instrument posts a balanced JE with Dr on the 411-family account and Cr on the portfolio account (resolve via Task-1 resolver), `journal_code='EF'`, `source_type='instrument'`.
- [ ] **Step 2: Run — FAIL.**
- [ ] **Step 3: Implement.** Constructor: `GeneralLedgerService`, `TreasuryMovementServiceInterface`, `InstrumentAccountResolver`, `CurrencyScaleResolverInterface` (`private readonly`). Register in `TreasuryServiceProvider`. Two concurrent cancels: second 422s (status re-check under lock).
- [ ] **Step 4: Run — PASS.** · **Step 5: Commit** `feat(treasury): InstrumentLifecycleService core (receive/custody/cancel/updateDetails)`.

### Task 7: `remit()` — slip composition + remise JE (effets) + deposit-wrapper rewire

**Files:**
- Create: `apps/api/app/Modules/Treasury/Application/Services/InstrumentRemittanceService.php`
- Modify: `GeneralLedgerService.php` (add `createInstrumentRemittanceEntry`), `InstrumentLifecycleService.php`
- Test: `apps/api/tests/Feature/Treasury/InstrumentRemittanceServiceTest.php`

**Interfaces:**
- Produces: `InstrumentRemittanceService` — `createDraft(companyId, tenantId, bankRepositoryId, RemittanceType, InstrumentKind, userId): InstrumentRemittance` (validates bank repo `type === 'bank_account'`, reusing the deposit guard logic from `PaymentInstrumentController.php:175`); `addLine(remittanceId, instrumentId)` / `removeLine` (draft-only; instrument must be `Received` — or `Bounced` with `dishonor_routing=re_present` (spec §12) — same kind, same company, not already on a draft/remitted slip); `remit(remittanceId, userId): InstrumentRemittance` — in one transaction, **lock line instruments id-sorted**, re-validate, then: effets slips post ONE JE Dr `EffectsInCollection` / Cr `EffectsReceivable` for the slip total (`source_type='instrument_remittance'`, `source_id=remittance id`, journal_code EF) via `postEntryNow`; **cheque slips post NO JE** (§7 — checks stay in P); re-presented cheque lines post Dr `ChecksToCollect` / Cr routing-account (§7 re-presentation) per line; stamp instruments `Deposited` + `deposited_at` + `deposited_to_id`, write `Remitted` (or `RePresented`) events with `remittance_id` + `journal_entry_id`; status → `remitted`.
- `InstrumentLifecycleService::deposit(string $instrumentId, string $bankRepositoryId, ?string $userId)` = the single-instrument wrapper: creates+remits a one-line slip (keeps the legacy `POST /payment-instruments/{id}/deposit` URL contract — spec §6).

- [ ] **Step 1: Failing tests** — effet slip of 2 instruments: one JE, total = sum, both instruments Deposited, 2 events, slip `remitted`; cheque slip: NO JE, statuses move; mixed-kind addLine → exception; addLine on a remitted slip → exception; non-bank repository → exception; re-presentation: bounced effet with routing `re_present` remits again (per §7 its value never left 413, so it rides the normal slip JE — assert no double-posting by checking the slip JE total includes it exactly once); bounced cheque re-presented → per-line Dr P / Cr 411 JE; GL failure injected → nothing persists (slip, statuses, events all rolled back).
- [ ] **Step 2: Run — FAIL.** · **Step 3: Implement.** · **Step 4: Run — PASS.**
- [ ] **Step 5: Commit** `feat(treasury): remittance service — bordereau composition + remise GL + deposit wrapper`.

### Task 8: `clear()` — bank credit, fees, movement(in)

**Files:**
- Modify: `InstrumentLifecycleService.php`, `GeneralLedgerService.php` (add `createInstrumentClearingEntry`)
- Create: `Application/DTOs/ClearInstrumentData.php`
- Test: `apps/api/tests/Feature/Treasury/InstrumentClearTest.php`

**Interfaces:**
- Produces: `clear(ClearInstrumentData $data): PaymentInstrument` — data: instrumentId, feeAmount ('0.000' default), feeVatAmount ('0.000'), valueDate?, userId?, **explicit currency**. Guards: status `canClear()`; instrument on a `remitted` slip (line `pending`). One transaction: lock instrument → build JE per §7 (cheque: Dr bank `gl_account_id` net + Dr `InstrumentBankFees` + Dr `VatRecoverableOnFees` / Cr `ChecksToCollect` nominal; effet: same but Cr `EffectsInCollection`) → `postEntryNow` → port `record()` **in**, amount = `bcsub(nominal, bcadd(fee, feeVat))`, repository = the slip's `bank_repository_id`, `sourceType: MovementSourceType::Instrument`, `sourceId: instrument id`, `idempotencyLeg: "clear:{line_id}"`, `journalEntryId` linked, `allowWhileFrozen: false` → instrument `Cleared`+`cleared_at`, line `cleared`, slip auto-`closed` when last line settles → `Cleared` event (journal_entry_id + movement_id).
- **Reconcile-#2 invariant pinned:** movement amount MUST equal the JE's bank-account lines (net) exactly — `ReconcileTreasuryCommand.php:405-423` freezes on mismatch (T9/T22).

- [ ] **Step 1: Failing tests** — clear with zero fees: movement in = nominal, JE 2 lines, balance up by nominal; clear with fee 1.000 + VAT 0.190 (TND scale 3): movement = nominal − 1.190, JE lines: bank net, fees 1.000, VAT 0.190, Cr nominal — assert `bccomp` equality and JE balance; **run `treasury:reconcile` in-test on that repo → clean (0 drift)**; double-clear (concurrent) → one movement, second 422; clear an instrument not on a remitted slip → 422; GL-fail injection → full rollback.
- [ ] **Step 2: Run — FAIL.** · **Step 3: Implement.** · **Step 4: Run — PASS** (+ pgsql leg for the reconcile assertion). · **Step 5: Commit** `feat(treasury): instrument clearing — bank credit + fees via the movement port`.

### Task 9: `bounce()` — routing, fees, allocation reopening, dishonor-after-clear

**Files:**
- Modify: `InstrumentLifecycleService.php`, `GeneralLedgerService.php` (add `createInstrumentDishonorEntry`), `apps/api/app/Modules/Treasury/Domain/Payment.php` (+`dishonored_at`)
- Create: `Application/DTOs/BounceInstrumentData.php`, migration `2026_07_12_100500_add_dishonored_at_to_payments.php`
- Test: `apps/api/tests/Feature/Treasury/InstrumentBounceTest.php`

**Interfaces:**
- Produces: `bounce(BounceInstrumentData $data)` — data: instrumentId, routing (`DishonorRouting`, required), feeAmount/feeVatAmount ('0.000'), reason?, userId?, currency. **Lock order (T1): instrument → allocated documents id-sorted → GL advisory → repository.** Posting per §7 (both flavors):
  - **before clear** (status Deposited): JE Dr routing-account / Cr `EffectsInCollection`-or-`ChecksToCollect` (nominal); optional fee JE lines + Dr fees + Dr VAT / Cr bank with **movement out = `bcadd(fee, feeVat)`** leg `bounce_fee:{line_id}` (T9);
  - **after clear** (status Cleared): JE Dr routing-account (nominal) + Dr fees + Dr VAT / Cr bank (nominal+fees) with **movement out = `bcadd(nominal, bcadd(fee, feeVat))`** leg `dishonor:{line_id}`.
  - Routing-account map (§12): effet `re_present`→`EffectsReceivable` (413); cheque `re_present`→411 (customer AR — resolve the SAME receivable account the payment flow credits; grep `createPaymentReceivedJournalEntry` for the AR account source); `receivable`→411; `doubtful`→`DoubtfulReceivables` (416).
  - **Subledger reopening** (routing `receivable`/`doubtful`, linked payment exists): write **negative-amount `PaymentAllocation` rows** mirroring each of the payment's allocations (never DELETE — T4); reverse tolerance allocations tied to the payment + contre-passation of the tolerance write-off JE (`CloseInvoiceWithToleranceService.php:108-118` — grep how the tolerance allocation is marked, e.g. a type/flag column, before writing); revert each reopened document `status` `Paid` → `Posted` (T5 — first verify the document-immutability trigger `2025_12_11_054716` permits the write; if it blocks, extend its allowlist in this task's migration); set `payments.dishonored_at = now()`. On sqlite (no balance trigger) maintain `balance_due` imperatively exactly as `PaymentController` does; pgsql CI asserts the trigger path.
  - Routing `re_present`: allocations untouched; instrument keeps `dishonor_routing='re_present'` enabling Task-7 re-remise.
  - `Bounced` status + `bounced_at` + `bounce_reason` + `dishonor_routing`; line `bounced`; slip auto-close check; `Bounced` event with payload (routing, fees).

- [ ] **Step 1: Failing tests** — (a) effet bounce-before-clear, routing `receivable`, no fees: JE Dr 411 / Cr 5313-family, NO movement, allocations reversed (negative rows), invoice `balance_due` reopened AND status back to `Posted`, payment `dishonored_at` set; (b) cheque bounce with fees: fee movement out = fee+VAT, reconcile clean after; (c) dishonor-after-clear: movement out = nominal+fees, JE Cr bank total matches movement; (d) routing `re_present`: allocations untouched, invoice stays Paid; (e) tolerance-closed invoice: reopened `balance_due` equals the FULL original receivable (tolerance write-off reversed); (f) concurrent bounce vs payment on the same documents — no deadlock (lock-order test, pgsql, two connections); (g) GL-fail injection → nothing persists.
- [ ] **Step 2: Run — FAIL.** · **Step 3: Implement.** · **Step 4: Run — PASS.** · **Step 5: Commit** `feat(treasury): instrument dishonor — routing, fees, allocation reopening, claw-back`.

### Task 10: Audit wiring — instrument events → `audit_events`

**Files:**
- Modify: `apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php`
- Test: `apps/api/tests/Feature/Compliance/InstrumentAuditTrailTest.php`

**Interfaces:**
- Produces: `InstrumentReceived/Deposited/Cleared/Bounced/Transferred` all subscribed → `audit_events` rows (mirror `handleRepositoryMovementRecorded`, `DomainEventSubscriber.php:969/:1067`). Lifecycle service dispatches each after commit (`DB::afterCommit` for the event dispatch ONLY — the writes are already durable).

- [ ] **Step 1: Failing test** — a full receive→deposit→clear cycle leaves 3 typed `audit_events` rows. · **Step 2: FAIL.** · **Step 3: Implement.** · **Step 4: PASS.** · **Step 5: Commit** `feat(compliance): instrument lifecycle events land in audit_events`.

---

## WAVE C — HTTP surface (instruments + remittances)

### Task 11: `PaymentInstrumentController` rework — thin delegation, company scoping, PATCH, pagination, permissions

**Files:**
- Modify: `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentInstrumentController.php`, `apps/api/app/Modules/Treasury/Presentation/routes.php`, `apps/api/database/seeders/RolesAndPermissionsSeeder.php`
- Test: `apps/api/tests/Feature/Treasury/PaymentInstrumentControllerTest.php` (extend existing if present — grep first)

**Interfaces:**
- Produces (spec §13): all endpoints add the **`company_id` predicate** (T16) + `Str::isUuid()` → 404 guards; `index` paginated with `meta` + filters (status, kind, direction, partner_id, repository_id, `needs_details`, maturity date window); `store` delegates to `receive()` (currency defaults to **company currency**, not 'TND' — T17; kind snapshots from the method; 422 pre-transaction on missing portfolio account for payment-linked creates); `PATCH /payment-instruments/{id}` → `updateDetails()` under new `can:instruments.update`; `deposit` → wrapper (Task 7); `clear` → `clear()` with optional `fee_amount`/`fee_vat_amount`/`value_date` (money regex `^\d+(\.\d{1,3})?$`); `bounce` → `bounce()` with required `routing` + optional fees, moved to new `can:instruments.bounce`; `transfer` → `custodyTransfer()`. New permissions `instruments.update`, `instruments.bounce`, `instruments.remit` seeded to admin/owner/manager/accountant-equivalent roles (grep the role list `RolesAndPermissionsSeeder.php:210-213` and mirror `instruments.clear`'s grants).
- Response shape: keep `formatInstrument` (`:351`) extended with the new fields (kind, direction, origin, needs_details, dishonor_routing, remittance_id, bank_id).

- [ ] **Step 1: Failing tests** — company-B user cannot see/transition company-A instruments (404); PATCH on Received updates + event row; PATCH on Deposited → 422; bounce without `instruments.bounce` → 403; index returns `meta.total` and respects `needs_details=true` filter; store without currency lands company currency.
- [ ] **Step 2: FAIL.** · **Step 3: Implement.** · **Step 4: PASS.** · **Step 5: Commit** `feat(treasury): instrument endpoints — company scoping, PATCH completion, granular permissions, pagination`.

### Task 12: Remittance endpoints

**Files:**
- Create: `apps/api/app/Modules/Treasury/Presentation/Controllers/InstrumentRemittanceController.php`
- Modify: `routes.php`
- Test: `apps/api/tests/Feature/Treasury/InstrumentRemittanceApiTest.php`

**Interfaces:**
- Produces (spec §13): `POST /instrument-remittances` (draft; body: bank_repository_id, remittance_type, instrument_kind) · `GET /instrument-remittances` (paginated, filters status/kind/bank repo) · `GET /instrument-remittances/{id}` (with lines + instruments) · `POST /{id}/lines` `{instrument_id}` · `DELETE /{id}/lines/{lineId}` (draft-only) · `POST /{id}/remit` · `POST /{id}/lines/{lineId}/clear` (fees body) · `POST /{id}/lines/{lineId}/bounce` (routing + fees) — create/compose/remit under `can:instruments.remit`; line clear/bounce under `can:instruments.clear`/`can:instruments.bounce`; all company-scoped, UUID-guarded, delegating to the Wave-B services; domain exceptions → 422 canonical envelope.

- [ ] **Step 1: Failing tests** — happy path draft→2 lines→remit→line clear→slip closed via API; draft slip deletable, remitted not; cross-company slip access 404; permission matrix (remit vs clear vs bounce).
- [ ] **Step 2: FAIL.** · **Step 3: Implement.** · **Step 4: PASS.** · **Step 5: Commit** `feat(treasury): remittance (bordereau) API`.

---

## WAVE D — Web payment-flow cutover (G7 + cash-recognition fix)

### Task 13: `PaymentController::store()` customer-direction cutover

**Problem (verified):** today a check/traite customer payment debits repo cash GL + records a movement at receipt (`PaymentController.php:602-609` era, now port-converged) — cash recognized before clearing, the same error class as the old unpaid-expense bug. FE orchestrates instrument creation in two calls (`PaymentForm.tsx:608-658`), backend never auto-creates (G7), `payment_instruments.payment_id` never written.

**Files:**
- Modify: `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php`, `GeneralLedgerService.php` (portfolio-debit variant of `createPaymentReceivedJournalEntry` + `createCustomerAdvanceJournalEntry` — both take the debit account id as a parameter already/near-already; verify `:981-991` and `:330-341` and thread the §3 account through)
- Test: `apps/api/tests/Feature/Treasury/DeferredTenderPaymentTest.php`

**Interfaces:**
- Consumes: `InstrumentLifecycleService::receive()`, `InstrumentAccountResolver`.
- Produces (spec §8, customer direction only — `!$isSupplierPayment`, branch at `PaymentController.php:406`): when method `has_maturity`:
  1. **Validation adds:** `repository_id` required (T12-finding); `instrument` object (reference required; maturity_date required when method kind = effet) XOR `instrument_id` (must be Received, unlinked, partner-matching); `withholding_enabled` → 422 (D-9); no `amount` change.
  2. **Order inside the existing transaction:** create/lock instrument FIRST (`receive()` inline or `lockForUpdate` the supplied one — before any GL, per lock order) → create Payment + allocations as today (documents lock at `:670-674` unchanged) → build the payment JE with the **debit line on the §7 receipt account** (P for cheque / R for effet via resolver; resolver miss → 422 BEFORE the transaction opened — hoist the resolve to validation) → excess? the advance JE debit line swaps too (T7) → `postEntryNow` → **NO `record()` call** → write `payments.instrument_id` + `payment_instruments.payment_id`.
  3. Immediate methods: **byte-identical** — pin with a test asserting JE line accounts + movement row identical to a pre-change fixture.
  4. Payment currency default: company currency (replace `?? 'TND'` at `:604`; same at `:1143` in Task 14's sweep).

- [ ] **Step 1: Failing tests** — (a) traite payment: instrument auto-created (kind effet, payment_id linked), JE debit = 413-family account, **zero `repository_movements` rows**, repo balance unchanged, invoice allocated/closed; (b) check payment with excess 10.000 over allocations: BOTH JEs debit P; total P debits == payment amount == instrument amount; (c) missing `repository_id` → 422; (d) `withholding_enabled: true` → 422; (e) supplied `instrument_id` in `Deposited` → 422; (f) immediate cash payment byte-identical (JE accounts + one movement as before); (g) GL-fail injection → no instrument link, no payment, no allocation survives.
- [ ] **Step 2: FAIL.** · **Step 3: Implement.** · **Step 4: PASS** (`./vendor/bin/phpunit tests/Feature/Treasury/DeferredTenderPaymentTest.php` + existing `tests/Feature/Treasury` payment suites by path — no regressions).
- [ ] **Step 5: Commit** `feat(treasury): deferred customer tenders — portfolio debit, no cash at receipt, server-side instrument (G7)`.

### Task 14: Supplier direction + side-door guards + refund guards

**Files:**
- Modify: `PaymentController.php` (supplier branch + `storeMultiple`), `MultiPaymentController.php`, `PaymentRefundService.php`
- Test: `apps/api/tests/Feature/Treasury/DeferredTenderGuardsTest.php`

**Interfaces:**
- Produces (spec §8 writer table):
  - **Supplier-direction `store()` + `has_maturity`:** Phase-① behavior verbatim (movement out + Cr cash JE — test-pinned byte-identical) PLUS `receive()` an `direction=outbound` instrument (origin web, kind from method, custody = payment repository, payment linked) for échéancier visibility. No GL change (D-4).
  - **`storeMultiple` (`PaymentController.php:995-998`), `MultiPaymentController::createSplitPayment` (`:123-125`), `recordDeposit` (`:250-252`), `recordPaymentOnAccount` (`:412`):** `has_maturity` method ⇒ 422 with translated `treasury.deferred_method_not_supported_on_this_path` (T8).
  - **`PaymentRefundService::refundPayment/partialRefund/reversePayment`:** payment linked to a non-terminal instrument (`Received/Deposited/Bounced`) ⇒ 422 domain exception "settle the instrument first (bounce/cancel)" (T3); instrument `Cleared` ⇒ refund proceeds as today. Guard sits BEFORE any write, reading via the new `payment->instrument` relation (add `instrument(): BelongsTo` on `Payment` — column exists `Payment.php:80`).
- [ ] **Step 1: Failing tests** — supplier traite: movement out + instrument row `direction=outbound` + JE unchanged vs fixture; each of the four side doors 422s on a maturity method and still accepts cash; refund of a pending-check payment 422s; refund after clear succeeds (movement out from the CLEARED bank repo); reverse of pending-instrument payment 422s.
- [ ] **Step 2: FAIL.** · **Step 3: Implement.** · **Step 4: PASS.** · **Step 5: Commit** `feat(treasury): outbound registration, side-door 422s, refund guards for deferred tenders`.

### Task 15: FE — `PaymentForm` single-call + inline instrument block

**Files:**
- Modify: `apps/web/src/features/treasury/PaymentForm.tsx`, `apps/web/src/locales/en/treasury.json`, `fr/treasury.json`
- Test: `apps/web/src/features/treasury/__tests__/PaymentForm.instrument.test.tsx` (co-locate per existing convention — grep neighbors)

**Interfaces:**
- Produces: when `selectedMethod?.has_maturity`, the form renders an instrument fieldset (reference required; maturity date — required iff method `instrument_kind === 'effet'`; drawer name; bank free-text trio — `BankPicker` swap deferred to the bank-directory interlock, spec §15) and submits ONE `apiPost('/payments', {..., instrument: {...}})` — the two-call block (`:608-658`) is deleted. Repository select becomes required for maturity methods. Withholding toggle disabled + tooltip when maturity method selected. All new strings via `t('treasury:instruments.*')` en+fr.

- [ ] **Step 1: Failing Vitest** — maturity method shows the fieldset and blocks submit without reference; submit payload contains `instrument.reference`, no second POST fired (assert single api call); immediate method → no fieldset, payload unchanged.
- [ ] **Step 2: FAIL** (`cd apps/web && pnpm vitest run src/features/treasury/__tests__/PaymentForm.instrument.test.tsx`). · **Step 3: Implement.** · **Step 4: PASS + `pnpm typecheck`.** · **Step 5: Commit** `feat(web): single-call deferred-tender payment with inline instrument details`.

---

## WAVE E — POS bridges (G6)

### Task 16: Shared maturity-leg helper + `TreasuryReceiptBridge` sale legs

**Problem (verified):** the bridge records a movement per tender leg unconditionally (`TreasuryReceiptBridge.php:548-572`) and never creates instruments; check tenders reach it today (`AdvancedPaymentsModal.tsx:212` lists all active methods).

**Files:**
- Create: `apps/api/app/Modules/Treasury/Application/Projections/Concerns/HandlesMaturityTenderLeg.php` (trait or invokable helper — pick what the bridges' existing structure suggests)
- Modify: `TreasuryReceiptBridge.php`, `GeneralLedgerService.php` (`createPOSPaymentEntry` gains a `?string $cashAccountOverrideId` param — the swap seam; credit lines untouched, §7 rule)
- Test: `apps/api/tests/Feature/Treasury/PosBridgeInstrumentTest.php`

**Interfaces:**
- Produces (spec §9): inside `projectPaymentLineFromCanonical` (`:364`), after method resolution (`:391-405`): if `method->has_maturity` →
  - **instrument** via `receive()` with `idempotencyKey = "fiscal_event:{$event->id}:instrument:{$index}"`, `origin=Pos`, `reference = 'POS-'.substr($event->id,0,8).'-'.$index`, `needs_details=true`, `maturity_date=null`, custody = `resolveDefaultRepository()` (`:575-608`), partner from receipt, **explicit currency** (`$receipt->currency`); on unique-violation SELECT + semantic-validate (amount, kind, event) — mismatch throws;
  - **JE debit** swapped via the override param (portfolio account from the resolver — **resolver miss THROWS**, F4);
  - **NO `record()` call** for the leg; immediate legs unchanged;
  - Payment row keeps its per-leg idempotency + gets `instrument_id`.
  - **Replay probe (F5/T11)** in the idempotent-hit branch (`:458-471`): existing leg Payment → read its JE debit-line account: repo `gl_account_id` ⇒ pre-cutover leg (movement completable via the existing branch, **create NO instrument**); portfolio account ⇒ post-cutover (ensure instrument exists — create if the unique key is absent — movement FORBIDDEN: assert none gets recorded); no Payment ⇒ fresh post-cutover processing.
- `HandlesMaturityTenderLeg` exposes `handleMaturityLeg(FiscalEvent $event, PaymentDTO $leg, int $index, PaymentMethod $method, Receipt-ish $ctx): MaturityLegResult` so Task 18 reuses it single-leg.

- [ ] **Step 1: Failing tests** (all clear `CompanyContext` before `apply()` — rule 20) — (a) SALE_RECEIPT with cash+check split: cash leg → movement, check leg → instrument (needs_details, POS-ref) + JE debit on P + NO movement for that leg; (b) full replay of the same event → no duplicate instrument/payment/movement (complete-set); (c) **pre-cutover simulation:** seed a leg Payment whose JE debits the repo cash account + its movement, then replay → no instrument minted, no new JE, event applied clean; (d) missing portfolio account → bridge throws (job would retry/dead-letter); (e) voucher leg (instrument_serial set, non-maturity method) → untouched behavior.
- [ ] **Step 2: FAIL.** · **Step 3: Implement.** · **Step 4: PASS.** · **Step 5: Commit** `feat(treasury): POS check/traite tenders enter the portfolio; deferred legs stop moving cash (G6)`.

### Task 17: Bridge refund/void maturity handling

**Files:**
- Modify: `TreasuryReceiptBridge.php`
- Test: `apps/api/tests/Feature/Treasury/PosBridgeInstrumentRefundTest.php`

**Interfaces:**
- Produces (spec §9 refund contract): for maturity legs of REFUND/VOID receipts (`invoice_type_code`, `:322-323`):
  1. resolve original event id via canonical `original_receipt_reference.fiscal_event_id` (same DTO `PosCoreReceiptProjection.php:513-524` consumes); candidates = `Received` instruments `idempotency_key LIKE "fiscal_event:{original}:instrument:%"`; match `(kind, exact amount)`;
  2. exactly one → `cancel()` with `CancellationShape::PosRevenue` (JE **Dr ProductRevenue / Cr P-or-R** — the leg's ENTIRE GL effect: `createPOSRefundReversalEntry` AND the movement-Out are both SKIPPED for this leg);
  3. instrument remitted/cleared → leg posts the STANDARD cash reversal + movement Out (drawer really pays out), instrument untouched, `audit_events` row `pos_refund_on_active_instrument` keyed `(refund_event_id, leg_index)` (idempotent — check-before-insert) + `Log::warning`;
  4. zero/ambiguous match → same alert path, leg still posts the standard cash reversal? **NO** — zero/ambiguous means we cannot know whether cash ever entered; the SAFE default (spec §9 step 1: "never guess-cancel") is: post the standard cash reversal (mirroring what the non-instrument-aware bridge does today) + alert. Document this choice in the test.
  5. transient exceptions propagate (F3); replay after successful cancel → `Cancelled` instrument → silent no-op, no duplicate alert row.

- [ ] **Step 1: Failing tests** — same-day void of a check sale: instrument Cancelled, JE Dr Revenue / Cr P, NO cash movement either direction, revenue net zero across the two events; refund after the check was remitted: standard reversal + movement out + ONE alert row (replay → still one); ambiguous (two identical-amount check legs) → no cancel + alert + standard reversal; DB error mid-cancel → exception propagates (row not marked applied).
- [ ] **Step 2: FAIL.** · **Step 3: Implement.** · **Step 4: PASS.** · **Step 5: Commit** `feat(treasury): POS refund/void resolves portfolio instruments (cancel or alert, never guess)`.

### Task 18: Deposit + AccountPayment bridges

**Files:**
- Modify: `TreasuryDepositBridge.php`, `TreasuryAccountPaymentBridge.php`
- Test: `apps/api/tests/Feature/Treasury/PosSiblingBridgesMaturityTest.php`

**Interfaces:**
- Produces: both single-leg bridges (`TreasuryDepositBridge.php:176-192`, `TreasuryAccountPaymentBridge.php:163-179`) call `handleMaturityLeg` for their one leg: instrument + debit swap (credit side untouched — deposit liability / on-account) + no movement; same replay probe; same missing-account throw.

- [ ] **Step 1: Failing tests** — DEPOSIT_RECEIPT by check: instrument + Dr P / Cr deposit-liability + no movement; ACCOUNT_PAYMENT by traite: instrument + Dr R + no movement; cash variants byte-identical to today.
- [ ] **Step 2: FAIL.** · **Step 3: Implement.** · **Step 4: PASS.** · **Step 5: Commit** `feat(treasury): deposit + account-payment bridges honor deferred tenders`.

---

## WAVE F — Échéancier, alerts, reconcile

### Task 19: `GET /treasury/maturing-instruments` + buckets

**Files:**
- Create: `apps/api/app/Modules/Treasury/Presentation/Controllers/MaturingInstrumentsController.php`
- Modify: `routes.php`
- Test: `apps/api/tests/Feature/Treasury/MaturingInstrumentsTest.php`

**Interfaces:**
- Produces (spec §10): `GET /api/v1/treasury/maturing-instruments` (`can:instruments.view`) — pending instruments (`Received/Deposited`, both directions), filters (from/to, direction, kind, repository_id, partner_id, needs_details), response `{data: rows[], meta: {buckets: {overdue, d0_7, d8_30, d31_60, d61_90, d90_plus}}}`; each bucket `{count, total_in, total_out}` (strings, bcadd); row carries `certainty: 'portfolio'|'remitted'` (Received vs Deposited); null maturity buckets as `d0_7` ("due now" — at-sight). Totals per company scope.

- [ ] **Step 1: Failing test** — seed instruments across buckets/directions → bucket math exact (bccomp), overdue = matured uncleared, null-maturity in d0_7, direction split correct.
- [ ] **Step 2: FAIL.** · **Step 3: Implement** (single query + PHP bucketing at scale; no float). · **Step 4: PASS.** · **Step 5: Commit** `feat(treasury): échéancier endpoint with forward maturity buckets`.

### Task 20: Forecast integration (G8) + double-count pin

**Files:**
- Modify: `apps/api/app/Modules/Accounting/Application/Services/Reports/UpcomingPaymentsService.php`
- Test: `apps/api/tests/Feature/Accounting/UpcomingPaymentsInstrumentsTest.php`

**Interfaces:**
- Produces (spec §10): Money-In gains inbound pending instruments (maturity in window or null), Money-Out gains outbound; line shape mirrors existing entries + `source: 'instrument'`, certainty tier. **Double-count pin:** an invoice fully allocated by a deferred payment is CLOSED (`balance_due=0` → drops out of `openDocuments()`, `UpcomingPaymentsService.php:65-70`) so it appears exactly once — as the instrument (T-review verified the mechanism; the test makes it permanent). A bounced-reopened invoice (status reverted `Posted`, Task 9) reappears as an invoice — and its `re_present`-routed instrument is still pending: assert NO double count in that state either (the instrument is excluded from Money-In when `dishonor_routing` is set and status is `Bounced`... wait — `Bounced` is not in the pending set (`Received/Deposited`), so it self-excludes; assert exactly that).

- [ ] **Step 1: Failing tests** — traite-settled invoice: forecast shows one Money-In line (instrument, maturity date), zero invoice lines; after bounce with `receivable` routing: invoice line back, instrument gone; supplier outbound traite in Money-Out.
- [ ] **Step 2: FAIL.** · **Step 3: Implement.** · **Step 4: PASS.** · **Step 5: Commit** `feat(accounting): instrument maturities feed the cash forecast (G8)`.

### Task 21: Pre-maturity alert command + setting

**Files:**
- Create: `apps/api/app/Modules/Treasury/Presentation/Console/InstrumentMaturityAlertsCommand.php`, migration `2026_07_12_100600_add_instrument_alert_days_to_country_payment_settings.php`
- Modify: `apps/api/routes/console.php`, `TreasuryServiceProvider.php` (register command)
- Test: `apps/api/tests/Feature/Treasury/InstrumentMaturityAlertsTest.php`

**Interfaces:**
- Produces (spec §10): `treasury:instrument-maturity-alerts` — `TenantScopedCommand` batch shape (`forEachTenant`, continue-on-throw — copy `ReconcileTreasuryCommand` constructor/executeCommand structure `:108-122`); per company: inbound `Received` with `maturity_date <= today + instrument_alert_days` (default 7; column `instrument_alert_days smallint null` on `country_payment_settings` — per-COUNTRY table, T20) plus `Deposited` matured > alert-window days unsettled → ONE `audit_events` row per company per run (`treasury.instrument.maturity_alert`, payload counts + ids) + `Log::warning`. Scheduled `dailyAt('06:30')` + `withoutOverlapping()`, NOT `runInBackground` (observe exit code — `console.php:44` rationale).

- [ ] **Step 1: Failing test** — seed instruments in/out of window → one audit row with correct ids; second run same day → a second row is fine (daily cadence, no dedup needed — assert payload correctness not row count); tenant with a throwing company doesn't abort the loop (forEachTenant contract).
- [ ] **Step 2: FAIL.** · **Step 3: Implement.** · **Step 4: PASS.** · **Step 5: Commit** `feat(treasury): pre-maturity échéancier alerts (scheduled, per tenant)`.

### Task 22: `treasury:reconcile` check #4 — portfolio↔GL coherence (alert-only)

**Files:**
- Modify: `apps/api/app/Modules/Treasury/Presentation/Console/ReconcileTreasuryCommand.php`
- Test: `apps/api/tests/Feature/Treasury/ReconcilePortfolioCheckTest.php`

**Interfaces:**
- Produces (spec §11): per company, three equalities at scale — `Σ(pending cheque nominal, status Received+Deposited) == balance(ChecksToCollect)`; `Σ(effet Received) == balance(EffectsReceivable)` **(inbound only — outbound instruments post no GL this phase and are excluded)**; `Σ(effet Deposited) == balance(EffectsInCollection)`. GL side = full account balance (§5.5 reservation makes this sound — T6), watermark-excluded: rows/JEs before `companies.phase2_cutover_at` (new nullable column in this task's migration, stamped by the migration at deploy time = `now()`) don't count, and instruments matching the §9 pre-cutover probe never exist (Task 16 suppression). Drift → `audit_events` `treasury.reconcile.portfolio_drift` + `Log::error`; **NEVER freezes** (assert freeze list untouched). Missing purpose accounts → skip + info log (charts not yet reseeded is a deploy-window state, not drift).

- [ ] **Step 1: Failing tests** — clean cycle (receive→remit→clear) reconciles green incl. check #4; seeded drift both directions (orphan GL line on 5312 / instrument without receipt JE) → portfolio_drift audit row, NO freeze; pre-watermark noise excluded.
- [ ] **Step 2: FAIL.** · **Step 3: Implement** (follow the existing per-repo check block structure `:248-303`; new check is per-company not per-repo). · **Step 4: PASS (pgsql).** · **Step 5: Commit** `feat(treasury): reconcile check #4 — portfolio vs GL transit accounts (alert-only)`.

---

## WAVE G — Frontend

### Task 23: Instrument register + detail rework

**Files:**
- Modify: `apps/web/src/features/treasury/InstrumentListPage.tsx`, `InstrumentDetailPage.tsx`, `apps/web/src/hooks/usePermissions.ts` (add `instruments.view/create/update/clear/bounce/remit/transfer` keys — the role map is the known gotcha), `apps/web/src/routes/index.tsx` (granular `RequirePermission`), locales `en|fr/treasury.json`
- Create: `apps/web/src/features/treasury/hooks/useInstrumentEvents.ts`
- Test: `apps/web/src/features/treasury/__tests__/{InstrumentListPage.filters,InstrumentDetailPage.lifecycle}.test.tsx`

**Interfaces:**
- Produces (spec §15): list — maturity-window/kind/direction/needs_details filters + bucket chip row (consumes Task 19 meta) + kind/direction badges + pagination (`api.get`, `{data,meta}`); detail — full 9-status typing (fix the 5-status drift, `InstrumentDetailPage.tsx:69`), timeline from `instrument_events` (replaces derived), actions: remit → navigate to slip-create preselected, clear (fee/VAT `<MoneyInput>` + value date), bounce (routing select + fees + reason), custody transfer, cancel; every action gated by its granular permission; amounts as strings + `formatCurrency` (fix the `number` typing at `:64/:186`). Design tokens; all text `t()`.

- [ ] **Step 1: Failing Vitest** — filters drive query params (tenantScopedKey asserted); bounce dialog requires routing; detail renders timeline rows from a mocked events hook; action buttons hidden without permissions.
- [ ] **Step 2: FAIL.** · **Step 3: Implement.** · **Step 4: PASS + typecheck + lint.** · **Step 5: Commit** `feat(web): instrument register filters/buckets + lifecycle detail page`.

### Task 24: Remittance pages + printable bordereau

**Files:**
- Create: `apps/web/src/features/treasury/{RemittanceListPage,RemittanceDetailPage,RemittanceCreatePage}.tsx`, `hooks/useRemittances.ts`, `components/BordereauPrintView.tsx`
- Modify: `routes/index.tsx`, `Sidebar.tsx` (nav under Treasury), locales
- Test: `apps/web/src/features/treasury/__tests__/Remittance.test.tsx`

**Interfaces:**
- Produces (spec §15): create flow (bank repo select → kind → filterable Received-instrument picker → draft → remit); detail (lines, per-line clear/bounce dialogs, totals, status); **printable bordereau** (§3 conventions: depositor, bank + RIB of the bank repository, slip number/date, one line per instrument — drawer, drawee bank, reference, amount, échéance for effets — count + total; `@media print` CSS; PDF export deferred). New-directory token rule applies strictly (rule 18).

- [ ] **Step 1: Failing Vitest** — create flow posts lines then remit; print view renders count+total rows from fixture. · **Step 2: FAIL.** · **Step 3: Implement.** · **Step 4: PASS + typecheck + lint.** · **Step 5: Commit** `feat(web): remittance pages + printable bordereau`.

### Task 25: Treasury Overview échéancier panel + types

**Files:**
- Create: `apps/web/src/features/finance/components/EcheancierPanel.tsx`, `apps/web/src/features/treasury/hooks/useMaturingInstruments.ts`
- Modify: `apps/web/src/features/finance/pages/TreasuryOverviewPage.tsx`, locales
- Test: `apps/web/src/features/finance/__tests__/EcheancierPanel.test.tsx`

**Interfaces:**
- Produces (spec §10): "Échéancier — upcoming maturities" card (next 30d in/out totals from Task-19 buckets + top rows + link to filtered register). Run `php artisan typescript:transform` (worktree `CACHE_STORE=array`) after all backend DTOs exist; commit generated types separately.

- [ ] **Step 1: Failing Vitest.** · **Step 2: FAIL.** · **Step 3: Implement.** · **Step 4: PASS + typecheck.** · **Step 5: Commit** `feat(web): échéancier panel on Treasury Overview`.

---

## WAVE H — End-to-end + CI

### Task 26: pgsql CI leg extension

**Files:**
- Modify: `.github/workflows/*` (the `treasury-spine-pgsql` job — locate by name)
- Test: the job itself.

- [ ] **Step 1:** Add the new test paths (`tests/Feature/Treasury/Instrument*`, `PosBridgeInstrument*`, `ReconcilePortfolioCheckTest`, `DeferredTender*`) to the pgsql job's path list; keep it in `all-checks-pass.needs`.
- [ ] **Step 2:** Push to the feature branch only when the owner authorizes CI runs; otherwise verify the workflow YAML with `act`-style dry parse or lint (`yamllint`/actionlint if present).
- [ ] **Step 3: Commit** `ci: treasury phase-2 suites on the pgsql leg`.

### Task 27: Live Playwright A→Z on the db-per-tenant stack

**Files:**
- Create: `docs/sessions/treasury-phase2-e2e/REPORT.md` (+ screenshots; gitignored path)

Per spec §16, drive the real UI (local stack per `docs/handoff/RESUME-2026-07-08.md`, demo tenant `owner@pharmabio.tn`):
- [ ] **Step 1:** B2B traite payment (inline instrument) → register shows it (kind badge, needs_details false) → Total Cash UNCHANGED.
- [ ] **Step 2:** Create effet slip, remit → JE visible (EF journal), instruments Deposited.
- [ ] **Step 3:** Clear one with a fee → bank repo balance +net, movement row links instrument + JE; Overview cash reflects.
- [ ] **Step 4:** Bounce the other with `receivable` routing → invoice `balance_due` reopens, status Posted, échéancier panel shows the survivor only.
- [ ] **Step 5:** POS leg: seed/emit a check-tender SALE_RECEIPT fiscal event through the projection → instrument appears `needs_details` → complete via PATCH → full remit/clear cycle.
- [ ] **Step 6:** `php artisan treasury:reconcile` → green incl. check #4; `treasury:instrument-maturity-alerts` → audit row.
- [ ] **Step 7:** Write REPORT.md with screenshots; fix-forward anything broken (do NOT mark the plan done with a red station).

### Task 28: Deploy notes + memory

- [ ] **Step 1:** Append to the plan-completion commit a `docs/` deploy note (or extend the spec §18): per-tenant `tenants:migrate`, chart seeder re-run (adds portfolio accounts), perm reseed + `permission:cache-reset` (three new permissions), `phase2_cutover_at` stamped by migration; FEC descriptive doc gains the `EF` journal declaration (D-2).
- [ ] **Step 2: Commit** `docs(treasury): phase-2 deploy checklist`.

---

## Self-Review (author, 2026-07-11)

1. **Spec coverage:** §5 schema → Tasks 2-5; §6 machine/lock order → 2, 6-9; §7 postings → 6-9, 13, 16; §8 cutover table → 13-14; §9 bridge → 16-18; §10 échéancier → 19-21, 25; §11 register/reconcile → 11, 22, 23; §12 impayé → 9, 11; §13 API → 11-12; §15 FE → 15, 23-25; §16 tests → distributed + 26-27; §17/§18 → 22 (watermark), 28. Escompte/outbound-GL/re-billing correctly ABSENT (deferred by D-4/5/6).
2. **Placeholder scan:** none — every step names files, signatures, and concrete assertions; line refs marked as re-grep-before-edit.
3. **Type consistency:** `InstrumentKind/Direction/Origin/DishonorRouting` (Task 2) consumed by 3, 6-9, 13-14, 16-19; `handleMaturityLeg` (16) consumed by 18; `CancellationShape` (6) consumed by 17; `ReceiveInstrumentData` fields match Task 13/16 call sites; bucket keys (19) consumed by 23/25.

## Execution Handoff

Owner gates dispatch (handoff §mandate). When authorized: superpowers:subagent-driven-development in the `feat/treasury-instruments` worktree, per-task adversarial review (treasury-reviewer; fiscal-pos-reviewer on Wave E), hard-stop gates after Waves B, D, E, and H.
