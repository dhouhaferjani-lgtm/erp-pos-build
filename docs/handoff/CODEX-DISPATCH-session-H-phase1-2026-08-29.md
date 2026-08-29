# Codex dispatch — Session H (B-18 party/contact program), **Phase 1: shape-neutral cleanup** (2026-08-29)

> ## ⚙️ EXECUTION MODE — SELF-REVIEWING WAVE (read this before anything else)
> This wave runs under **`docs/handoff/SELF-REVIEW-HARNESS.md`**. You do NOT hand back to a human
> between milestones. At the end of every milestone you run the adversarial review YOURSELF via
> `scripts/adversarial-review.sh` (it calls `claude -p --model opus`), read its register, and loop
> scoped fix rounds until ACCEPT — then move on.
> - **State file: `docs/handoff/progress/session-h-phase1.progress.yaml`** — read it first, update it
>   after every milestone (status, commit SHA, verdict path, fix_rounds). It is your resume point.
> - **Reviewer fallback (Anthropic weekly limit until 2026-08-30 15:00 Africa/Tunis):** if
>   `adversarial-review.sh` exits **3** (tool error) twice in a row for one milestone, write your OWN
>   adversarial register for that milestone (same lenses, numbered findings, `VERDICT:` line) to the
>   register path, set `reviewer_model: codex-fallback` on that milestone in the YAML, and continue.
>   The parent re-gates every codex-fallback milestone with the Claude reviewer agents at merge.
> - Per-milestone registers go to `docs/handoff/reviews/session-h-phase1/`.
> - **Branches NOT merged into dev, NOT pushed.** The parent orchestrator (Session H) merges after gates.
> - **STOP and escalate only at the three harness STOP conditions:** fix rounds exhausted (max 4), an
>   owner gate (see §5 — anything that changes SEALED BYTES or a payload KEY SET is an owner gate),
>   or an architecture contradiction. Set the YAML `status` + `blockers` and end your run.

**Model/effort (owner directive):** Codex SOL/Luna, HIGH effort. Workhorse mode: TDD red-first,
milestone by milestone, self-gated.

**Citation convention:** every path is repo-root-relative from `/Users/houssamr/Projects/syneriva/apps/erp`.
Line anchors were verified against **`23b1b8a65`** (= local `dev` tip at worktree creation). Re-verify
each anchor with `grep -n` before editing — do not trust a line number blindly.

**Two worktrees are ALREADY CREATED and dependency-ready** (vendor copied + `composer dump-autoload`,
`.env` copied, `pnpm install --offline` done). Do not create new ones; do not `git stash` (repo-global
stash — forbidden in worktree lanes); never combine `--force` with `git push` in one command.

| Lane | Worktree | Branch | Milestones |
|---|---|---|---|
| **H1-cleanup** | `.worktrees/h1-cleanup` | `fix/h1-shape-neutral-cleanup` | M1 → M2 → M3 |
| **H1-a1** | `.worktrees/h1-a1-buyer` | `fix/h1-a1-buyer-block` | M4 → M5 |

Do **H1-cleanup first** (M1–M3), then **H1-a1** (M4–M5). They are independent branches; keep them so.

---

## §0 — Read before writing code, in this order

1. `docs/superpowers/specs/2026-08-23-party-contact-target-model-research.md` — **§0, §1.1–1.5, §5.3–5.4,
   §7.3–7.5, §8.2 Flow 1, §8.3, §9**. §9 is the authoritative scope of every lane below.
2. `docs/handoff/AUDIT-parties-partners-disambiguation-2026-08-23.md` §3.2, §5.1–5.8, §6.2(a).
3. `docs/sessions/session-H-party-model-2026-08-29/OQ-SHEET-2026-08-29.md` — what is OWNER-GATED
   (Phases 2–5). **Nothing in this brief needs an owner answer**; if you find you need one, that is a STOP.
