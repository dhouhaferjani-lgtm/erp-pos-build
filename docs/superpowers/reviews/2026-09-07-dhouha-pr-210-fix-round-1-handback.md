# Fix round 1 — PR #210 "fix(authz): gate supplier-invoice posting behind a dedicated permission (F-W2-14)"

- **Gate answered**: `docs/superpowers/reviews/2026-09-05-dhouha-pr-210-gate-r1.md` (verdict CHANGES, findings 1–10)
- **Worktree / branch**: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-210`, `gate/pr-210` (PR head merged onto local `dev` `fa000edc3`)
- **Owner ruling applied (2026-09-07, binding)**: option (a) — the secure default stands (`operator` does NOT get `supplier-invoices.manage`), AND the permission stays grantable per user / per custom role through the existing permissions surface. Both halves are now pinned by tests.
- **Not merged. Not pushed. Nothing under the lot/cash slices touched** (`BatchExpiry`, inventory counting, `StockTransferService`, `PosCoreReceiptProjection`, `TreasuryMovementService`, `RepositoryTransferService`, `payment_repositories` schema — all untouched, confirmed by `git status`).

---

## Permission names and role grants after this round

`apps/api/database/seeders/RolesAndPermissionsSeeder.php`

| Permission | Catalogue line | Seeded to | Explicitly NOT seeded to |
|---|---|---|---|
| `supplier-invoices.manage` (unchanged by this round; shipped by the PR) | `:158` | `admin` (via `Permission::all()`), `manager` `:595`, `accountant` `:829` | cashier, operator, viewer, technician |
| `payments.pay-supplier` (**NEW this round**) | `:260` | `admin` (via `Permission::all()`), `manager` `:609`, `accountant` `:836` | cashier, operator, viewer, technician |
| `purchase-orders.confirm` (**existing**, re-used — no new permission) | `:146` | `admin`, `manager` `:578` | cashier, operator, viewer, technician, accountant |

Both new-ish permissions are grantable with no code change: `GET /api/v1/permissions` returns `Permission::all()` grouped by the name prefix (`RoleController::permissions()` `:315-317`) and neither `supplier-invoices` nor `payments` is in `PERMISSION_GROUP_MODULE` `:35-46`, so both groups are core and always listed; `POST|PATCH /api/v1/roles{/id}` sync a role's permissions (`RoleController` `:197`, `:246`). Nothing in the backend or the frontend reads a ROLE NAME for these gates (verified by grep and by the two grant tests below).

---

## Per finding

### Finding 1 [MAJOR] — F-W2-14 closed for real: PO revert + supplier payment

**(a) `POST /documents/{document}/revert`** — a cashier could un-confirm a purchase order.

- `apps/api/app/Policies/DocumentPolicy.php:155` — new `revert(User, Document)`: `PurchaseOrder => can('purchase-orders.confirm')`, `default => can('documents.update')` (company-isolation check first, same shape as the sibling abilities). **No new permission**: `purchase-orders.confirm` is the permission that governs the inverse transition (`apps/api/app/Modules/Document/Presentation/routes.php:300`) and is already manager/admin-only. `purchase-orders.update` was rejected as the gate because a role may legitimately hold it to correct a DRAFT without being allowed to un-commit a confirmed order.
- `apps/api/app/Modules/Document/Presentation/Controllers/DocumentController.php:241` — `Gate::forUser($request->user())->authorize('revert', $documentModel)`, placed AFTER the 404 so a cross-company id still reads as "not found" (the existing cross-company test still passes).
- The route keeps `can:documents.update` (`Document/Presentation/routes.php:84-87`) as the coarse gate — the per-type verdict cannot live in route middleware because `{document}` is not model-bound (`whereUuid` + manual `find`).
- **Non-PO revert behaviour is unchanged**: quote and sales order still need only `documents.update`, pinned by `test_quote_revert_still_needs_only_documents_update`.

**(b) `POST /payments`** — a cashier could pay a supplier.

- `apps/api/app/Modules/Treasury/Application/Services/SupplierPaymentAuthorizer.php` (new) — decides supplier-side by the SHAPE OF THE REQUEST, never by role: (1) any named document whose type `isSupplierSettlement()`, else (2) no such document and the partner is a pure `supplier` (an on-account payment out). `PartnerType::Both` is deliberately excluded from arm (2) — with no supplier document named the controller treats the payment as a customer receipt, and denying it would break a mixed-role partner's AR flow for a cashier.
- `apps/api/app/Modules/Document/Domain/Enums/DocumentType.php:134` — new `isSupplierSettlement()`: `SupplierInvoice`, `SupplierCreditNote`, `PurchaseOrder`, `PurchaseQuoteRequest`.
- `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:458` (`store`) and `:1527` (`storeMultiple`) — `assertMayPay(...)` runs immediately after `$request->validate(...)` and before the first write, so a refusal leaves **no `payments` row, no `payment_allocations` row, no journal entry and no `repository_movements` row** (asserted). Injected by constructor (`:79`), per rule 13.
- New permission `payments.pay-supplier` (nothing in the catalogue fitted: `payments.allocate` gates the invoice-side auto-allocation route `Document/Presentation/routes.php:215`, and `expenses.pay` is the expense flow — reusing either would conflate two concepts, CLAUDE.md rule 22 "one surface per concept").
- **Customer-side payments by a cashier are untouched** — pinned by `test_cashier_can_still_take_a_customer_payment`.

**Fixture updates owed by (b)** — seven existing supplier-payment fixtures grant permissions explicitly and now also need `payments.pay-supplier` (admin-role fixtures needed nothing):
`tests/Feature/Treasury/SupplierPaymentGuardTest.php:101`, `PaymentTest.php:91`, `OpeningItemPaymentDirectionTest.php:120`, `PaymentControllerSpineTest.php:107`, `PaymentGlPostingTest.php:103`, `Concerns/PaymentApplicabilityScaffold.php:92`.

### Finding 2 [MAJOR] — deploy steps
Written into `docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md` §3 as a blocking checkbox (staging `SYNC_PERMISSIONS_ON_BOOT` confirmation, the manual `tenants:seed` + `permission:cache-reset` pair for every env where the flag is false, the `syncPermissions` release-note caveat, and a five-line post-deploy smoke). **`apps/api/docker/entrypoint.sh` was NOT changed**, as instructed.

Additionally the FE now **fails closed** in the stale-tenant window (finding 2's actual symptom): `apps/web/src/hooks/usePermissions.ts:10-45` adds `supplier-invoices.manage` and `payments.pay-supplier` to `SERVER_AUTHORITATIVE_PERMISSIONS`, so a manager on a tenant whose seeder has not re-run sees no Post / New / Pay control instead of a button that 403s.

### Finding 3 [MAJOR / OWNER RULING] — operator
Owner ruled option (a). `test_operator_cannot_post_supplier_invoice_under_secure_default` keeps the deny and its comment now records the ruling as binding rather than as an open question (`SupplierInvoiceApiTest.php`). The grantable half is proven end-to-end by three new tests:
- `SupplierInvoiceApiTest::test_operator_granted_supplier_invoices_manage_directly_can_post` — an `operator` whose role default is a deny, granted the permission with `givePermissionTo`, stores AND posts a supplier invoice through the real routes (200, `status=posted`).
- `SupplierPaymentPermissionTest::test_operator_granted_the_permission_directly_can_pay_a_supplier_invoice` — same shape for `payments.pay-supplier` (201).
- `DocumentRevertEndpointTest::test_directly_granted_purchase_order_confirm_permission_allows_revert` — same shape for the PO revert gate (200).
- FE half: `usePermissions.supplierInvoiceGates.test.tsx` — a server grant to an operator is honoured; an operator without it is denied; a stale-tenant manager is denied.

**Correction to the gate's premise about `SERVER_AUTHORITATIVE_PERMISSIONS`:** a direct server grant was ALREADY honoured before this round — `hasPermission` returns `true` from the server list first (`usePermissions.ts:129`, before the SERVER_AUTHORITATIVE check), and `AuthUserData.php:43` builds that list from `User::getAllPermissions()`, which unions role-derived and directly-assigned permissions. The two entries were added for the OTHER direction (fail closed on a stale tenant), which is finding 2.

### Finding 4 [MINOR] — both-layer gating on the CREATE surface
- `apps/web/src/routes/index.tsx` — `supplier-invoices/new` is now `<RequirePermission moduleKey="purchases" permission="supplier-invoices.manage">` (module gate KEPT; the `purchases.create` UI role alias dropped from this route — it denied accountants a page whose API call they are explicitly authorised to make).
- `apps/web/src/features/purchases/supplier-invoices/SupplierInvoiceListPage.tsx:200` — the "New supplier invoice" `<Link>` is now conditioned on `hasPermission('supplier-invoices.manage')` (it carried no condition at all).
- Also, for the new payment gate: `SupplierInvoiceDetailPage.tsx:89` — `canCreatePayments = hasPermission('payments.create') && hasPermission('payments.pay-supplier')`, matching what `POST /payments` now enforces on its AP branch.
- `scripts/factory/manifests/routes-web.yaml:709-713` regenerated with `node scripts/factory/gen-route-manifest.mjs` (module_gate `null → purchases`, permission `purchases.create → supplier-invoices.manage`); `node --test scripts/factory/gen-route-manifest.test.mjs` → 10/10 pass.
- `apps/web/src/hooks/permissionsMap.generated.ts` regenerated with the repo generator (`php artisan permissions:export-frontend-map`) — see the "generator byte-identity" evidence below.

### Finding 5 [MINOR] — attachments residual surface: CLOSED, not just listed
The gate's own prescription was "put it in the residual list". It was cheap to close properly instead, so it is closed:
- `apps/api/app/Policies/DocumentPolicy.php:181` — new `attach(User, Document)`: `SupplierInvoice => can('supplier-invoices.manage')`, `default => can('documents.update')`.
- `apps/api/app/Modules/Media/Presentation/Controllers/DocumentAttachmentController.php:163` — `authorizeAttach()` helper called from `store()` `:68` and `destroy()` `:137`, after `resolveDocument()` so a cross-company id keeps reading as 404. `apps/api/app/Modules/Media/routes.php` unchanged (the coarse `can:documents.update` stays as the route gate).
- Read paths (`index`, `download`, `can:documents.view`) are unchanged.

### Finding 6 [MINOR] — stale published contract doc
`docs/superpowers/coordination/2026-06-26-supplier-invoice-api-contract.md:30,33,36` now read `can:supplier-invoices.manage`; `:39` documents the new `DocumentPolicy::attach()` per-type verdict on the attachment endpoint; the payment section records the `payments.pay-supplier` AP branch.

### Finding 7 [MINOR] — permission catalogue test extended
`apps/api/tests/Feature/Permissions/P2pEntryPointPermissionsTest.php` — `supplier-invoices.manage` and `payments.pay-supplier` added to `$permissions` (so the exist / manager-has-all / viewer-cashier-operator-deny matrix covers them), the accountant "only" test asserts both explicitly, and a new `test_purchase_order_revert_permission_is_manager_tier_not_cashier_tier` pins `purchase-orders.confirm` (manager yes; viewer/cashier/operator/accountant no) plus the fact that `cashier` DOES hold `documents.update` — which is why the per-type verdict was needed.

### Finding 8 [MINOR] — inaccurate verification claim
Corrected here, on the record: **the three `422 != 200` invoice-first failures are NOT sqlite-only.** Measured on this tree, both engines:
- sqlite `SupplierInvoiceApiTest`: 72 tests, 3 failures — the same three the gate named.
- PostgreSQL `SupplierInvoiceApiTest`: 61 passed / 5 failed — the same three PLUS two the gate identified as a PG-only fixture bug (`locations.code` is `varchar(20)`; the test inserts `SI-APPROVAL-MISSING-WH` / `SI-LINK-NOT-PENDING-WH`, 22 chars).
The gate verified all five identical on unmodified `dev` (`fa000edc3`), so none is caused by the PR or by this round; this round adds **+1 net passing test and zero new failures** on both engines. The two truncation failures still deserve their own ticket (not opened here — out of scope).

### Finding 9 [INFO] — rule 22 second-of-everything
**Restated unchanged: N/A.** This round adds no table, no unique key and touches no catalogue entity, so `TenantOnlyUniqueOnCatalogueTablesRatchetTest` is untouched. Permissions are Spatie team-scoped by TENANT, not company (`apps/api/config/permission.php:99`, `SetPermissionsTeam.php:28`), so a second company inside the same tenant resolves every one of these gates identically and a second-company authz test would add no signal.

### Finding 10 [INFO] — verified-correct list
Still holds. The additions keep the same shape: middleware/policy, never inline role checks; the permission names match exactly across seeder, policy, controller, generated map and FE call sites; `tsc --noEmit` is clean so no FE typo is possible on the generated `Permission` union.

---

## Verification — exact commands and result lines

All run from the worktree. **The full PHPUnit suite was never run** (standing rule).

### Backend — sqlite (`apps/api`, `./vendor/bin/phpunit`, config `phpunit.xml`)
```
$ ./vendor/bin/phpunit tests/Feature/Permissions/P2pEntryPointPermissionsTest.php tests/Feature/Document/DocumentRevertEndpointTest.php
  OK (13 tests, 62 assertions)

