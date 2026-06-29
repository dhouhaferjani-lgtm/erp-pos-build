# Adversarial Plan Review — POS Loyalty Phase 1 (online-only)

Plan: `docs/superpowers/plans/2026-06-28-loyalty-pos-online-phase1.md`
Spec: `docs/superpowers/specs/2026-06-28-loyalty-pos-online-phase1-design.md`
Worktree: `apps/erp.loyalty-pos` (branch `feat/loyalty-pos-online`)
Verified against real source on 2026-06-28. Read every cited file; no assumptions.

---

## BLOCKERS (must fix before executing)

### B1 — T2 test: `UserCompanyMembership` imported from the wrong namespace (won't compile)
- Plan, Task 2 Step 1, line 226:
  `use App\Modules\Identity\Domain\UserCompanyMembership;`
- Reality: the class lives at **`App\Modules\Company\Domain\UserCompanyMembership`** —
  `find app -name UserCompanyMembership.php` → `app/Modules/Company/Domain/UserCompanyMembership.php` (only location).
  The real harness confirms it: `tests/Feature/Loyalty/LoyaltyPOSControllerTest.php:8`
  `use App\Modules\Company\Domain\UserCompanyMembership;`
- Effect: `PosLoyaltyBalanceTest` fatals with "class not found" in both `setUp()` (line 253) and
  `test_403_when_loyalty_module_disabled` (line 314). Whole test class never runs.
- **Fix:** change the import to `use App\Modules\Company\Domain\UserCompanyMembership;`.
  (`User` from `App\Modules\Identity\Domain\User` and `Company` from `App\Modules\Company\Domain\Company`
  are correct as written — only the membership import is wrong.)

---

## SHOULD-FIX

### S1 — T3 earn-rate fetch hits a `can:loyalty.view`-gated endpoint that POS operators likely lack → estimate silently never loads
- Real route: `app/Modules/Loyalty/Presentation/routes.php:28-30`
  `Route::get('loyalty/earn-rate', …)->middleware('can:loyalty.view')`.
- The POS terminal user is provisioned with `pos.operate_terminal` (see the existing
  `LoyaltyPOSControllerTest` / the new test's permission grant). A cashier role typically does **not**
  hold `loyalty.view`, so `apiGet('/loyalty/earn-rate')` returns **403**.
- The plan (Task 3 Step 6) swallows the error (`catch → console.debug`), so the rate stays `null` and
  the headline `+N pts this sale` estimate — the central UX of this phase — never renders in production,
  even though all unit tests (which mock the rate) pass. This is an E2E-only failure (Task 5 Step 4).
- **Fix (pick one):** (a) grant `loyalty.view` to POS terminal roles; OR (b) add a
  `can:pos.operate_terminal`-gated rate read under the existing `loyalty/pos` group and point the POS at
  it; OR (c) explicitly accept "no estimate for POS-only operators" and update the spec. Recommend (b) —
  keeps gating coherent with the rest of the POS loyalty surface.

### S2 — T4 component return type `JSX.Element | null` deviates from the POS convention and risks a typecheck failure
- Plan, Task 4 Step 5, line 762: `export function CustomerLoyaltyBadge({ customer }: Props): JSX.Element | null`.
- Real POS components do **not** annotate the return type: `Badge` (`components/ui/Badge.tsx:24`) and
  `CartCustomerControl` (`components/customers/CartCustomerControl.tsx:10`) both write
  `export function X(props) { … }` with no `JSX.Element`/`ReactElement` annotation. The only
  `ReactElement` usages are in test files. There is no established `JSX.Element` usage in `src/components`.
- Under React 19 + the automatic JSX runtime (no `import React`), the global `JSX` namespace may be
  unavailable, making `JSX.Element` a typecheck error in `pnpm typecheck` (Task 5 Step 3).
- **Fix:** drop the return annotation (let it infer `Element | null`, matching `Badge`/`CartCustomerControl`),
  or `import { type ReactElement } from 'react'` and use `ReactElement | null`. The plan already flags this
  as a "confirm" note — resolve it to: omit the annotation.

---

## NITS / low-risk

### N1 — `eslint-disable` directive references a rule that isn't registered in the POS
- Plan, Task 4 Step 5, line 771: `// eslint-disable-next-line precision/no-parsefloat-on-money`.
- The `no-parsefloat-on-money` rule exists only in **apps/web** (`apps/web/eslint-rules/no-parsefloat-on-money.js`,
  registered in `apps/web/eslint.config.js`). The **apps/pos** `eslint.config.js` registers only a local
  `no-untranslated-literal` plugin — no money/precision rule. So the disable directive is inert (and the
  rule id `precision/…` doesn't match apps/web's `no-parsefloat-on-money` either).
- Harmless (ESLint ignores unknown rule ids in disable directives unless `--report-unused-disable-directives`
  is on), but pointless. **Fix:** delete the disable comment; `Number(rate)` / `Number(balance.balance)`
  for a display-only integer estimate is fine and unguarded in the POS.

### N2 — `loyalty_members` unique index is non-partial; soft-deleted same-phone member would 500 the create path
- Migration `database/migrations/tenant/2026_01_10_100001_create_loyalty_members_table.php:47`
  `$table->unique(['tenant_id', 'phone'])` (plain unique), and `LoyaltyMember` uses `SoftDeletes`.
- `findOrCreateMember`'s dedupe (`->where('phone', $normalized)->first()`) excludes soft-deleted rows, so a
  previously soft-deleted member with the same `(tenant, phone)` would slip past the find and the subsequent
  `LoyaltyMember::create` would hit the unique constraint → unhandled `QueryException` → 500. Not exercised by
  the planned tests; very edge for the demo. Note only.

### N3 — T1 "byte-match" wording is loose (behavior is preserved)
- The plan's `MemberResolver::resolveByContactOrPartner` is **not** byte-identical to the real
  `SaleEarningService::resolveMember` (`SaleEarningService.php:104-130`): the real version reads
  `$context->contactId/partnerId/tenantId` and introduces a local `$partnerId = $context->partnerId;`
  before the closure. The plan's param-based version is logically equivalent (same query shape, same
  contact→partner→`customer_id` fallback, same tenant scoping). Faithful extraction — no defect.