4. `apps/erp/CLAUDE.md` rules 2, 3, 6, 7, 9, 11, 12, 13, 14, 18, 19 and `docs/conventions/01`, `03`, `04`, `06`.
5. `docs/handoff/SELF-REVIEW-HARNESS.md`.
6. `docs/handoff/ASSESSMENT-crm-seam-party-model-2026-08-29.md` — why the model is safe for a future
   marketing CRM and what is deliberately NOT built. Do not add consent/tag/channel columns or any
   marketing surface in this wave.

---

## §0.1 — Browser-level verification is a GATE for every milestone (owner requirement)

Unit tests are necessary, not sufficient. Every milestone ships **committed Playwright specs** that
drive the real UI, plus screenshots, and the milestone's self-review must read them.

**Local stack (already running, owned by the main checkout — do NOT stop or restart it):**
- API `http://127.0.0.1:8010` (`php artisan serve` from `apps/api` of the MAIN checkout, db-per-tenant,
  health `GET /api/v1/health`), web vite `http://localhost:5173` (main checkout), PG `127.0.0.1:5433`,
  demo tenant **PharmaBio Tunisie** — login `owner@pharmabio.tn` / `password` (also `manager@` /
  `cashier@`). Queue worker may be needed for projections:
  `php artisan queue:work redis --queue=default,fiscal-projections,enrichment,images,imports`.
- **Your FE changes are NOT on :5173.** Run the worktree's web on its own port:
  `cd .worktrees/<lane>/apps/web && pnpm dev --port 5174 --strictPort` (vite proxies `/api` → :8010,
  i.e. the MAIN checkout's API — fine for FE-only milestones).
- **Your API changes are NOT on :8010.** For API-side assertions run the worktree's API on its own port:
  `cd .worktrees/<lane>/apps/api && php artisan serve --host=127.0.0.1 --port=8011` and hit
  `http://127.0.0.1:8011/api/v1/...` with Playwright's `request` fixture (Bearer token from
  `POST /auth/login`). Pattern to copy: `apps/web/e2e/smoke/treasury-phase5a-outbound.smoke.ts:17-27,113-118`
  (`test.use({ baseURL })`, env-overridable `API_BASE`, login via the API).
- Playwright config: `apps/web/playwright.config.ts` (`testDir ./e2e`, `reuseExistingServer` locally —
  it will NOT start a server for you and its default `baseURL` is :5173, so **always** `test.use({
  baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:5174' })` in your specs).
- Put specs in **`apps/web/e2e/session-h/<milestone>-*.spec.ts`**; run with
  `cd apps/web && pnpm exec playwright test e2e/session-h/<file> --reporter=line`. Screenshots →
  `.playwright-mcp/session-h/<milestone>/` (git-excluded) and referenced by path in the register.
- The POS (`apps/pos`) is Tauri + SQLite and **cannot be driven in a browser**. For H1-a1 the browser
  gate is the SERVER side (validator + projection via :8011) and the device side is Vitest; the parent
  runs the on-device smoke (`pnpm tauri dev`) at the merge gate. Say so in the M4/M5 report.
- Never enter credentials other than the demo tenant's; never point a spec at staging.

**Locked (do not relitigate):** R1–R5 of the spec §0; the P0 regex convergence is DONE (`929de7b34`);
`a2` (retire `ImportType::Partners`) belongs to **Session G** — you do NOT touch the `Partners` enum
case, its rules, or the import wizard cards; `a4` as written in the audit is REPLACED by §2 M2 below.

---

## §1 — Standing constraints for every milestone

- **Shape-neutral:** no migration, no new column, no new enum value, no new sealed-payload key, no
  change to any canonical-bytes value that devices already sign — **except** the one deliberate
  `buyer` VALUE change in H1-a1, which is the lane's whole purpose and is fixture-pinned (§3).
- **Types flow from backend (rule 7):** never hand-edit `packages/shared/types/`; if a PHP DTO changes,
  run `php artisan typescript:transform` (with `CACHE_STORE=array` in the worktree) and commit the output.