$ ./vendor/bin/phpunit tests/Feature/Treasury/SupplierPaymentPermissionTest.php
  OK (5 tests, 15 assertions)

$ ./vendor/bin/phpunit tests/Feature/Treasury/SupplierPaymentPermissionTest.php tests/Feature/Treasury/SupplierPaymentGuardTest.php \
    tests/Feature/Treasury/PaymentControllerSpineTest.php tests/Feature/Treasury/PaymentGlPostingTest.php \
    tests/Feature/Document/DocumentRevertEndpointTest.php tests/Feature/Permissions/P2pEntryPointPermissionsTest.php \
    tests/Feature/Modules/Media/DocumentAttachmentApiContractTest.php tests/Feature/Console/ExportFrontendPermissionsMapCommandTest.php
  Tests: 52, Assertions: 269, PHPUnit Deprecations: 23   (0 failures)

$ ./vendor/bin/phpunit tests/Feature/Procurement/SupplierInvoiceApiTest.php tests/Feature/DocumentIngestion/CommitSupplierInvoiceTest.php
  Tests: 72, Assertions: 382, Failures: 3
  (the 3 pre-existing invoice-first 422s named by the gate; CommitSupplierInvoiceTest 6/6 green)

$ ./vendor/bin/phpunit tests/Architecture/FeatureLaneManifestCheckerTest.php
  OK (76 tests, 431 assertions)