---

## Point-by-point verification log

**T1**
- Faithful, behavior-preserving extraction (see N3). ✓
- `grep "new SaleEarningService("` across `apps/api/` → **no matches**. No manual instantiation; adding a
  3rd constructor param (`MemberResolver`) is container-safe. ✓
- `MemberResolver` is a plain `final readonly` concrete class → Laravel auto-resolves it; `app(MemberResolver::class)`
  in the test works with no binding. ✓
- `tests/Feature/Loyalty/SaleEarningServiceTest.php` exists (characterization step is runnable). ✓
- `LoyaltyMemberFactory` exists with `loyaltyable_type='contact'`, unique `phone` default → the T1 test's
  two `LoyaltyMember::factory()->create([...])` calls won't collide on the unique phone. ✓

**T2 — signatures/columns**
- `LoyaltyMember::normalizePhone(string $phone): string` is **static** (`LoyaltyMember.php:112`). ✓
- `$fillable` (`LoyaltyMember.php:54-67`) includes `tenant_id, customer_id, loyaltyable_type, loyaltyable_id,
  phone, first_name, status, enrollment_date` — all mass-assignable. ✓
- `status` cast → `MemberStatus::class` (`LoyaltyMember.php:76`); `MemberStatus::Active` exists
  (`Enums/MemberStatus.php:9`). Passing the enum instance is correct. ✓
- `Enrollment.current_balance` cast `decimal:3` (`Enrollment.php:75`); fresh enrollment created with
  `current_balance => 0` (`MemberEnrollmentService.php:66`) reads back as **`'0.000'`** — the test's
  `data.balance === '0.000'` assertions (and the not-enrolled literal) are correct. ✓
- `MemberEnrollmentService::enroll(string $memberId, string $programId, ?float $welcomeBonus = null): EnrollmentData`
  (`:37`) creates the Enrollment (balance 0, `EnrollmentStatus::Active`), re-validates program `isActive()`,
  throws if already enrolled. Plan passes member+program only and guards with `findByMemberAndProgram` first. ✓
- `EnrollmentRepositoryInterface::findByMemberAndProgram(string $memberId, string $programId): ?Enrollment`
  (`:23`). ✓  `LoyaltyProgramRepositoryInterface::findByTenantAndStatus(string $tenantId, ProgramStatus $status):
  Collection` (`:33`) — `->first()` valid; `ProgramStatus::Active` exists (`Enums/ProgramStatus.php:10`). ✓
- `Enrollment::currentTier()` BelongsTo (`Enrollment.php:111`); `Tier` has `name` (`Tier.php:42` fillable,
  `:18` `@property string $name`). `$enrollment->currentTier?->name` valid. ✓
- Route group: `loyalty/pos` group (`routes.php:188`) is nested inside the outer group whose middleware is
  `['api','auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, 'module:Loyalty']`
  (`routes.php:26`). Adding `POST /balance` inside `loyalty/pos` with `->middleware('can:pos.operate_terminal')`
  inherits all gating — matches rule 12 and the existing siblings (`member-lookup`, `earn`). ✓
