# Adversarial Review: Loyalty POS Phase 1 Design Spec

**Spec:** `docs/superpowers/specs/2026-06-28-loyalty-pos-online-phase1-design.md`
**Branch:** `feat/loyalty-pos-online` (worktree `apps/erp.loyalty-pos`)
**Reviewer:** Codex (adversarial pass), 2026-06-28
**Scope:** Concrete feasibility flaws and rule violations against actual codebase only. Style nits excluded.

---

## BLOCKERS

### B1 — No cross-module contract exists to read Partner/Contact phone or name (Rule 6)

**Evidence:**

`apps/api/app/Shared/Contracts/PartnerServiceInterface.php` exposes only VAT/name-upsert and lookup-by-VAT. It has no method signature for `getPhoneByPartnerId`, `getContactPhoneById`, or any equivalent.

`apps/api/app/Modules/Partner/Application/Services/PartnerService.php` and `apps/api/app/Modules/Contact/Application/Services/ContactService.php` — both are module-internal services, not exported via a Shared contract.

**Impact:** The auto-enroll path, as designed, must read a customer's phone and name to populate `loyalty_members.phone` (which is NOT NULL). The spec's plan to "source phone from the customer's Partner/Contact" has no legal cross-module path. Importing the Partner model directly into the Loyalty projection violates Rule 6. A new Shared contract method (e.g. `PartnerServiceInterface::getContactById(string $id): ContactSummaryDTO`) is a prerequisite — it is not currently present and is not mentioned as a deliverable in the spec.

**Fix required:** The spec must explicitly add a task to extend `PartnerServiceInterface` (and its implementation) with a phone/name lookup method, before the auto-enroll task is started.

---

### B2 — `loyalty_members.phone` is NOT NULL and has a tenant-scoped UNIQUE index; auto-enroll will throw on any customer without a phone

**Evidence** (migration `2026_01_10_100001_create_loyalty_members_table.php`):

```php
$table->string('phone')->notNullable();
$table->unique(['tenant_id', 'phone']);
```

A later migration (`2026_03_02_300000_fix_loyalty_schema.php`) does not drop the NOT NULL or the unique constraint.

**Impact:** If the attached customer has no phone on file (common for walk-in POS customers), `MemberEnrollmentService::enroll()` will throw a database constraint error, killing the projection's `DB::transaction`. The spec says "auto-enroll on first sale" but does not address the missing-phone case.

**Fix required:** Either (a) make `phone` nullable + drop the unique index for the auto-enroll flow, or (b) the spec must explicitly gate auto-enroll on `customer.phone !== null` and document that customers without phones are silently skipped (no exception, no partial member).

---

### B3 — `MemberEnrollmentService::enroll()` is NOT idempotent; replay would throw

**Evidence** (`apps/api/app/Modules/Loyalty/Application/Services/MemberEnrollmentService.php`):

The public `enroll(EnrollMemberDTO $dto): LoyaltyMember` method:
1. Creates the member unconditionally (no find-or-create).
2. Throws `MemberAlreadyEnrolledException` (or DB unique violation on phone) if a member already exists.

The `loyalty_members` table has no unique index on `(loyaltyable_type, loyaltyable_id)` — so a duplicated projection replay would insert a second member row (if the phone were nullable) or throw on the phone unique constraint (with current schema).

**Impact:** The spec's description of "find-or-create member keyed on `tenant_id + loyaltyable_type + loyaltyable_id`" does not match what the service actually does. On any projection replay (standard Horizon behaviour on failure), the auto-enroll step will either create a duplicate or throw.

**Fix required:** Either add a `UNIQUE (loyaltyable_type, loyaltyable_id)` partial index to `loyalty_members` and change `enroll()` to upsert/find-or-create, OR create a separate `autoEnroll(SaleEarnContext $ctx): ?LoyaltyMember` method that implements find-or-create semantics without touching the existing enroll path.

---

### B4 — Auto-enroll runs in a projection with NO CompanyContext; `MemberEnrollmentService` may implicitly rely on it

**Evidence:**

`apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php` — grep for `CompanyContext` returns zero hits: the projection does not set or clear CompanyContext.

`apps/api/app/Modules/Loyalty/Application/Services/EarningProcessingService.php` calls `CurrencyScaleResolverInterface::getScale()` with no argument in at least one branch, which throws in a no-CompanyContext worker (Rule 19 / Rule 20).

**Impact:** If the auto-enroll call chain touches any code that calls `getScale()` without an explicit currency argument, the projection worker will throw. The spec does not audit this path.

**Fix required:** The spec must require that the entire auto-enroll + earn-processing call chain uses `getScale($currency)` (explicit currency from the sale payload), not `getScale()`. Verify `EarningProcessingService` and any service it delegates to.

---

## SHOULD-FIX

### S1 — `GET /loyalty/pos/balance` endpoint and route group do not exist yet

**Evidence:** `apps/api/app/Modules/Loyalty/Presentation/Controllers/LoyaltyPOSController.php` exists but has no `balance()` method. `apps/api/app/Modules/Loyalty/Presentation/routes.php` has no `loyalty/pos` route group with `module:Loyalty` + `can:pos.operate_terminal` middleware.