$ php tools/feature-lane-manifest-check.php
  tests/Feature lane manifest OK — 1509 Feature classes in 74 groups   (gated ceiling unchanged: 1245;
  the new class lands in tests/Feature/Treasury, an EXECUTING lane, so no ceiling raise is owed)
```

### Backend — PostgreSQL (private DB `autoerp_test_pr210` on `127.0.0.1:5433`, created and left in place)
```
$ DB_DATABASE=autoerp_test_pr210 DB_CENTRAL_DATABASE=autoerp_test_pr210 CACHE_STORE=array \
    php artisan test -c phpunit-pgsql.xml tests/Feature/Treasury/SupplierPaymentPermissionTest.php \
    tests/Feature/Document/DocumentRevertEndpointTest.php tests/Feature/Permissions/P2pEntryPointPermissionsTest.php \
    tests/Feature/Console/ExportFrontendPermissionsMapCommandTest.php
  Tests: 21 passed (…)      [after the repository_movements column fix below]

$ … php artisan test -c phpunit-pgsql.xml tests/Feature/Treasury/SupplierPaymentPermissionTest.php
  ✓ cashier cannot pay a supplier invoice and no cash leaves the repository
  ✓ cashier cannot make an on account payment to a supplier
  ✓ manager can pay a supplier invoice
  ✓ operator granted the permission directly can pay a supplier invoice
  ✓ cashier can still take a customer payment
  Tests: 5 passed (15 assertions)   Duration 20.32s

