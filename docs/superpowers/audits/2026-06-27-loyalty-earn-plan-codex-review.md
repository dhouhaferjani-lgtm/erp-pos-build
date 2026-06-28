# Adversarial plan review — Loyalty earn-on-purchase (demo)

**Plan:** `docs/superpowers/plans/2026-06-27-loyalty-earn-demo.md`
**Spec:** `docs/superpowers/specs/2026-06-27-loyalty-earn-per-product-design.md`
**Worktree:** `apps/erp.loyalty-earn` (branch `feat/loyalty-earn-per-product`)
**Method:** every claim verified against the real files (paths below are repo-relative to `apps/erp.loyalty-earn`).

Verdict up front: **NOT ready to execute as written.** Tasks 1, 4, 5, 6 are essentially correct against the codebase. **Task 2 has a hard BLOCKER (missing factories) and a discriminated-failure SHOULD-FIX. Task 3 has two test-construction BLOCKERs (the sibling FiscalEvent builder cannot express training / refund cases as the plan assumes).** Signatures, enums, casts, projection scope, ctor injection, and FE assumptions all check out.

---

## BLOCKERS (must fix before the affected task can go green)

### B1 — `LoyaltyMember::factory()` and `Enrollment::factory()` DO NOT EXIST (Task 2 tests)
`apps/api/database/factories/Loyalty/` contains ONLY `LoyaltyProgramFactory.php`. There is no member/enrollment/earning-rule factory, and across the whole suite `LoyaltyMember::factory()` / `Enrollment::factory()` / `EarningRule::factory()` are **never used** — every existing test builds them with `::create([...])` (see `tests/Feature/Loyalty/LoyaltyPOSControllerTest.php:225-245`).

Critically, Laravel will NOT auto-discover a factory placed at `Database\Factories\Loyalty\XFactory` for a module model. Resolution requires a `newFactory()` override on the model. `LoyaltyProgram` is the proof: it works only because `app/Modules/Loyalty/Domain/Entities/LoyaltyProgram.php:51` declares `protected static function newFactory(): LoyaltyProgramFactory`. `EarningRule`, `LoyaltyMember`, `Enrollment` have **no** `newFactory()` (grep-confirmed: only `LoyaltyProgram` has it).

Consequences:
- Task 2 Step 1 test calls `LoyaltyMember::factory()`, `Enrollment::factory()`, `EarningRule::factory()` → all three fail to resolve.
- Task 4 Step 1's `EarningRuleFactory` (placed at `Database\Factories\Loyalty\EarningRuleFactory`) will NOT be picked up by `EarningRule::factory()` unless you ALSO add `newFactory()` to the model. The plan flags this only as a conditional "confirm…" note — it is **mandatory**.

**Fix (pick one, do it explicitly in the plan):**
- (a) Add `EarningRuleFactory`, `LoyaltyMemberFactory`, `EnrollmentFactory` under `database/factories/Loyalty/` AND add a `newFactory()` override to each of the three models, mirroring `LoyaltyProgram.php:51`; **or**
- (b) Rewrite Task 2's helpers to use `LoyaltyMember::create([...])` / `Enrollment::create([...])` / `EarningRule::create([...])`, copying `LoyaltyPOSControllerTest::createMember` (`tenant_id`, `phone` via `LoyaltyMember::normalizePhone()`, `status => MemberStatus::Active`, `enrollment_date => now()`) and `::createEnrollment` (`program_id`, `member_id`, `current_balance`, `lifetime_earned`, `lifetime_redeemed`, `status => EnrollmentStatus::Active`, `enrolled_at => now()`). Note `LoyaltyMember` requires a non-null `phone` and `enrollment_date`; `Enrollment` requires `enrolled_at`.

Option (a) is more work but the plan only fully specifies `EarningRuleFactory`; it does not give `LoyaltyMemberFactory`/`EnrollmentFactory` definitions. Either way this is unspecified work that blocks Task 2.

### B2 — Task 3 `test_training_receipt_credits_nothing` cannot be built from the sibling helper
The FiscalEvent builder the plan tells you to copy is `storeSaleReceiptFiscalEvent()` in `tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php:1365`. Its payload **hardcodes** `'training_flag' => false` (`PosCoreReceiptProjectionTest.php:~1428`/line shown as `'training_flag' => false,`). The helper exposes NO training-flag parameter. The plan's training test therefore can't be expressed without first parametrizing the helper (add `bool $trainingFlag = false` and thread it into the payload). Flag this as required test-harness work, not a copy-paste.

