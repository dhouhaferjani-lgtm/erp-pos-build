## M3 round‑1 adversarial register — `fix/h1-shape-neutral-cleanup` (`bdc228a18..7eebc4522`)

**Lenses applied:** `tenancy-authz` (applies — route gates changed), `frontend-conventions` (applies — routes/pickers/forms/locales). Rule‑19 money/quantity, migrations, queues, constructor injection, backend tenant scoping: **N/A — this milestone is frontend + Playwright only** (`git diff --stat` shows zero PHP/pos/migration files).

**Gates I ran myself (all green, so not findings):** `pnpm typecheck` clean; `eslint` on all 8 touched FE files = 0 errors (22 pre‑existing warnings); `tools/audit-tanstack-keys.mjs` Gate C = 0 new; `tools/audit-design-system.mjs` = 807 acknowledged / 0 new; `audit:i18n:local` OK (55 ns, 8 baseline entries burnt down, no re‑pin needed); `vitest` on the 7 milestone test files = 95/95 pass; `vitest` on 60 PartnerPicker‑consumer files (documents/vouchers/scheduling/crm) = 473/473 pass.

---

### 1. **P1 — CONFIRMED** — `apps/web/src/routes/index.tsx:843-845` — the suppliers list lost its module gate instead of gaining a permission gate
The brief's premise ("`/purchases/suppliers` list route currently has no permission gate … add `partners.view` **matching `/sales/customers`**") is factually wrong: the route was gated `moduleKey="purchases"` (see the diff hunk). The implementation **replaced** it rather than AND‑ing — and `RequirePermission` supports both props simultaneously (`apps/web/src/features/auth/components/RequirePermission.tsx:51-58` evaluates `moduleKey` then `permission`). `/sales/customers` itself is `moduleKey="sales"` (`routes/index.tsx:596`), so the result does **not** match it.

Failure scenario (widening): `moduleKey="purchases"` → `purchases.view`, a UI alias held by `['admin','purchases','manager']` (`hooks/uiAliasPermissions.ts:6`; `purchases` is not a seeded role). `partners.view` is held by `accountant, admin, cashier, manager, operator, technician, viewer` (`hooks/permissionsMap.generated.ts:152`). A **cashier or viewer** now types `/purchases/suppliers` and gets the full supplier list with balances — previously denied. The Purchases nav group still hides it (`components/organisms/Sidebar/Sidebar.tsx:176`), so the nav gate and the route gate now disagree; a bookmark/back‑nav is enough.
Failure scenario (narrowing + inconsistency): an actor holding `purchases.view` but not `partners.view` is now blocked from the list — and this is **pinned as intended** by the new test (`routes/PartnerRoutes.gates.test.tsx:64-75`, `setActor([], ['purchases.view'])` → dashboard fallback). Meanwhile `suppliers/:id` is still `moduleKey="purchases"` (`routes/index.tsx:864`) and `suppliers/new` still `purchases.create` (`:854`), so a cashier can open the list and is bounced to `/dashboard` on any row click.
Fix: `<RequirePermission moduleKey="purchases" permission="partners.view">` and update `PartnerRoutes.gates.test.tsx:64-75` to assert both legs.

### 2. **P1 — CONFIRMED** — M3 addendum "second company" test is absent from the entire lane
The addendum (brief §2 M3, "Journey hardening", explicitly *"Add, in this lane"*, graded **MAJOR** by the brief itself) requires a case where company B of the same tenant creates a partner with the **same `code`** as company A's and a different nature — both persist, `/sales/customers` is company‑scoped, Nature‑required and the B2B NULL heuristic behave identically for B.
Verified absent: no such case in the M3 range, and none anywhere in the lane (`23b1b8a65..HEAD`). The only multi‑scope partner test touched is `apps/api/tests/Feature/Partner/ListPartnersTest.php:265-300`, which is **cross‑tenant**, not second‑company‑same‑tenant, and it landed in M1 (`1e0b61b69`). Grep for `otherCompany|companyB|company-2|second company` across `apps/web/src`, `apps/web/e2e`, `apps/api/tests/Feature/Partner` returns no same‑code case.
Failure scenario: the unique constraint is `(company_id, code)` (`apps/api/database/migrations/tenant/2025_12_30_195300_fix_multi_company_unique_constraints.php:22-34`); a duplicate‑code collision or a company‑bleed in the customers list ships unnoticed, which is precisely the regression class this rule exists to catch.