$ … php artisan test -c phpunit-pgsql.xml tests/Feature/Modules/Media/DocumentAttachmentApiContractTest.php \
    tests/Feature/DocumentIngestion/CommitSupplierInvoiceTest.php tests/Feature/Treasury/SupplierPaymentGuardTest.php \
    tests/Feature/Treasury/PaymentControllerSpineTest.php tests/Feature/Treasury/PaymentGlPostingTest.php
  Tests: 37 passed (209 assertions)   Duration 85.00s

$ … php artisan test -c phpunit-pgsql.xml tests/Feature/Procurement/SupplierInvoiceApiTest.php
  ✓ operator granted supplier invoices manage directly can post
  ✓ operator cannot post supplier invoice under secure default
  Tests: 5 failed, 61 passed (339 assertions)
  (the SAME five the gate measured on unmodified dev: 3 × invoice-first 422 + 2 × PG locations.code truncation)
```
PG caught one real defect in my own new test that sqlite did not: `repository_movements` has no `repository_id` column (it is `payment_repository_id`), and sqlite's `assertDatabaseMissing` had been passing on a non-existent column. Fixed and re-verified on both engines.

### Red-before-green (TDD evidence — each gate temporarily disabled, test re-run, gate restored)
```
revert gate off      → test_cashier_cannot_revert_a_confirmed_purchase_order: "Expected 403 but received 200"
attachment gate off  → test_cashier_cannot_attach_to_a_supplier_invoice:      "Expected 403 but received 201"
payment gate off     → SupplierPaymentPermissionTest: 2 × "Expected 403 but received 201"
```
The 200/201 in each line IS the wave-2 hole being reproduced on this tree.

### Regression baseline (proving zero new failures)
```
$ (main checkout, unmodified dev) ./vendor/bin/phpunit tests/Feature/Treasury/PaymentTest.php tests/Feature/Treasury/DeferredSupplierPaymentTest.php
  Tests: 39, Assertions: 120, Errors: 5, Failures: 2
$ (this tree)                     same command
  Tests: 39 … Errors: 5, Failures: 2 — IDENTICAL test names
  (DeferredSupplierPaymentTest × 6 "fiscal period closed" / PaymentTest × 1 PaymentType assertion — all pre-existing)
```

### Static analysis / style
```
$ ./vendor/bin/phpstan analyse app/Modules/Document/Domain/Enums/DocumentType.php app/Policies/DocumentPolicy.php \
    app/Modules/Document/Presentation/Controllers/DocumentController.php \
    app/Modules/Media/Presentation/Controllers/DocumentAttachmentController.php \
    app/Modules/Treasury/Application/Services/SupplierPaymentAuthorizer.php \
    app/Modules/Treasury/Presentation/Controllers/PaymentController.php \
    database/seeders/RolesAndPermissionsSeeder.php --memory-limit=1G
  [OK] No errors        (level 8, live PG env from apps/api/.env)

$ ./vendor/bin/pint --test <all 18 changed/added PHP files>
  {"result":"pass"}