### B3 — Task 3 `test_refund_receipt_credits_nothing` is non-trivial (projection throws before the earn guard)
For `invoice_type_code in {REFUND,VOID}`, `PosCoreReceiptProjection::assertOriginalReceiptResolvableForRefundOrVoid()` (`PosCoreReceiptProjection.php:460-499`) **throws** `OriginalReceiptUnresolvableException` (RuntimeException if the ref is missing entirely) and the wrapping `DB::transaction` rolls everything back UNLESS the original SALE receipt has already been projected locally. So a naive "build a REFUND event and assert no Earn" passes only because the whole projection aborts — it does NOT exercise the earn-eligibility guard (`receiptType === Return`). To test the intended path, the test must (1) project the original SALE first, then (2) project a REFUND whose `originalReceiptReference.fiscalEventId` points at it (use the `$originalReceiptReference` + `$invoiceTypeCode` params of `storeSaleReceiptFiscalEvent`). The plan's pseudostructure under-specifies this; call it out so the executor doesn't ship a green-but-vacuous test.

---

## SHOULD-FIX

### S1 — `catch (InvalidArgumentException)` swallows real errors, contradicting the spec (Task 2 Step 3)
`EarningProcessingService::earnPoints()` throws `InvalidArgumentException` in **two** places, not one:
- `apps/api/app/Modules/Loyalty/Application/Services/EarningProcessingService.php:62` — `"Enrollment with ID {$enrollmentId} not found"`
- `:68` — `"Points already earned for {$sourceType} {$sourceId}"` (the duplicate case)

(The "no rules configured" case does NOT throw — it returns a zero `TransactionData`, lines 73-89. Good: a seeded-but-ruleless program is a silent no-op, not an error.)

The plan's `catch (InvalidArgumentException $e) { continue; }` block sits **before** the `catch (\Throwable)` log block, so it catches BOTH IAE subtypes and silently swallows the enrollment-not-found error as if it were a duplicate — exactly the "blanket catch" the spec (§ Error handling / Codex BLOCKER-3) says to avoid ("Discriminate by exception type/message, not a blanket `catch (\Throwable)`"). In this flow enrollments are freshly queried so not-found is unlikely, but the plan's own design intent is violated.

**Fix:** discriminate by message before swallowing, e.g.
```php
} catch (InvalidArgumentException $e) {
    if (! str_contains($e->getMessage(), 'already earned')) {
        Log::error('Loyalty earn failed for sale (recoverable by hand)', [...]);
    }
    continue;
}
```
(Exact duplicate message substring: `already earned`.)

