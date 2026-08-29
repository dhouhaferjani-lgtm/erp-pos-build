<!-- tenancy-authz-reviewer (Claude), merge-gate round 1, lane fix/h1-shape-neutral-cleanup @ 6bb800455, dispatched by Session H 2026-08-29 -->

# Merge-gate review — Session H lane `fix/h1-shape-neutral-cleanup`

**Branch:** `fix/h1-shape-neutral-cleanup` · **HEAD:** `6bb800455` · **Range:** `dev...HEAD` (39 commits, 75 files)
**Reviewer scope:** authz/tenancy lens — route middleware, permission-catalog sync, FE/BE gate parity, company scoping of the new code-uniqueness rule, e2e fixture leakage, convention 09 evidence.
**Not re-run (per instruction):** PHPUnit/PG legs. Ran: `phpstan` on the two touched request/DTO files (`[OK] No errors`), greps, file reads.

---

## Findings

### MAJOR

**1. The new company-scoped code rule has no CREATE-path deny test — convention 09's "re-run / idempotency" leg is missing.**
`apps/api/app/Modules/Partner/Presentation/Requests/CreatePartnerRequest.php:76-78` now scopes uniqueness to `company_id`. The only same-company rejection proof is on the UPDATE path (`apps/api/tests/Feature/Partner/UpdatePartnerTest.php:148`). Every test method in `apps/api/tests/Feature/Partner/CreatePartnerTest.php` was enumerated: `:77,:87,:97,:108,:120,:133,:146,:170,:206,:260,:272,:284,:296,:306` — none posts the same `code` twice into the same company. The lane's own second-company test (`CreatePartnerTest.php:206`) passes identically whether the create rule is company-scoped, tenant-scoped, or silently non-functional. Convention 09 (`docs/conventions/09-SECOND-OF-EVERYTHING.md`, "Re-run / idempotency") wants exactly this second run.
**Fix:** add `test_rejects_a_duplicate_code_in_the_same_company` — POST the same `code` twice with the same `X-Company-Id`, assert 422 on `code` and `assertDatabaseCount('partners', …) === 1`.

**2. Second-of-everything: second-location leg neither tested nor waived.**
Lane touches a catalogue entity (partners / `code`), so all three legs are required. Second company: present (`apps/api/tests/Feature/Partner/CreatePartnerTest.php:206`, `apps/api/tests/Feature/Partner/UpdatePartnerTest.php:172`, FE `apps/web/src/features/partners/PartnerForm.test.tsx:168`). Second location: absent, and `docs/sessions/session-H-party-model-2026-08-29/LANE-REPORT-h1-cleanup.md` carries no waiver line. Partners genuinely have no location column (`apps/api/database/migrations/tenant/2025_11_30_052119_create_partners_table.php` has no `location_id`), so this is closable by a written justification rather than a test — but per the convention, silence is MAJOR.
**Fix:** one line in the lane report: "second-location N/A — `partners` carries no location binding (create_partners_table)".

**3. Second write surface for partner creation now diverges from the primary on a required field.**
`apps/web/src/features/partners/PartnerForm.tsx:161-166` makes Nature (`customer_category`) required on create via zod. `apps/web/src/components/organisms/AddPartnerModal/AddPartnerModal.tsx:170-183` (the quick-create surface reachable from `PartnerPicker` inline add, `apps/web/src/components/molecules/pickers/PartnerPicker.tsx:11`) neither collects nor sends `customer_category`, and the backend does not require it (`CreatePartnerRequest.php:71` = `nullable`). So the secondary path keeps minting exactly the legacy null-Nature rows that M2's compensating heuristic (`apps/web/src/features/partners/partnerNature.ts:12-22`) exists to paper over. Convention 11 "one surface per concept". The lane declares it deferred ("inline-modal Nature gap", LANE-REPORT line 17) — surfacing it so the parent defers knowingly, not silently.
**Fix:** either add the Nature select to `AddPartnerModal` or record an owner ruling that quick-create legitimately produces unclassified partners.

**4. FE/BE gate parity fixed for list+edit, left inconsistent for create and detail.**
Corrected by the lane: `apps/web/src/routes/index.tsx:624` and `:873` now use `partners.update` (matches `apps/api/app/Modules/Partner/routes.php:47`), and `:843` composes `moduleKey="purchases"` + `partners.view` (matches `routes.php:23`). Left untouched in the same family:
- `apps/web/src/routes/index.tsx:604` (`customers/new` → `sales.create`) and `:853` (`suppliers/new` → `purchases.create`). Both are UI **role aliases**, not seeded permissions — `apps/web/src/hooks/uiAliasPermissions.ts:4-7` resolves them to roles `admin|sales|purchases|manager`, and `grep -c "sales.view" database/seeders/RolesAndPermissionsSeeder.php` = 0. The backend `POST /partners` requires `partners.create` (`app/Modules/Partner/routes.php:43`), held by `cashier` (seeder:668) and `operator` (seeder:769) — neither holds the alias roles, so both are **wrongly denied the create form** they are authorized to submit.
- `apps/web/src/routes/index.tsx:614` / `:863` (detail) gate on module only while `GET /partners/{id}` requires `partners.view` (`routes.php:27`).
Pre-existing, but M3's stated deliverable is "corrected partner route gates" — leaving half the family on aliases is an incomplete fix.
**Fix:** `customers/new`/`suppliers/new` → `permission="partners.create"` (optionally + `moduleKey`), detail routes → add `permission="partners.view"`. Or record the deferral explicitly.