- **Frontend:** `t()` for every string (EN + FR keys in the same commit; add AR keys where the namespace
  already has an `ar/*.json`); design tokens for any colour class you touch; `tenantScopedKey` on every
  tenant-data query key; zod + RHF for forms you touch.
- **Backend:** constructor injection only; PHPStan level 8 clean on touched files; Pint clean.
- **PG test legs — per-session database (machine rule 2026-08-29 evening, from the orchestrator/broker):** the shared default test DB was `migrate:fresh`-ed mid-run by concurrent sessions. Every PG leg in this lane (and in the parent's reviewer agents) MUST run as `DB_DATABASE=autoerp_test_h DB_CENTRAL_DATABASE=autoerp_test_h php artisan test -c phpunit-pgsql.xml <paths>` (DB already exists on 127.0.0.1:5433). ONE PG leg at a time within this session. Never run a PG leg against the default DB name.
- **Tests:** TDD red-first. Backend tests by PATH only (`./vendor/bin/phpunit <file>`); **never the full
  suite**. Web: `pnpm vitest run <dir>`; kill stray workers after (`pkill -f 'node (vitest'`).
- **At every commit:** `cd apps/api && php tools/feature-lane-manifest-check.php` — a new Feature test
  class needs a manifest ceiling raise (precedent notes in the file).
- Commit prefix: `h1-cleanup M<m>.<s>:` / `h1-a1 M<m>.<s>:`. Small commits, one concern each.

---

## §2 — Lane H1-cleanup (`.worktrees/h1-cleanup`)

### M1 — a3 + a7 + a8: data-shape drift fixes

**a3 — `tax_id → vat_number`, consume generated `PartnerData`.**
- `apps/web/src/features/partners/PartnerListPage.tsx:33` declares a hand-rolled `tax_id: string | null`
  and renders `partner.tax_id ?? '-'` at `:392`; the API returns `vat_number`. Result: the Tax ID column
  is `-` for every VAT-bearing partner (audit §5.2).
- Replace the local `Partner` interface in `PartnerListPage.tsx` and `PartnerForm.tsx` with the
  generated `PartnerData` from `packages/shared/types/` (find its exact export name; grep
  `PartnerData` in `packages/shared/types`). Where the generated type is missing a field the UI needs,
  that is a BACKEND DTO gap: add it to the PHP DTO, re-transform, commit — do not widen the FE type.
- `apps/web/src/components/organisms/AddPartnerModal/AddPartnerModal.tsx` posts `tax_id` and `address`
  (which `CreatePartnerRequest` does not declare → silently dropped) and a free-text `country` that
  422s against `size:2` (audit §5.3). Rebuild its payload against `CreatePartnerRequest`
  (`apps/api/app/Modules/Partner/Presentation/Requests/CreatePartnerRequest.php`): `vat_number`,
  `address_line1`/city/etc. as the request actually names them, `country_code` as ISO-2 from the
  existing countries source used by `PartnerForm`.
- Tests: Vitest on `PartnerListPage` (renders the vat_number in the Tax ID column) and on
  `AddPartnerModal` (submits `vat_number` + address fields + ISO-2 country; never `tax_id`).
  Check `apps/web/src/features/partners/partners.test.tsx:512` — an existing test already asserts the
  form binds `vat_number`; extend, don't duplicate.

**a7 — `Parties` import `code` `max:100 → max:50`.**
- `apps/api/app/Modules/Import/Domain/Enums/ImportType.php` — the `self::Parties` rules block (starts
  `:169`; verify). `partners.code` is `varchar(50)` (confirm in the partners migration under
  `apps/api/database/migrations/`), so 51–100 chars pass validation and fail with PG `22001`.
  Touch ONLY the `Parties` block. If the `Partners` block (`:182`) carries the same bug, leave it and
  note it in your report for Session G.
- Test: extend the existing Parties import validation test (grep `ImportType::Parties` under
  `apps/api/tests/Feature/Import`) with a 51-char code → 422 with the right field error.

**a8 — `'retail'` mirror literal → `null`.**
- `apps/pos/src/lib/customer/pendingCustomerCreateService.ts:45` and
  `apps/pos/src/components/customers/CustomerAttachPanel.tsx:163` set `customer_category: 'retail'` on
  the optimistic device mirror. `'retail'` is not a value of the server enum
  (`apps/api/app/Modules/Partner/Domain/Enums/CustomerCategory.php`: `individual|business`). Set `null`.
- **DO NOT touch `apps/pos/src/lib/fiscal/payloads/AccountPaymentPayload.ts:116`** — that literal is
  inside a sealed payload; it is flagged to the owner (OQ sheet §C-4) and out of scope here.
- Test: the existing pos test for pendingCustomerCreateService / CustomerAttachPanel asserts `null`.

**M1 browser gate** (`e2e/session-h/m1-*.spec.ts`, :5174 + :8011): (i) log in, open `/sales/customers`,
assert a VAT-bearing demo partner shows its `vat_number` in the Tax ID column (pick one via the API
first); (ii) open the inline Add-partner modal from a document/quote screen, submit name + VAT + city +
country, then `GET /partners/{id}` on :8011 and assert `vat_number` and the address fields persisted;
(iii) `POST` a Parties import validation on :8011 with a 51-char `code` → 422 naming `code`.

**M1 review lenses:** `frontend-conventions`, `imports`.

### M2 — a4′: nature required on create + NULL heuristic for the B2B block

The audit's a4 ("treat NULL as show-it") is REJECTED (spec §9). Implement instead, FE-only, no schema:
- In `apps/web/src/features/partners/PartnerForm.tsx`, the `customer_category` select (grep
  `customer_category`; currently labelled "Customer Category") becomes a **required** field on the
  CREATE form, labelled via new keys `sales:partners.nature.label` = EN "Nature" / FR "Nature", options
  `sales:partners.nature.individual` = "Individual" / "Particulier" and `sales:partners.nature.company`
  = "Company" / "Société" (AR if `ar/sales.json` exists: "النوع" / "فرد" / "شركة"). Values stay
  `individual` / `business` (server enum untouched). Default `business` when the route is
  `/purchases/suppliers/new`, no default on `/sales/customers/new`. Edit form: not required (legacy
  NULL rows must stay editable).
- The B2B block gate (`apps/web/src/features/partners/components/B2BFieldsSection.tsx` (228 lines; signature `:19`), plus
  `PartnerForm.tsx` where the section is conditionally rendered — grep `customer_category ===`) becomes:
  show when `customer_category === 'business'` **OR** (`customer_category === null` AND any of
  `vat_number`, `company_legal_name`, `business_registration_number`, `credit_limit` is non-empty).
  Walk-ins (NULL + none of those) keep the block hidden. This is the Phase-2 backfill heuristic run
  early, in the view.
- Wire `CreditLimitWarning.tsx` (built, zero importers — grep it) wherever the credit limit renders
  in the B2B section. Small, in scope. **Amendment 2026-08-29 (Phase-2 gate finding F-14):** the
  component compares money with `parseFloat` / JS numbers
  (`apps/web/src/features/partners/components/CreditLimitWarning.tsx:22,61`) — rule 19 forbids that.
  BEFORE wiring it, convert it to the shared decimal-safe helpers (`apps/web/src/lib/decimal` —
  grep `bccomp`/`compare` + `formatCurrency`) and add boundary tests (equal-to-limit, one millime
  over, 3-decimal TND). If that conversion is not small, wire nothing and record it in `owes_parent`.
- Tests: Vitest — create form refuses submit without nature; suppliers route defaults to Company;
  B2B block visible for NULL+vat_number, hidden for NULL+nothing; warning renders over limit.

**M2 browser gate** (`e2e/session-h/m2-*.spec.ts`, :5174): (i) `/sales/customers/new` — submit without
Nature → inline error, no request sent; choose *Individual*, fill name + phone → created, B2B block
was hidden; (ii) `/purchases/suppliers/new` — Nature preselected *Company*, B2B block visible;
(iii) open an existing demo partner that has `vat_number` but `customer_category = NULL` (find one via
the API) → B2B block visible; open a NULL walk-in with no B2B data → hidden. Screenshots of both.

**M2 review lenses:** `frontend-conventions`.

### M3 — a5 + a6: dead surfaces and misgated routes

**a5 — delete Companies; fix the `contacts.update` gate; gate suppliers.**
- Delete the `companies` route block (`apps/web/src/routes/index.tsx:2786-2798`), its lazy import
  (`:279`), `apps/web/src/features/crm/pages/CompanyListPage.tsx`, its nav entry (grep
  `crm/companies` and `companies` across `apps/web/src/components/layout/` and any nav config), and the
  now-unused `crm:companies.*` key block in every locale file (EN/FR/AR). Keep `/crm/contacts*`
  routes for now (owner-gated OQ2) and keep the `/partners → /sales/customers` legacy redirects
  (`routes/index.tsx:3201-3203`, verify).
- The two partner EDIT routes gated on `contacts.update` (`routes/index.tsx:627` and `:876`) → 
  `partners.update`. Line `:2832` is the contact edit route — leave it. Verify the permission name
  exists in `RolesAndPermissionsSeeder` (grep `partners.update`).
- `/purchases/suppliers` list route currently has no permission gate (spec §7.3); add
  `RequirePermission permission="partners.view"` matching `/sales/customers`.
- Run `apps/web/tools/audit-tanstack-keys.mjs` and the i18n baseline check used by web lint to be sure
  the key deletions don't trip the removal-only ratchet in the wrong direction (read the ratchet's
  notes before deciding whether a baseline re-pin is needed — if yes, STOP: baseline re-pins are
  parent-owned at promotion; record it in `owes_parent`).