```
(PHPStan's configured `paths:` is `app/` only, so test files are out of its scope — analysing them explicitly surfaces 58 pre-existing errors in untouched test code and is not a meaningful signal.)

### Frontend (`apps/web`)
```
$ ./node_modules/.bin/vitest run src/hooks/__tests__/usePermissions.supplierInvoiceGates.test.tsx
  Test Files 1 passed (1)   Tests 4 passed (4)
$ ./node_modules/.bin/vitest run src/features/purchases/supplier-invoices/SupplierInvoiceListPage.test.tsx
  Test Files 1 passed (1)   Tests 17 passed (17)
$ ./node_modules/.bin/vitest run src/features/purchases/supplier-invoices/SupplierInvoiceDetailPage.test.tsx
  Test Files 1 passed (1)   Tests 24 passed (24)
$ ./node_modules/.bin/vitest run src/hooks/__tests__ src/features/auth src/features/purchases
  Test Files 43 passed (43)   Tests 282 passed (282)
$ ./node_modules/.bin/vitest run tools/__tests__          # incl. permission-map-drift-guard
  Test Files 8 passed (8)   Tests 189 passed (189)

$ ./node_modules/.bin/tsc --noEmit                        # = pnpm typecheck
  (clean, exit 0)

$ node tools/audit-tanstack-keys.mjs      → Gate C: 0 new, 0 stale
$ node tools/audit-design-system.mjs      → 810 acknowledged, 0 new, 0 stale
$ node tools/audit-quantity-display.mjs   → 0 total, 0 new
$ node --test scripts/factory/gen-route-manifest.test.mjs → pass 10, fail 0

$ npx react-doctor --blocking warning <the 4 touched .ts/.tsx source files>
  (this tree)  Score 92/100, 7 warnings
  (dev)        Score 92/100, 7 warnings — the SAME seven, same rule ids, only line
               numbers shifted by the added comments. 0 new.
  (the repo's staged-diff hook prints an advisory on commit; the findings are the
   pre-existing giant-component / chained-iteration / lazy-state-init ones in
   untouched code, none on a line this round wrote.)
```
**`pnpm lint` was not run whole** (it chains eslint over the entire app plus five audits and is heavy on this laptop). Instead: the four audits above were run individually, and `eslint` was run on the touched files — **with a measured dev baseline** so "pre-existing" is not an assertion:
```
$ (this tree)      ./node_modules/.bin/eslint <8 touched FE files incl. the new spec>
    ✖ 35 problems (0 errors, 35 warnings)
$ (main checkout, unmodified dev) ./node_modules/.bin/eslint <the same 7 pre-existing files>
    ✖ 35 problems (0 errors, 35 warnings)
  => identical: 0 errors, 0 new warnings; the new spec file contributes 0.
```

### Generator byte-identity (the gate's finding-4 requirement)
```
$ cd apps/api && CACHE_STORE=array php artisan permissions:export-frontend-map
  Exported frontend permission map to …/apps/web/src/hooks/permissionsMap.generated.ts.
$ git diff -- apps/web/src/hooks/permissionsMap.generated.ts
  only: source hash 109bee61… → a00c4e9c…, and one added line
  +  'payments.pay-supplier': ['accountant', 'admin', 'manager'],