### MINOR

**5. Code-uniqueness validator and DB index disagree on soft-deletes → 500 instead of 422.**
`CreatePartnerRequest.php:78` / `UpdatePartnerRequest.php:95` add `whereNull('deleted_at')`, but the live index is a plain `unique(['company_id','code'])` (`apps/api/database/migrations/tenant/2025_12_30_195300_fix_multi_company_unique_constraints.php:22`), which counts soft-deleted rows. A code held by a trashed partner in the same company passes validation and then violates the constraint. The analogous VAT case *is* handled with a dedicated message (`CreatePartnerRequest.php:155-163`); `code` is not. Shape is pre-existing (was the same under the tenant scope), but the lane owns these lines now.

**6. Quick-create silently stopped writing the address country.**
`AddPartnerModal.tsx:354-368` re-binds the field labelled `sales:partners.country` ("Country", rendered after city/postal code) to `country_code` — the **tax** country — with the placeholder `selectCountryCode` ("Select country for VAT"). `apps/web/src/components/organisms/AddPartnerModal/AddPartnerModal.test.tsx` asserts `expect(payload).not.toHaveProperty('country')`, so the address `country` column (accepted at `CreatePartnerRequest.php:133`) is now never populated from this surface. Deliberate, but a data-meaning change with a mismatched label.

**7. Credit-usage percentage truncates where it used to round.** `apps/web/src/features/partners/components/CreditLimitWarning.tsx:30` — `usagePercentage.split('.',1)[0]` renders 79.9% as "79" (was `Math.round` → "80"). Cosmetic, but the number sits next to an "approaching limit" warning whose threshold is 80.

**8. Arabic Nature label collides with Type.** `apps/web/src/locales/ar/sales.json` → `partners.nature.label` = "النوع", the same word used for the Type field. Two identically-labelled selects on the create form for AR users. Declared deferred in the lane report (line 17).

**9. `['partner', id]` query key now has a third writer with a narrower shape.** `PartnerForm.tsx:279` and `PartnerDetailPage.tsx:173` cache the full `PartnerData` under `tenantScopedKey(['partner', id])`; `PartnerPicker.tsx:146` caches the slim `PartnerPickerValue` under the same key, and this lane wires the picker into `apps/web/src/features/vehicles/VehicleForm.tsx:221-232`. Visiting a vehicle edit page then the partner edit form can hand `reset()` a stripped object until the refetch lands. Pre-existing collision; the lane adds a consumer.

**10. The FE gate test's ALLOW actor is a permission the server never issues.** `apps/web/src/routes/PartnerRoutes.gates.test.tsx:79` grants `['partners.view','purchases.view']`; `purchases.view` is a UI alias resolved by *role* (`uiAliasPermissions.ts:6`) and is absent from `RolesAndPermissionsSeeder`. The deny cases are sound; the realistic allow actor (role `manager`) is untested for `/purchases/suppliers`.

**11. POS fixtures still seed the retired value.** Production code stopped emitting `customer_category: 'retail'` (`apps/pos/src/lib/customer/pendingCustomerCreateService.ts:45`, `apps/pos/src/components/customers/CustomerAttachPanel.tsx:163`), but `apps/pos/src/stores/__tests__/paymentStore.customerAttach.test.ts:67`, `paymentStore.audit.test.ts:66`, `paymentStore.branchSeller.test.ts:175`, `components/customers/CustomerSearchModal.test.tsx:155`, `CustomerSearchInput.test.tsx:42` still assert against an impossible server value.

**12. Default `playwright.config.ts` now picks up mutating session-H specs.** `apps/web/playwright.config.ts:4` (`testDir: './e2e'`) matches `e2e/session-h/*.spec.ts`, which create a real role + user in the demo tenant (`m3-…spec.ts:327-344`) and shell out to `php artisan tinker` against `apps/api` (`:249-252`). Not a CI risk — the CI job runs `playwright.smoke.config.ts` (`testDir: './e2e/smoke'`, `testMatch: /.*\.smoke\.ts$/`, `.github/workflows/smoke-test.yml:53`) — but a plain local `playwright test` mutates a demo tenant and hard-fails (not skips) when it isn't seeded.

---

## Verified OK