### S2 — Route ability `can:pos.operate_terminal` is wrong for a back-office product editor (Task 5 Step 4)
The earn-rate endpoint is consumed by the product editor (back-office). The product read/editor routes are guarded by `can:products.view` / `products.update` under `module:Inventory` (`apps/api/app/Modules/Product/routes.php:45-62`). A back-office editor user is not guaranteed to hold `pos.operate_terminal` (that's the POS-terminal-operator ability). Every read endpoint in the Loyalty module uses **`can:loyalty.view`** (`apps/api/app/Modules/Loyalty/Presentation/routes.php` — e.g. lines 33, 37, 63). That is the semantically correct, module-consistent guard for reading loyalty data.

**Fix:** use `->middleware('can:loyalty.view')` (not `pos.operate_terminal`). Keep it inside the existing `module:Loyalty` group. Update the Task 5 endpoint test to grant `loyalty.view` (the harness in `LoyaltyPOSControllerTest` grants `pos.operate_terminal`; swap the permission name accordingly). The exact outer group string to nest under (`Loyalty/Presentation/routes.php:25`):
`['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, 'module:Loyalty']`.

### S3 — Wrong `CompanyContext` FQCN in plan code (Tasks 2 & 5)
- Task 2 test's placeholder `app(...CompanyContextHelperForTest::class ?? \App\Modules\Identity\Domain\CompanyContext::class)->clear()` is broken: `Identity\Domain\CompanyContext` does not exist, and the `??` expression resolves to a non-null class-string so it would try to resolve a bogus binding.
- Task 5 controller imports `App\Shared\Context\CompanyContext` (a guessed FQCN).

The real class is **`App\Modules\Company\Services\CompanyContext`** (injected by `LoyaltyProgramController` at `LoyaltyProgramController.php:7,20`). It exposes `clear()` (`CompanyContext.php:148`), `requireCompany(): Company` (:100) and `requireTenantId(): string` (:64). For the controller, `$this->companyContext->requireTenantId()` is cleaner than `requireCompany()->tenant_id` (both work).

For the worker-reality clear in Task 2/3, the exact call is:
```php
app(\App\Modules\Company\Services\CompanyContext::class)->clear();
```
Note: the sibling projection test (`tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php:12,106`) **sets** context (`app(CompanyContext::class)->setCompanyId($this->companyId)`); it does NOT clear. So the plan's "copy their CompanyContext::clear() setup" instruction is inaccurate — there is nothing to copy; add the `clear()` call yourself per rule 20.

### S4 — Redundant (silently dropped) `'id'` in the seeded EarningRule (Task 4 Step 4)
`EarningRule` uses `HasUuids` and `id` is NOT in `$fillable` (`EarningRule.php:40-53`). The listener's `new EarningRule([... 'id' => (string) Str::uuid() ...])` silently drops the `id` key (mass-assignment ignores non-fillable) and `HasUuids` generates the UUID on save anyway. Harmless but dead code. The canonical pattern is `EarningRuleController@store` (`EarningRuleController.php:76`): `new EarningRule($data)` with NO `id`. Mirror it — drop the `'id'` key.

---

## VERIFIED CORRECT (no change needed)

**Signatures / enums / repos**
- `EarningProcessingService::earnPoints(string $enrollmentId, array $transactionData, string $sourceType, string $sourceId, ?string $description = null): TransactionData` — plan's named-arg call matches exactly (`EarningProcessingService.php:52-58`).
- `LoyaltyProgramRepositoryInterface::findByTenantAndStatus(string, ProgramStatus): Collection` exists (`...Repositories/LoyaltyProgramRepositoryInterface.php:33`, returns `Illuminate\Support\Collection`; `->isEmpty()` works).
- `EarningRuleRepositoryInterface::findActiveByProgram(string): Collection` (:32) and `save(EarningRule): EarningRule` (:37) exist.
- Enums: `ProgramStatus::Active='active'`, `EnrollmentStatus::Active='active'`, `EarningRuleType::Spend='spend'`, `TransactionType::Earn='earn'` — all exact.
- `ProgramActivated(programId, tenantId, programName, activatedAt)` — matches (`Domain/Events/ProgramActivated.php`); dispatched via `DB::afterCommit` + `event()` in `ProgramManagementService::activateProgram()` (:166,183-184).

**Casts (test assertions are correct)**
- `EarningRule::reward_value` cast `decimal:4` ⇒ returns `'1.0000'` / `'2.0000'`. Task 4 assertion `'1.0000'` ✓; Task 5 suggested `'2.0000'` ✓ (note the controller does `(string) $spend->reward_value` so it returns the 4-dp string).
- `Enrollment::current_balance` cast `decimal:3` ⇒ `'12.000'` ✓ (`Enrollment.php:66`).
- `Transaction::amount` cast `decimal:3` ⇒ `'12.000'` ✓ (`Transaction.php:74`).

**Earn math with empty items**
- `PointEarningService::calculateSpendPoints()` (`PointEarningService.php:277-286`) = `amount × reward_value`, ignores `items`. With `amount='12.000'`, `reward_value='1'`, scale 3 ⇒ `12.000`. `ruleApplies()` returns true for empty `conditions` (:102). `earnPoints()` fetches rules for `$enrollment->program_id` (:72) so the seeded Spend rule must belong to that program — Task 2's seed does this. Balance written as test expects. ✓

**Projection ctor injection + call-site scope**
- `grep "new PosCoreReceiptProjection("` across the repo → ZERO hits. It is always container-resolved (`$this->app->make(PosCoreReceiptProjection::class)` throughout `PosCoreReceiptProjectionTest.php`). Adding a 5th promoted ctor param (`LoyaltyEarningContract`) is safe — the container resolves it via the LoyaltyServiceProvider binding (Task 2 Step 4). Current ctor has 4 params (`PosCoreReceiptProjection.php:122-127`).
- At the insert point right after `$this->redeemVouchers($receiptId, $event, $view);` (`PosCoreReceiptProjection.php:318`, inside the `DB::transaction` closure), ALL of `$receiptId`, `$event`, `$view`, `$payload`, `$receiptTypeEnum`, `$totalNorm` are in scope (defined at :182, closure `use`, :169, :237, :188). ✓
- `$view->buyer?->contactId` and `?->customerId` exist — confirmed by the projection itself reading `$buyer?->customerId` / `$buyer?->contactId` (:243-244); `BuyerDTO` at `Fiscal/Domain/DTOs/Canonical/BuyerDTO.php`.
- `$payload` is `App\Modules\Fiscal\Domain\DTOs\SaleReceiptPayload` (the type of `SaleReceiptCanonicalView::$payload`, `SaleReceiptCanonicalView.php:35`). Plan's typehint `SaleReceiptPayload` is correct — import `App\Modules\Fiscal\Domain\DTOs\SaleReceiptPayload`.
- `$payload->currencyCode` (:269), `$payload->trainingFlag` (:278), `$event->event_time_device` (:190), `$event->tenant_id`, `$event->sequence_number` (int, :197) all exist. `ReceiptType` (`POS\Domain\Enums\ReceiptType`) is ALREADY imported (:21); `Log` already imported (:35) — the plan's "add if missing" notes are moot, they're present.
- Minor: plan passes the receipt number from `$event->sequence_number`; the nicer human number `$receiptNumber` ("FE-…") is also in scope (:193). Either is fine (description-only).

**FE**
- `apiGet` is exported from `apps/web/src/lib/api.ts:197` and returns `response.data.data` (it DOES double-unwrap the envelope). The controller returns `{ data: { rate } }`, so `apiGet<EarnRateResponse>('/loyalty/earn-rate')` yields `{ rate }`. Hook's `data?.rate ?? null` is correct. Import path `../../lib/api` from `src/features/inventory/` resolves correctly. The api client base path already includes `/api/v1` (FE calls products as `/products/${id}`), so `'/loyalty/earn-rate'` hits `/api/v1/loyalty/earn-rate`. ✓
- `useCompanyConfig` exported from `apps/web/src/contexts/CompanyConfigContext.tsx:108`; Task 6 test mock path `'../../../contexts/CompanyConfigContext'` resolves correctly from `src/features/inventory/__tests__/`.
- `ProductForm.tsx`: `const { config, hasModule } = useCompanyConfig()` (:136); `const salePriceValue = watch('sale_price')` (:512, a string used with `.trim()`); `EditorSectionCard` imported (:33) with props `{ id: string; title: string (pre-translated); children }` (`products/editor/components/EditorSectionCard.tsx:5-11`) — plan's `<EditorSectionCard id="section-loyalty" title={t(...)}>` is correct.
- `sectionDefs` (`ProductForm.tsx:554`) is `Array<{ id: string; labelKey: string }>` and is gated for pharmacy via `...(isParapharmacy ? [{ id, labelKey }] : [])` (:558-563). The loyalty entry must use the SAME `{ id, labelKey }` shape (NOT `title`): `...(hasModule('Loyalty') ? [{ id: 'section-loyalty', labelKey: 'catalog:editor.sectionLabels.loyalty' }] : [])`. Plan's prose is consistent with this.

**Test harness to copy from**
- Endpoint auth/tenant/module:Loyalty setup → `tests/Feature/Loyalty/LoyaltyPOSControllerTest.php` (`Tenant::factory()->create(['enabled_extras' => ['Loyalty']])`, `Company`/`User`/`UserCompanyMembership`, `Permission::findOrCreate(...,'sanctum')` + `givePermissionTo`, `Sanctum::actingAs`, per-tenant `PermissionRegistrar::setPermissionsTeamId`). Real and reusable.
- Projection FiscalEvent fixture builder → `tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php:1365` `storeSaleReceiptFiscalEvent(... ?array $buyer, string $total, string $invoiceTypeCode, ?array $originalReceiptReference ...)`. Real and reusable (subject to B2/B3 above). Apply via `$this->app->make(PosCoreReceiptProjection::class)->apply($event)`.

---

## Task-by-task readiness

| Task | Status | Blocking edits before execution |
|---|---|---|
| 1 — Contract + DTO | Ready | none |
| 2 — SaleEarningService | **Needs edits** | B1 (factories) + S1 (discriminate IAE) + S3 (CompanyContext FQCN/clear) |
| 3 — Projection trigger | **Needs edits** | B2 (training-flag param) + B3 (refund original seeding) + S3 (clear) |
| 4 — Activation seed | Ready (minor) | EarningRule `newFactory()` override is MANDATORY (part of B1); S4 drop `'id'` |
| 5 — earn-rate endpoint | **Needs edits** | S2 (use `can:loyalty.view`) + S3 (CompanyContext FQCN) |
| 6 — FE display | Ready | none (sectionDefs uses `labelKey`, already noted) |
| 7 — Preflight/E2E | Ready | none |

**Bottom line:** the engine wiring, signatures, enums, casts, scope, and FE assumptions are sound. The plan will NOT run green out of the box because of the missing Loyalty factories (B1, affects Tasks 2 & 4) and two under-specified projection tests (B2/B3). Fix B1–B3 and S1–S3 and it's executable.