### 3. **P2 — CONFIRMED** — no M3 register / evidence artifact; the addendum's four required written statements are nowhere
`docs/handoff/reviews/session-h-phase1/` contains M1 and M2 artifacts only (`M2-implementation-evidence.md`, `M2-round1..3.md`) — nothing for M3. Consequently none of the addendum's mandatory statements exist: the "second location — not in scope, no location‑keyed table touched" declaration; the re‑run/idempotency reference to `ImportReExecutionGuardTest` **with file:line** (grep for `ImportReExecutionGuard` under `apps/web` and the reviews dir → 0 hits) and the "heuristic is a pure function of the row" assertion (grep `idempot|rerender` in the partners tests → 0 hits); the `Concepts:` block citing a3 for the hand‑rolled‑type rule; the industry‑baseline citation of spec §2.1–2.5. `docs/handoff/progress/session-h-phase1.progress.yaml:73-78` carries M1/M2 `owes_parent` rows and **nothing for M3**.

### 4. **P2 — CONFIRMED** — browser‑gate evidence is stale relative to HEAD, and gate item (v) has no evidence at all
`.playwright-mcp/session-h/m3/` holds 6 PNGs, all written 16:31–16:32. The final two commits that change the spec and helpers — `08bac61fa` "fail Otospex post-auth discovery regressions" (16:33:46) and `7eebc4522` (16:37:50) — postdate every screenshot, so **the committed spec has never been shown to run**. Separately, brief §2 M3 gate item **(v)** (Otospex `/vehicles/new` owner search returns customers only, supplier name yields no match) produces no screenshot by design and must therefore be reported as run‑or‑skipped‑with‑reason in the register — which does not exist (finding 3). The a9 change is the one behavioural change in this milestone with **zero** browser evidence.
Note: the fixture choice is sound — `demo-unlimited` is `Vertical::Mechanic` (`apps/api/database/seeders/DemoTenantSeeder.php:145-151`), and after M3.7 a post‑auth discovery failure throws rather than silently skipping (`e2e/session-h/helpers.ts:66-104`). It just hasn't been demonstrated.

### 5. **P2 — CONFIRMED (consequence PLAUSIBLE)** — the M3 spec mutates a *seeded* demo user's roles instead of creating its own
`e2e/session-h/m3-dead-crm-partner-vehicle-gates.spec.ts:296-317`: `beforeAll` creates a temp role, assigns it to the seeded `viewer@pharmabio.tn`, then **DELETEs that user's `viewer` role**. The brief said to *create* a user via the API with a role built in the spec. Restoration lives in `afterAll` (`:328-352`) and is asserted, but `afterAll` does not run on SIGINT/worker kill/harness timeout.
Failure scenario: an interrupted run leaves the shared PharmaBio demo tenant's `viewer@pharmabio.tn` with no `viewer` role and a stray `session-h-m3-contacts-only-<ts>` role. Every later manual demo, other lane, or session using that account then observes wrong permissions with no signal that a test did it.

### 6. **P2 — CONFIRMED** — no red‑first evidence for any of the three behavioural changes
`M3.1` (`13f9c9590`), `M3.2` (`e223d3575`) and `M3.3` (`bf122421e`) each commit the new test **and** the implementation in the same commit, and every commit body in the range is empty (`git log --format='%b'`). With no M3 report either (finding 3), there is nothing showing `PartnerRoutes.gates.test.tsx`, the Type‑option tests, or the VehicleForm picker tests failing before the fix. Brief §1 requires TDD red‑first; the standing check requires it to be demonstrable.

### 7. **P3 — CONFIRMED** — orphaned i18n keys left behind by the two deletions
`nav.companies` survives in `src/locales/en/common.json:261`, `fr/common.json:201`, `ar/common.json:262` after the Sidebar entry was removed, and `vehicles:noOwner` (`en/vehicles.json:36`, `fr:36`, `ar:26`) is now unreferenced since the raw `<Select>` with the "No owner" option was replaced. Neither breaks the i18n gate (it passed, reporting an 8‑entry burn‑down and no re‑pin need), so this is a hygiene item — but the burn‑down itself is the kind of thing the brief wanted recorded in `owes_parent`.