- **No blockers, no cross-tenant/cross-company leak.** The "cross-company code 422→201" deferral is the **correct** consequence, not a leak: the live index is `unique(['company_id','code'])` (`2025_12_30_195300_fix_multi_company_unique_constraints.php:20-23`), so the request rule now *matches* the schema instead of over-refusing. Both code-keyed lookups are company-scoped — `app/Modules/Partner/Application/Services/PartnerService.php:83-92` and `app/Modules/Document/Application/Services/ArApOpeningService.php:152-153` (`Partner::forCompany($companyId)->where('code', …)`) — so duplicate codes across companies cannot resolve to the wrong partner. Cross-company update is proven 404 (`UpdatePartnerTest.php:172`).
- **No new `unique(['tenant_id', …])`**: the diff contains zero migrations. `tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php` / its baseline do not exist in this tree yet (lane I-2 unlanded), so no stale-baseline exposure.
- **Route middleware (rule 12) intact**: `app/Modules/Partner/routes.php:20` = `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]` with per-route `can:` and `whereUuid('partner')` on every UUID binding (`:29,:35,:40,:49,:54,:59,:64,:69`). Unmodified by this lane.
- **Permission-catalog sync**: every permission the diff newly requires is seeded and granted — `partners.view` (seeder `:52`; roles `:563,:668,:704,:744,:769,:803`), `partners.update` (`:54`; roles `:563,:769`), `contacts.update` retained on the true contact route (`:447`; role `:645`). No new `can:` guard was introduced, so **no tenant re-seed is required** for this lane.
- **No role loses access**: `customers/:id/edit` + `suppliers/:id/edit` move `contacts.update`→`partners.update`; the only non-admin holder of `contacts.update` is `manager`, which also holds `partners.update`, and `operator` correctly *gains* a form the API already allowed it to submit. `/purchases/suppliers` adds `partners.view` on top of the `purchases` module alias (roles admin/purchases/manager) — `manager` and `admin` hold it, so the composition denies nobody who could enter before.
- **Retired `/crm/companies` fully removed**: route (`routes/index.tsx`, was `:2785-2798`), nav entry (`Sidebar.tsx:263`), page file deleted, `crm.companies.*` + `nav.companies` keys removed in en/fr/ar with **zero orphan references** (greps for `crm:companies`, `b2b.customerCategory|selectCategory|b2b.individual|b2b.business`, `vehicles:noOwner` all empty), and the deletion is pinned by `routes/PartnerRoutes.gates.test.tsx:104` (route does not resolve) + `routes/routes.test.tsx:107`.
- **Money handling (rule 19) improved, not regressed**: `CreditLimitWarning.tsx` drops four `parseFloat` calls and `toLocaleString` for `bccomp/bcdiv/bcmul` + `formatCurrency` (`apps/web/src/lib/decimal.ts:65,80,95,115,179`), with currency threaded from `currentCompany.currency` (`B2BFieldsSection.tsx:29,178-183`). Division is guarded by `bccomp(creditLimit,'0') <= 0` (`CreditLimitWarning.tsx:25`).
- **No fiscal/sealed-payload regression from the POS `'retail'`→`null` change**: `assertOptionalNonEmptyStringAt` returns early on `null` (`apps/pos/src/lib/fiscal/FiscalEventEngine.ts:3427`), and the B2B branch keys on `=== 'business'` (`apps/pos/src/lib/accountCharge/accountChargeService.ts:378`), so classification is unchanged.
- **`tax_status` DTO default is truthful**: the column is NOT NULL `default('REGISTERED')` (`2026_01_02_100001_add_tax_exemption_to_partners.php:19`), so the `?? PartnerTaxStatus::REGISTERED` fallback (`PartnerData.php:90-96`) mirrors the schema rather than inventing a value. PHPStan level 8 clean on that file.
- **Vehicle-owner narrowing loses nothing**: `type=customer` includes `Both` (`PartnerController.php:74`, `Partner::scopeCustomers` `:257-260`), and a legacy supplier-owned vehicle still renders its owner because the picker resolves a string id through `GET /partners/{id}` regardless of the type filter (`PartnerPicker.tsx:145-152`).
- **Both-layer module gating for the vertical-exclusive surface the lane touched**: `apps/api/app/Modules/Vehicle/Presentation/routes.php:22` carries `module:Vehicle`; `routes/index.tsx:1489,1501,1513,1525` carry `<ModuleGuard module="Vehicle">`.
- **E2E fixture hygiene**: the disposable-user helper scopes its lookup by tenant and initialises tenancy explicitly (`m3-…spec.ts:241-246`), refuses to run outside `local|testing` (`:239`), and `afterAll` deletes role + user and asserts the disposable token is revoked (401) with a failure list that must be empty (`:400-433`). No cross-tenant read: the Otospex (`demo-unlimited`) and PharmaBio sessions use separate browser contexts and explicit `tenant_id`.
- **Test quality**: deny paths are exercised on both layers — `PartnerRoutes.gates.test.tsx:66,79` (403-equivalent redirect for `contacts.update`-only and for each half of the supplier gate), `CreatePartnerTest.php:306` (no-permission create), `PartnerForm.test.tsx` Nature/legacy-B2B/credit-exposure cases. No `assertTrue(true)`, no mocked subject-under-test.

---

**What to fix before merge:** add the create-path same-company duplicate-`code` 422 test (finding 1) and write the one-line second-location N/A waiver (finding 2); then have the parent explicitly accept or close findings 3 and 4 (quick-create Nature gap, create/detail route gates still on UI role aliases).

VERDICT: ACCEPT-WITH-CONDITIONS