- Tests: routes test (or a new one beside the existing routes tests) asserting the partner edit
  routes render for `partners.update` and NOT for `contacts.update` alone; `/crm/companies` no longer
  resolves; suppliers list is gated.

**a6 — constrain the Type select per route.**
- `apps/web/src/features/partners/PartnerForm.tsx:488-505` (verify) offers customer/supplier/both on
  every create route; picking the wrong one makes the record vanish from the list you created it from
  (audit §5.5). On `/sales/customers/new` offer `customer`/`both`, on `/purchases/suppliers/new` offer
  `supplier`/`both`; the generic edit form keeps all three.
- Tests: Vitest on both routes.

**a9 — Otospex: vehicle owner picker must not offer suppliers** (spec §7.4 "one fix while we are here";
owner-confirmed 2026-08-29).
- `apps/web/src/features/vehicles/VehicleForm.tsx:106-110` fetches `/partners` UNFILTERED and renders
  every partner — suppliers included — in a raw `<Select>` at `:244-253`; the same relationship already
  uses the searchable `PartnerPicker partnerType="customer"` on ownership transfer
  (`features/vehicles/components/organisms/TransferOwnershipModal.tsx:113`) and on work-order create
  (`WorkOrderCreatePage.tsx:124`). Make vehicle create/edit use `PartnerPicker partnerType="customer"`
  too; drop the unfiltered fetch. Owner may be a person OR a company (fleets) — do NOT gate on
  `customer_category`. Vehicles tab on partner detail (`PartnerDetailPage.tsx:866`) unchanged.