### 8. **P3 — CONFIRMED** — composed translation string in the new clear button
`src/components/molecules/pickers/PartnerPicker.tsx:240`: `` `${t('common.clear')} ${effectiveLabel}` ``. Concatenating two translated fragments hard‑codes English word order; FR ("Effacer Propriétaire") and AR read wrong. Convention is one key with interpolation (`t('common.clearField', { field })`) plus EN+FR values in the same commit.

### 9. **P3 — CONFIRMED (benign)** — the picker markup change silently restyles six unrelated surfaces
`PartnerPicker.tsx:210-251` now renders the label + a `role="group"` wrapper in the **selected** state, which previously had no label. That is a genuine improvement (the label used to vanish on selection), but it changes layout for `SupplierInvoiceCreatePage.tsx:713`, `QuoteRequestCreatePage.tsx:218`, `AppointmentFormDrawer.tsx:283`, `ExpenseRecurrenceForm.tsx:257`, `ExpenseFormFields.tsx:116` and the voucher modals — none of which are in a9's scope or in the M3 browser gate. I ran 473 consumer unit tests: no functional breakage. Flagging as scope/evidence only.

### 10. **P3 — PLAUSIBLE** — the BUG‑006 mechanism assertion was rewritten rather than preserved
`src/features/partners/__tests__/partnerListRouteType.test.tsx:288-301` changed from asserting the stale value `'customer'` to asserting `''`. The *mechanism* is unchanged — RHF still holds `type: 'customer'` after an unkeyed reconciliation; only the DOM can no longer display it. So on the supplier route the visible Type is empty while form state says `customer`, and a submit would post `customer`. Production routes are keyed (`routes/index.tsx:847,876` `key="supplier"`), so there is no live path today, but the divergence between rendered value and submitted value is now untested.

### 11. **P3 — CONFIRMED, pre‑existing, out of scope** — VehicleForm fuel/transmission literals don't match the backend enums
`src/features/vehicles/VehicleForm.tsx:49-50` offers `'Petrol' | 'Diesel' | …` / `'Manual' | …`, while the API enum is `FuelType::Gasoline = 'gasoline'` (`apps/api/app/Modules/Vehicle/Domain/Enums/FuelType.php:9`) — the canonical values the lane's own new fixture now uses (`VehicleForm.tenantScope.test.tsx:108-109`). Editing a vehicle whose `fuel_type = 'gasoline'` shows a blank select and saving nulls the field. Untouched by this diff; recording it for the parent / Session H since M3 edited this file and the addendum asks for hand‑rolled‑type drift to be cited.

---

### Bypasses I attempted that FAILED to find a defect
- **`both`‑type partners excluded from the vehicle owner picker?** No — `PartnerController.php:70-79` maps `type=customer` to `whereIn('type', [Customer, Both])`. Fleet/dual‑role owners remain selectable.
- **Legacy owner who is a supplier or inactive becomes unrenderable on vehicle edit?** No — `PartnerPicker.tsx:145-153` resolves the id via unfiltered `GET /partners/{id}`, independent of `partnerType` and `is_active`.
- **Clearing the owner posts `''` and 422s?** No — `VehicleForm.tsx:131,153` both send `data.partner_id || null`.
- **Residual `/crm/companies` reference in a nav config, command palette, or lazy import?** None: grep across `apps/web`/`apps/pos` returns only the three test/spec assertions.
- **Collateral breakage from the PartnerPicker restructure?** None: 60 consumer test files / 473 tests pass, including the unmocked renderers in `documents`, `vouchers`, `crm`, `scheduling`.
- **A legitimate actor locked out by `contacts.update → partners.update`?** No — `partners.update` = `admin, manager, operator` ⊇ `contacts.update` = `admin, manager` (`permissionsMap.generated.ts:45,151`); this leg is a correct, brief‑authorised change.
- **Vacuous new gate tests?** No — `PartnerRoutes.gates.test.tsx:88-93` would render `partner list` (the old redirect page) rather than `dashboard fallback` if the Companies route survived; the mocks match the real lazy imports (`routes/index.tsx:41,43,280`).
- **Broken ratchets from the key deletions?** No — tanstack Gate C, design‑system, and the i18n baseline authority all pass with 0 new entries.

VERDICT: CHANGES-REQUIRED