The spec lists this as a deliverable. That is accurate — it is not a false assumption. But the spec should note it requires both a new controller method AND a new route group, not just the controller method.

---

### S2 — `SaleEarningService::resolveMember()` is `private`; the spec's "extract shared resolver" is feasible but needs a concrete home

**Evidence:** `apps/api/app/Modules/Loyalty/Application/Services/SaleEarningService.php` — `resolveMember()` is declared `private`.

The spec says "extract a shared resolver". The correct home is a new method on `LoyaltyService` (which implements `LoyaltyServiceInterface` in Shared/Contracts) or a dedicated `MemberResolverService` in `Loyalty/Application/Services/`. It must NOT be placed in `Shared/` — Loyalty domain logic stays in the Loyalty module. The spec should name this explicitly.

---

### S3 — Rate caching: `refreshCompanyConfig` is the correct seam, but the earn rate is NOT currently in the company config payload

**Evidence:** `apps/pos/src/lib/companyConfigCache.ts` + `apps/pos/src/types/companyConfig.ts` — the `CompanyConfig` type has no `loyalty_earn_rate` or equivalent field. `apps/pos/src/api/productApi.ts` fetches the earn rate via a separate endpoint (`/loyalty/earn-rate`).

**Impact:** The spec proposes caching the earn rate by piggybacking on `refreshCompanyConfig`. This is feasible architecturally, but requires (a) adding `loyalty_earn_rate` to the `CompanyConfig` DTO on the backend, (b) including it in the company-config API response, and (c) typing it in `companyConfig.ts`. None of these steps are currently done. The spec should list them as sub-tasks, not assume they are free.

---

### S4 — POS cart TTC total exists as a `number`, not a string; Rule 19 requires string for money

**Evidence:** `apps/pos/src/stores/cartStore.ts` — the TTC/inclusive total selector is present (good: the spec's claim that a TTC total exists is correct), but it is typed and returned as `number`.

**Impact:** The earn estimate computation (`cartTTC × rate`) would use a JS `number`, violating Rule 19 ("never `parseFloat`/`Number(...)` on money/quantity; use string + `formatCurrency`"). The spec must require that the earn estimate be computed using string arithmetic (or at minimum, that the display path formats via `formatCurrency` before rendering).

---

### S5 — Pending/local (offline-created) customers must be excluded from the balance fetch

**Evidence:** `apps/pos/src/components/customers/CustomerAttachPanel.tsx` — customers can be attached before sync; a locally-created customer has a client-generated UUID that does not exist on the server yet.

**Impact:** A `GET /loyalty/pos/balance?partner_id=<local-uuid>` call would return 404 or an empty result. The spec says "online-only" but does not exclude local customers from triggering the balance call. This will silently show no balance for a legitimately attached (but not-yet-synced) customer.

**Fix required:** The spec must add: "skip balance fetch if the attached customer's `sync_status !== 'synced'` (or if the ID is a client-generated UUID)."

---

### S6 — Frontend loyalty chrome must be gated with `hasModule(config, 'Loyalty')` AND a `RequirePermission` wrapper (Rule 12, both-layer gating)

**Evidence:** `apps/pos/src/stores/productStore.ts` — `hasModule(config, 'Loyalty')` works (confirmed: `companyConfig.all_enabled_modules` is the correct field). Backend route group must include `module:Loyalty` middleware (not yet present — see S1).

**Impact:** The spec mentions module gating, but does not explicitly call out that BOTH layers (backend route middleware AND frontend `hasModule`/`RequirePermission`) are required per Rule 12. If only one layer is gated, a future module-removal will silently leave the other layer open.

---

## NITS

### N1 — Earn basis TTC confirmed correct

`apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php` passes the canonical payload `total` (which is the TTC/tax-inclusive amount) to `SaleEarningService`. The local POS estimate (`cartTTC × rate`) will match server earn behavior. No action needed.

### N2 — `Badge` atom exists on this branch

`apps/pos/src/components/ui/Badge.tsx` is present and suitable for the loyalty chrome. No action needed.

### N3 — `companyConfig.all_enabled_modules` field name is correct

Confirmed in `apps/pos/src/types/companyConfig.ts`. The spec's field reference is accurate.

---

## VERDICT

**Not sound — resolve blockers first.**

Three blockers (B1, B2, B3) each independently make the auto-enroll path either unimplementable without a spec amendment (B1: missing Shared contract) or unreliable in production (B2: NOT NULL phone crashes on walk-ins, B3: non-idempotent enroll crashes on projection replay). B4 is a silent data-loss risk in the worker. None of these are minor implementation details — they require schema decisions, new contracts, or service redesign that must be explicit in the spec before implementation starts.

Recommended path before writing a task plan:
1. Add `PartnerServiceInterface::getContactById()` as an explicit prerequisite task (B1).
2. Make `phone` nullable in `loyalty_members` and document the skip-if-no-phone behaviour (B2).
3. Add a unique index on `(loyaltyable_type, loyaltyable_id)` and make `MemberEnrollmentService` upsert-safe (B3).
4. Audit the full earn/enroll call chain for no-arg `getScale()` calls (B4).
5. Address S3 (earn rate in company config), S4 (string arithmetic for estimate), and S5 (skip unsynced customers) as sub-tasks.