```
The committed map IS the generator's output; a re-run produces no further diff.

---

## What I could NOT do, and why

1. **`deptrac` ratchet fails on this branch — pre-existing, not caused by this round.** `php tools/deptrac-ratchet.php` reports `SharedContracts on ModuleDomain` 36→37 RATCHET and `ModuleDomain on ModuleApplication` 54→53 improved, TOTAL 183 = 183. The recomputed per-category numbers on this tree are **byte-identical to local `dev`'s reconciled baseline** (dev: 68/53/1/18/37/4/2, PASS). The branch simply predates the 2026-09-05 `dev-reds-2` reconciliation commit that re-keyed `deptrac.baseline.json` (`git diff HEAD dev -- apps/api/deptrac.baseline.json` shows exactly those two numbers swapping, with a `ceiling_note` explaining the 12-day-old mis-attribution). **It resolves itself the moment this branch is merged onto current local `dev`** — I did not rebase or touch the baseline, since either would have been out of scope and would have masked the reconciliation.
2. **No browser evidence.** No Playwright or manual UI run was performed. The FE gating claims rest on the three Vitest suites (two of which mock `usePermissions`, one of which drives the real hook through `useAuthStore`), `tsc --noEmit`, and the regenerated route manifest. **Nobody has clicked through as a cashier / manager / granted-operator on this tree.**
3. **No staging or production deploy.** The `SYNC_PERMISSIONS_ON_BOOT` claim still rests on `entrypoint.sh:153-161` plus the 2026-09-02 handover note, not on a run. The promotion-checklist row is written; executing it is owner work.
4. **The two PG `locations.code` truncation failures were not fixed** and no ticket was opened — out of scope for this round (finding 8 only asked that the claim be corrected). They are a 22-char fixture value against a `varchar(20)` column in `SupplierInvoiceApiTest`.
5. **The three invoice-first `422 != 200` failures were not diagnosed** — confirmed identical on unmodified `dev`, out of scope.
6. **`pnpm lint` and `pnpm test` were not run whole** (laptop constraint) — substituted as described above with a measured baseline. A regression in an untouched FE area is unmeasured.
7. **The full PHPUnit suite was not run** (standing rule). Only the classes listed above were executed; a regression outside them is unmeasured. In particular, other callers of `POST /payments` outside `tests/Feature/Treasury` (POS device flows have their own endpoints and were not exercised) are unmeasured — though the AR path is unchanged by construction.
8. **`apps/api/docker/entrypoint.sh` was deliberately not changed**, per the fix-round instruction.

---

## Files changed

**Backend (app / db)**
- `apps/api/app/Modules/Document/Domain/Enums/DocumentType.php:134` — `isSupplierSettlement()`
- `apps/api/app/Policies/DocumentPolicy.php:155,181` — `revert()`, `attach()`
- `apps/api/app/Modules/Document/Presentation/Controllers/DocumentController.php:241`
- `apps/api/app/Modules/Media/Presentation/Controllers/DocumentAttachmentController.php:68,137,163`
- `apps/api/app/Modules/Treasury/Application/Services/SupplierPaymentAuthorizer.php` (new)
- `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:79,88,458,1527`
- `apps/api/database/seeders/RolesAndPermissionsSeeder.php:260,609,836`

**Backend (tests)**
- `apps/api/tests/Feature/Treasury/SupplierPaymentPermissionTest.php` (new, 5 tests)
- `apps/api/tests/Feature/Document/DocumentRevertEndpointTest.php` (+4 tests, +`makePurchaseOrder()`)
- `apps/api/tests/Feature/Permissions/P2pEntryPointPermissionsTest.php` (+2 permissions in the matrix, +1 test)
- `apps/api/tests/Feature/Procurement/SupplierInvoiceApiTest.php` (+1 grantability test, ruling recorded on the secure-default test)
- `apps/api/tests/Feature/Modules/Media/DocumentAttachmentApiContractTest.php` (+2 tests, +`makeRoleUser()`)
- 6 fixture files granting `payments.pay-supplier` (listed under finding 1(b))

**Frontend**
- `apps/web/src/hooks/usePermissions.ts` — `SERVER_AUTHORITATIVE_PERMISSIONS` + rationale docblock
- `apps/web/src/hooks/permissionsMap.generated.ts` — regenerated
- `apps/web/src/routes/index.tsx` — `supplier-invoices/new`
- `apps/web/src/features/purchases/supplier-invoices/SupplierInvoiceListPage.tsx:200`
- `apps/web/src/features/purchases/supplier-invoices/SupplierInvoiceDetailPage.tsx:89`
- `apps/web/src/hooks/__tests__/usePermissions.supplierInvoiceGates.test.tsx` (new, 4 tests)
- `SupplierInvoiceListPage.test.tsx` (+3 tests), `SupplierInvoiceDetailPage.test.tsx` (+1 test)

**Docs / manifests**
- `docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md` §3 — the F-W2-14 deploy row
- `docs/superpowers/coordination/2026-06-26-supplier-invoice-api-contract.md` — finding 6
- `scripts/factory/manifests/routes-web.yaml` — regenerated

---
---

# Fix round 2 — answering gate r2 (`docs/superpowers/reviews/2026-09-07-dhouha-pr-210-gate-r2.md`, verdict MERGE-WITH-FIXES)

Commits: see below. Not merged, not pushed. Nothing under the lot/cash slices touched.

## Item 1 [MAJOR] — the FE module gate was a role alias in front of the permission gate

**Routes** (`apps/web/src/routes/index.tsx`) now carry the SAME permission the API route checks, with no module alias:

| Web route | was | now | backend twin |
|---|---|---|---|
| `/purchases/supplier-invoices` `:1046-1055` | `moduleKey="purchases"` | `permission="documents.view"` | `Procurement/Presentation/routes.php:84` `can:documents.view` |
| `/purchases/supplier-invoices/:id` `:1071-1080` | `moduleKey="purchases"` | `permission="documents.view"` | `…routes.php:94` `can:documents.view` |
| `/purchases/supplier-invoices/new` `:1061-1069` | `moduleKey="purchases" permission="supplier-invoices.manage"` | `permission="supplier-invoices.manage"` | `…routes.php:104` `can:supplier-invoices.manage` |

Regenerated `scripts/factory/manifests/routes-web.yaml:709-720` → all three `module_gate: null`, permissions as above.

**Nav** (the gate's "also check the sidebar" half). The whole Purchases GROUP hung off the same alias (`Sidebar.tsx:176 permission: 'purchases'` → `MODULE_PERMISSIONS.purchases = ['purchases.view']`), and `Sidebar.tsx:453-458` filters children only AFTER the group's own gate passes — so an accountant saw no supplier-invoice link at all. Fixed **additively**, so nobody loses an entry:
- `apps/web/src/hooks/usePermissions.ts:58` — `purchases: ['purchases.view', 'supplier-invoices.manage']` (`canAccessModule` is `hasAnyPermission`, so the group now opens for the people the API already authorises).
- `usePermissions.ts:106-121` — three NAV-ONLY keys, each a UNION of the real permission that child's own API checks with the legacy alias, so the widening cannot offer anyone a page the server refuses: `nav.purchaseOrders: ['purchase-orders.view','purchases.view']`, `nav.purchaseQuoteRequests: ['purchase-quote-requests.view','purchases.view']`, `nav.supplierInvoices: ['supplier-invoices.manage']` (no alias arm — the alias is exactly what hid it from the accountant).
- `apps/web/src/components/organisms/Sidebar/Sidebar.tsx:180-195` — `quoteRequests`, `purchaseOrders`, `goodsReceipts` (its list reads `/purchase-orders`, `GoodsReceiptListPage.tsx:194`) and `supplierInvoices` gated on those keys.
- **Net effect**: accountant now sees Suppliers / Scans / Supplier invoices / Return notes and NOT the three whose API would 403; manager, admin and the alias-only `purchases` role keep every entry (pinned); cashier/viewer/operator still cannot open the group.

**Tests** — `apps/web/src/features/purchases/supplier-invoices/supplierInvoiceRouteGuards.test.tsx` (new, 11 tests) mounts the real `RequirePermission` guard with the real `usePermissions` hook and the real auth store, plus a static assertion against the generated route manifest so the wiring cannot drift back:
- read (`documents.view`): accountant ✅, granted operator ✅, **default operator ✅** — read mirrors the API, which allows `documents.view`; signed-out ❌.
- create (`supplier-invoices.manage`): accountant ✅, granted operator ✅, default operator ❌, **cashier ❌**.
- manifest: all three routes assert `module_gate: null` + the exact permission.

> Correction to the fix-round brief: "cashier denied" holds for CREATE only. A cashier holds `documents.view` and the API's `GET /supplier-invoices` admits them, so denying the read route on the FE would re-create the very mismatch this finding is about. The tests say so explicitly.

`Sidebar.test.tsx` gains 3 tests (accountant sees supplier invoices and not the 403 pages; manager keeps every child; cashier still shut out). `SupplierInvoiceListPage.test.tsx:337` — the test the gate called "green while asserting the opposite of production" is retitled to say it covers the PAGE BODY only and points at the route-guard file; both are kept.

## Item 2 [MINOR] — `PartnerType::Both` pinned; the cashier over-deny documented

`tests/Feature/Treasury/SupplierPaymentPermissionTest.php` `test_cashier_may_take_an_inbound_receipt_from_a_both_typed_partner`: cashier + `both` partner + no document → **201**, and `repository_movements.direction === 'in'` with **no** `out` row. The direction assertion is the load-bearing half: the `Both` exclusion is only safe because direction is derived from a `SupplierInvoice` allocation and not from the partner, so if that coupling ever changes this test fails instead of the arm silently becoming a hole.

**Behaviour change, recorded, NOT changed** (gate r2 finding 3): arm 2 of `SupplierPaymentAuthorizer` refuses a cashier ANY document-less payment naming a **pure `supplier`** partner — including money coming IN (a supplier refunding cash at the till). Pinned by `test_cashier_cannot_make_an_on_account_payment_to_a_supplier`. It is deliberate (a document-less payment to a supplier partner is indistinguishable, at request time, from an on-account supplier payment) and it is **not a one-liner to relax**: direction is decided downstream in `PaymentController::store()`, after the point where the gate must run to leave no rows behind. The clean fix would be an explicit `direction`/`kind` on the request, which is a product change, not a fix-round edit. **Owner-visible consequence**: a till taking cash back from a partner typed `supplier` now needs `payments.pay-supplier`, or the partner should be typed `both`. Added to the promotion row's smoke list.

## Item 3 [MINOR] — the idempotency replay no longer skips the gate

`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:389-405` (`store`) and `:1512-1524` (`storeMultiple`): the replay arm now re-takes `assertMayPay` against the **persisted** payment — its partner and its allocated documents (`allocatedDocumentIdsOf()`, `:88-107`; both finders already eager-load `allocations`) — because a replay request carries nothing but the key. Moving the gate *above* the short-circuit was not possible: it needs validated, scoped ids that only exist after `$request->validate(...)`, which itself runs after the replay by design (a retry must not be re-validated).

Test: `test_cashier_cannot_replay_a_managers_supplier_payment_idempotency_key` — manager creates (201), cashier replays the same key → **403**, manager's own retry still replays → **200**, and exactly **one** payment row exists.

## Item 4 [MINOR] — unguarded uuid bind

`apps/api/app/Modules/Media/Presentation/Controllers/DocumentAttachmentController.php:193-202` — `Str::isUuid()` guard → 404 before the query. Test `test_malformed_document_id_is_a_404_not_a_database_error` covers GET and POST on `/documents/not-a-uuid/attachments`.

## Item 5 [MINOR / OWNER CONFIRM] — accountant is denied PO revert by default

**Owner-visible default, stated for confirmation.** The ruling's parenthetical named "admin/manager/accountant/purchases-type roles"; the shipped shape is narrower for this one act:

| Act | Permission | Default holders | Deliberately NOT default |
|---|---|---|---|
| Create / re-match / post a supplier invoice; attach to one | `supplier-invoices.manage` | admin, manager, **accountant** | cashier, operator, viewer, technician |
| Pay a supplier | `payments.pay-supplier` | admin, manager, **accountant** | cashier, operator, viewer, technician |
| **Un-confirm (revert) a confirmed purchase order** | `purchase-orders.confirm` (existing) | admin, **manager only** | cashier, operator, viewer, **accountant** |

Rationale: un-committing a supplier commitment is a purchasing act, not an accounting one, and `purchase-orders.confirm` already existed with exactly that tier — inventing a wider permission would have been the larger change. It stays fully grantable (`P2pEntryPointPermissionsTest::test_purchase_order_revert_permission_is_manager_tier_not_cashier_tier` pins the default; `DocumentRevertEndpointTest::test_directly_granted_purchase_order_confirm_permission_allows_revert` pins the grant). **If the owner wants accountants to revert POs by default, add `'purchase-orders.confirm'` to the accountant block in `RolesAndPermissionsSeeder.php:829` and flip the one assertion — no code change.**

## Verification — fix round 2

```
$ sysctl vm.swapusage    -> used 8571M then 9498M / 10240M — runs kept to single files, full suite never run