- Tests: Vitest — VehicleForm renders the picker, submits `partner_id`, never requests `/partners`
  without `type=customer`.

**M3 browser gate** (`e2e/session-h/m3-*.spec.ts`, :5174): (v) on an Otospex-vertical tenant if one is
seeded locally (else skip with a named `test.skip` reason and say so in the register), `/vehicles/new`
owner picker search returns customers only — a demo supplier name yields no match; (i) `/crm/companies` → 404/redirect page, no
"Companies" entry in the sidebar; (ii) as `owner@` open `/sales/customers/<id>/edit` → renders; then
log in as a user holding `contacts.update` but NOT `partners.update` (create one via the API on :8011
with a role you build in the spec, or use `cashier@` and assert the 403/redirect) → blocked;
(iii) `/purchases/suppliers` blocked for that same user, open for owner; (iv) `/sales/customers/new`
Type select offers only Customer/Both; `/purchases/suppliers/new` only Supplier/Both.

**M3 addendum — Journey hardening (CLAUDE.md rule 22, added 2026-08-29 while this lane was in flight;
`docs/conventions/09-SECOND-OF-EVERYTHING.md`, `11-ONE-SURFACE-PER-CONCEPT.md`).** `partners` is a
catalogue entity (code-keyed, unique `(company_id, code)` —
`apps/api/database/migrations/tenant/2025_12_30_195300_fix_multi_company_unique_constraints.php:22-34`),
so both merge-gate reviewers now grade a missing second-of-everything test as MAJOR. Add, in this lane:
- **Second company:** a Vitest/PHPUnit (or the M2/M3 Playwright spec) case where company B of the same
  tenant creates a partner with the SAME `code` as company A's and a different nature — both persist,
  `/sales/customers` is company-scoped (B's row invisible under A), the Nature-required rule and the B2B
  NULL heuristic behave identically for B. Demo tenant has one company — create company B through the
  API in the spec (or use a PHPUnit feature test with two companies) and say which.
- **Second location:** state explicitly "not in scope: no location-keyed table touched by this lane"
  (verify `partners` has no location column; the vehicle picker change is FE-only).
- **Re-run / idempotency:** a7's Parties import re-run is covered by the existing
  `ImportReExecutionGuardTest` (cite file:line) — reference it; the nature/B2B heuristic must be a pure
  function of the row (re-render idempotent) — one assertion.
- **`Concepts:`** Party (glossary ✅ — row updated `df921cccf`), Contact (glossary ✅), Nature (label only in
  Phase 1 over `customer_category`; the glossary row lands with Phase 2's `party_kind`). No hand-rolled FE
  type beside a generated DTO — that is a3 (M1), cite it in the register.
- **Industry baseline:** Phase 1 is shape-neutral cleanup of surfaces already benchmarked in the spec
  (`docs/superpowers/specs/2026-08-23-party-contact-target-model-research.md` §2.1–2.5) — cite that section
  in the M3 register instead of re-tabulating; the full convention-10 table lives in the Phase 2 brief.

**M3 review lenses:** `tenancy-authz`, `frontend-conventions`.

**H1-cleanup done when:** M1–M3 ACCEPT, `pnpm typecheck && pnpm lint` clean in `apps/web`, touched
pos files pass `pnpm typecheck` in `apps/pos`, PHPStan/Pint clean on `ImportType.php` + any DTO you
touched, manifest checker green, YAML updated, branch unmerged.

---

## §3 — Lane H1-a1 (`.worktrees/h1-a1-buyer`) — `SALE_RECEIPT.buyer` populated from the attached customer

**Why:** today the device seals `buyer: null` on every SALE_RECEIPT
(`apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts:152`, `SaleReceiptV5Payload.ts:133`), so the
customer attached at the till is dropped from the sale (audit §5.1). The fix is a **VALUE** change on an
existing key, never a key change (spec §1.1, §8.2 Flow 1, §9-a1). The key-set drift gates cannot see a
`null → object` value change — hence the golden fixture (M5).

### M4 — device builder + server validator, TDD

Implement spec §8.2 Flow 1 **exactly**:
- `BuildSaleReceiptPayloadInput` gains ONE optional field `buyer?: BuyerBlockInput | null`
  (`apps/pos/src/lib/fiscal/FiscalEventEngine.ts` — `BuyerBlockInput` exists at `:287-297`, the payload
  field at `:397`; verify). The builder emits:
  - `buyer = null` when no customer is attached (byte-identical to today);
  - `buyer = { name, customer_id: <server uuid>, tax_number: <vat_number|null>, address: null,
    contact_id: null, codice_fiscale: null }` when the attached customer resolves to a server partner;
  - `buyer = { name, customer_id: null, tax_number: null, address: null, contact_id: null,
    codice_fiscale: null }` when attached but NOT yet resolved (offline-created, alias pending).
- **Resolution rule (the sharpest hole — §1.3):** `customer_id` carries a resolved SERVER partner id
  ONLY: a mirrored customer → `customers.id`; an offline-created one → `customer_aliases.server_partner_id`
  when present; otherwise `null`. Never a device-minted pending UUID (it would violate the
  `pos_receipts.partner_id` FK in `PosCoreReceiptProjection` and the receipt would fail to project).
  Find the alias table + repository (`apps/pos/src/lib/db/migrations.ts` `customer_aliases`,
  `pendingCustomerRepository.ts`) and reuse their resolution helper; do not add a second one.
- `contact_id` is **null by decision** — write that in a comment in the builder (no contact mirror on
  the device; Phase 5).
- Wire the call sites: `receiptService.ts` / `paymentStore.createReceiptLocalFirst` (grep
  `buildSaleReceiptPayload(` and `buyer: null` in `apps/pos/src/lib/fiscal/payloads/`) pass the
  selected customer through. `AccountChargePayload.ts:188` is NOT in scope (it carries its own
  `customer` block).
- Server: `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:2316`
  `validateBuyer()` — keep the key set and object-or-null check; **tighten `customer_id` to
  uuid-or-null — VERSION-GATED** (see the M4.0 ruling below). `name` required non-empty string when
  buyer is an object.

  **⚖️ M4.0 STOP — RULED by the parent orchestrator 2026-08-29 (architecture, not an owner gate; the
  lane resumes).** Codex correctly found that the golden canonical fixtures
  `apps/api/tests/Fixtures/Fiscal/sale-receipt-golden/v4/F-07-b2b-buyer-eur/payload.json` (`cust-007`,
  built by `tests/Helpers/Fiscal/GoldenFixtureBuilder.php:266`) and `…/v4/F-15-large/payload.json`
  (`customer-f15-001`, `LargeReceiptFixtureGenerator.php:211`) carry NON-UUID `buyer.customer_id`, and
  F-07 must validate at legacy `event_version=1`; the brief's "null on 100 % of existing sealed events"
  was about live events, not fixtures. Ruling:
  1. **Grandfather by `event_version`**, exactly like the validator's other version-gated rules
     (`:1269`, `:1320`): `customer_id` must be uuid-or-null **only when `event_version >= 5`** (the
     version `SaleReceiptV5Payload.ts` emits today — verify the constant); for `event_version <= 4`
     the existing string-or-null acceptance is unchanged. Golden fixtures F-07/F-15 are **NOT touched**
     (no fixture-byte or hash change — §5 condition 1 holds).
  2. **Projection is defensive regardless of version:** `PosCoreReceiptProjection` writes `partner_id`
     only when `buyer.customer_id` is a syntactically valid UUID AND resolves to a partner scoped to
     tenant+company; otherwise `partner_id = null` and the snapshot `customer_name` /
     `customer_identifier` still land. This is what keeps the `pos_receipts.partner_id` FK satisfiable
     for legacy/fixture payloads without rewriting anything.
  3. Tests: v5 payload with non-uuid `customer_id` → `payload_buyer_invalid`; v4 fixture F-07 still
     validates byte-identically (assert its pinned hash); projection of F-07 yields `partner_id = null`
     with the snapshot fields populated; v5 payload with a real scoped partner uuid → `partner_id` set.
  Set the YAML back to `status: in_progress`, move the blocker to `resolved_blockers` (parent has
  already done this — read the YAML), and proceed with M4.
- Projection: `PosCoreReceiptProjection` (grep `customer_name`, `partner_id`, `customer_identifier`)
  must write `partner_id` / `customer_name` / `customer_identifier` from the buyer snapshot when
  present. If it already does, prove it with a test; if it doesn't, add it — and scope the partner
  lookup to tenant + company like `DocumentAccountChargeFactureBridge` does.
- Tests (red first): pos unit tests for the three builder cases + the resolution rule (pending UUID →
  null); PHPUnit for `validateBuyer` (uuid ok, pending-style non-uuid string → `payload_buyer_invalid`,
  null ok); projection test with `app(CompanyContext::class)->clear()` before `apply()` (rule 20).

**M4 browser/API gate** (`e2e/session-h/m4-*.spec.ts`, :8011 via Playwright `request`): using the
existing POS receipt ingestion endpoint (find how `apps/web/e2e/pos/` or the money-campaign specs post a
sealed receipt — reuse their fixture builder), post (i) a SALE_RECEIPT with `buyer: null` → accepted,
projects with `partner_id = null`; (ii) one with a populated buyer whose `customer_id` is a real demo
partner uuid → accepted, and `GET` the projected receipt / the customer's history shows `partner_id`,
`customer_name`; (iii) one whose `customer_id` is a non-uuid pending-style string →
`payload_buyer_invalid`. If no e2e helper can seal a receipt from outside the device, say so and
substitute a PHPUnit feature test at the HTTP layer — do not skip the assertion.

**M4 review lenses:** `fiscal-pos`. The reviewer must confirm: no new key, null case byte-identical,
customer_id never a device UUID.

### M5 — golden populated-buyer canonical-bytes fixture, both sides

- Add a golden canonical-bytes fixture over a **populated** buyer beside the existing null-buyer one
  (device: `apps/pos/src/lib/fiscal/__tests__/` — find the canonical bytes / hash golden test;
  server: the matching PHPUnit canonical-bytes test under `apps/api/tests/Feature/Fiscal` or `Unit`).
  Same input on both sides must produce the **same bytes and hash**; pin the hash literal.
- Extend the key-set drift gate test to note (in a comment) that buyer is value-checked by this fixture.
- Update the fiscal fixture registry if one exists (grep `golden` under `apps/api/tests` and
  `apps/pos/src/lib/fiscal/__tests__`).

**M5 gate:** the golden fixture IS the evidence — the register must quote the pinned hash from the
device test and the server test and show they are identical; no browser spec required.

**M5 review lenses:** `fiscal-pos`.

**H1-a1 done when:** M4–M5 ACCEPT, `pnpm typecheck` + `pnpm vitest run src/lib/fiscal src/lib/customer`
green in `apps/pos`, PHPUnit by path green, PHPStan/Pint clean on touched PHP, manifest checker green,
YAML updated, branch unmerged. **A POS device version bump is NOT needed** (no schema change on the
device) — say so explicitly in the report if you agree; if you find it IS needed, STOP (owner gate).

---

## §4 — Reporting

Per milestone: register in `docs/handoff/reviews/session-h-phase1/<M-id>-r<n>.md` (the script writes it),
YAML updated. At the end of each lane, append a ≤25-line report to
`docs/sessions/session-H-party-model-2026-08-29/LANE-REPORT-<lane>.md`: what changed (files), tests run
with results, anything deferred to `owes_parent`, anything for Session G.

## §5 — STOP conditions specific to this wave

1. Any change that would alter a sealed payload **key set**, or a canonical-bytes **value** other than
   the deliberate `buyer` population in H1-a1.
2. Any need for a migration, a new enum value, a device schema version bump, or an i18n baseline re-pin.
3. Any finding that `PosCoreReceiptProjection` cannot satisfy the `pos_receipts.partner_id` FK from the
   buyer snapshot without a server-side alias lookup the device does not have — report the exact seam.
4. Fix rounds exhausted (4) on any milestone.