- Test harness: the plan's `setUp` mirrors the real `LoyaltyPOSControllerTest::setUp` (`Tenant::factory()
  ->create(['enabled_extras' => ['Loyalty']])`, `Company::factory`, `User::factory`, `UserCompanyMembership::create`,
  `app(PermissionRegistrar::class)->setPermissionsTeamId(...)`, `Permission::findOrCreate('pos.operate_terminal',
  'sanctum')`, `givePermissionTo`, `Sanctum::actingAs`) — **all correct except the `UserCompanyMembership`
  namespace (B1).**
- `activeProgram()` uses `LoyaltyProgram::factory()->create([...])` — `LoyaltyProgramFactory` exists and supplies
  defaults for all NOT-NULL columns (`name`, `program_type`, `currency`, `company_ids=null`), so the slim override
  works (the real test uses explicit `::create`, but the factory is equivalent here). ✓
- Envelope: controller returns `response()->json(['data' => $result])` (no `meta`) — consistent with sibling
  loyalty endpoints; the POS `apiPost` only reads `.data`, so no issue. The new tests assert happy paths + 403
  only; the app's `{error:{...}}` validation envelope is not exercised → no hidden problem. ✓

**T3/T4 (apps/pos)**
- `apiPost`/`apiGet` → `request()` → returns `json.data` (one-level unwrap) (`lib/api.ts:177-217`). So the
  `{data:{…}}` body yields the inner object. Plan's `fetchLoyaltyBalance`/earn-rate usage correct. ✓
- `AttachedCheckoutCustomer.phone: string | null` (`paymentStore.ts:170`),
  `customer_sync_status: CustomerSyncStatus` (`:186`), `type CustomerSyncStatus = 'synced' | 'pending_create'`
  (`:163`). Plan's `=== 'synced'` gate and `phone` pass-through correct. ✓
- `cartStore.total: () => number` (`cartStore.ts:121`) — returns a `number`; TTC per spec. ✓
- `Badge` tones `'neutral' | 'success' | 'warning' | 'danger' | 'action'` (`Badge.tsx:9`) — plan uses
  `action/neutral/success`, all exist. ✓
- `companyConfigCache.ts` storage helpers: `getStoredValue`/`setStoredValue` from `@/lib/storage`
  (`getStoredValue<T>(key): Promise<T|null>`, `setStoredValue<T>(key, value): Promise<void>` — `storage.ts:31,58`).
  Plan's `loyaltyRateCache.ts` mirrors them via `./storage` (== `@/lib/storage`). ✓
- i18n: `pos` namespace registered, `defaultNS: 'pos'` (`lib/i18n.ts:30-31`); `useTranslation('pos')` valid. ✓
- `refreshCompanyConfig` at `authStore.ts:418`; `apiGet` already imported (`authStore.ts:3`); `persistCompanyConfig`
  + `companyId` available in scope (`:424-426`). ✓
- `hasModule(config: CompanyConfig | null, moduleName: string): boolean` exported (`productStore.ts:110`);
  store field `companyConfig` exists (`productStore.ts:33`) so `useHasModule` selector is valid. ✓

**Idempotency (item 4)**
- Replay-safe in the single-device sequential case: 1st call creates member with
  `loyaltyable_type='partner', loyaltyable_id=partnerId`; 2nd call's `resolveByContactOrPartner` matches it →
  no re-create; enrollment guarded by `findByMemberAndProgram`. `test_repeat_call_does_not_duplicate` holds. ✓
- No unique index on `(loyaltyable_type, loyaltyable_id)`, but `(tenant_id, phone)` IS unique and the resolver
  dedupes by loyaltyable first, phone second — adequate for attach-time use. True concurrent double-insert
  (two devices, same new customer, same instant) would race the unique-phone constraint and 500 the loser;
  out of scope for online single-terminal attach. Low risk. (See N2 for the soft-delete edge.)

---

## Verdict

**Not ready as written — one compile blocker.** Fix **B1** (wrong `UserCompanyMembership` namespace) before
executing Task 2, or the entire `PosLoyaltyBalanceTest` fatals. **S1** (earn-rate endpoint gated by
`loyalty.view`, which POS operators lack) and **S2** (`JSX.Element` return type) should be resolved before
Task 3/Task 4 to avoid a silently estimate-less demo and a possible typecheck failure. Tasks 1 and 5 are sound
as written. With B1 fixed and S1/S2 addressed, the plan is executable.