# Backend (apps/api, sqlite)
$ ./vendor/bin/phpunit tests/Feature/Treasury/SupplierPaymentPermissionTest.php
  OK (7 tests, 23 assertions)
$ ./vendor/bin/phpunit tests/Feature/Modules/Media/DocumentAttachmentApiContractTest.php
  Tests: 17, Assertions: 87, PHPUnit Deprecations: 14   (0 failures)

# Red-before-green (replay gate removed, test re-run, gate restored)
  test_cashier_cannot_replay_a_managers_supplier_payment_idempotency_key
    -> "Expected response status code [403] but received 200."

# Static
$ ./vendor/bin/phpstan analyse app/Modules/Media/Presentation/Controllers/DocumentAttachmentController.php \
    app/Modules/Treasury/Presentation/Controllers/PaymentController.php --memory-limit=1G --no-progress
  [OK] No errors
$ ./vendor/bin/pint --test <4 changed PHP files>   -> {"result":"pass"}

# Generators (byte-identity re-confirmed)
$ (apps/api) CACHE_STORE=array php artisan permissions:export-frontend-map
  -> git status --porcelain apps/web/src/hooks/permissionsMap.generated.ts : EMPTY (seeder untouched in r2)
$ node scripts/factory/gen-route-manifest.mjs      -> wrote routes-web.yaml (271 routes)
$ node --test scripts/factory/gen-route-manifest.test.mjs -> pass 10, fail 0

# Frontend (apps/web)
$ ./node_modules/.bin/vitest run src/features/purchases/supplier-invoices \
    src/hooks/__tests__/usePermissions.supplierInvoiceGates.test.tsx \
    src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx src/routes/routes.test.tsx
  Test Files 8 passed (8)   Tests 155 passed (155)
$ ./node_modules/.bin/vitest run tools/__tests__     Test Files 8 passed (8)   Tests 189 passed (189)
$ ./node_modules/.bin/tsc --noEmit                   exit 0
$ ./node_modules/.bin/eslint <6 touched FE files>    ✖ 7 problems (0 errors, 7 warnings)
$ (dev baseline, the same 5 pre-existing files)      ✖ 7 problems (0 errors, 7 warnings)   => 0 new
```

## Still outstanding after round 2

- **No browser/Playwright run.** Finding 1 is now proven at the route-guard level with the real hook and store, and pinned in the generated manifest — but nobody has clicked through as an accountant or a granted operator.
- **No deploy**; the promotion row is written, executing it is owner work.
- The PG leg was **not** re-run for round 2 (swap at 9.5G/10.2G); round 1's PG evidence stands for the files it covered. `SupplierPaymentPermissionTest`'s two new cases assert on `repository_movements`, which round 1 already exercised on PG.
- Unchanged from round 1: the 3 invoice-first 422s and the 2 PG `locations.code` truncations are pre-existing and un-ticketed; the deptrac ratchet red is inherited and clears on merge (gate r2 confirmed this independently).
