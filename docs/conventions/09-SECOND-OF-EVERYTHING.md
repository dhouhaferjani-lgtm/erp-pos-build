# 9. Second-of-Everything — every catalogue change proves itself on a second company, a second location, and a second run

> **The convention, in one sentence:**
> **Any lane that touches a catalogue entity — anything keyed by a code, SKU, number, or name that an operator
> edits — ships with a test on a SECOND company, a SECOND location, and a RE-RUN (idempotency), and any new
> `unique(['tenant_id', …])` on such a table fails the architecture ratchet unless it carries a waiver.**

Adopted 2026-08-29 (Session I) after the onboarding-bug class found by manual testing. Owner-mandated.

---

## The incident class (all real, all found by the team in two days, 2026-08-27/29)

| Bug | Root shape |
|---|---|
| Product import lands stock in the wrong company's location after a company switch (F-BUG-1) | test ran with one company |
| SKU unique per **tenant**: company B cannot create the SKU company A has (G-3a) | `unique(['tenant_id','sku'])` on `products` |
| Units seeded per tenant, not visible/usable per company; `units 0` on day one (N-9, G-12) | single-company seeding assumption |
| Live tenant-wide uniques on operator-edited tables today: `products(tenant_id,sku)`, `product_variants(tenant_id,sku|barcode)`, `partners(tenant_id,vat_number)`, `units(tenant_id,code)`, `unit_categories(tenant_id,code)`, `product_attributes(tenant_id,code)`, `brands(tenant_id,slug)`, `brands(tenant_id,canonical_brand_id)`, `vehicles(tenant_id,vin|license_plate)`, `loyalty_members(tenant_id,phone)`, `documents(tenant_id,type,document_number)` — payment methods/repositories/accounts/partners-code were already fixed in `2025_12_30_195300`; **G-3a (merged to dev 2026-08-29, `68c698f1a`) removed `products` sku, `product_variants` sku and `partners` vat_number from this set** | same pattern |
| Import re-run doubled opening stock until `OpeningAlreadyExistsException` was swallowed | no re-run test |

Every one of these passed a depth-first adversarial gate. The tests were green because **every test provisioned
exactly one tenant, one company, one location, and ran the flow once**. The second company was never in the
fixture, so nothing could observe that the key was too wide or the location too sticky.

---

## The rule

A lane is **in scope** when its diff touches, on any layer (migration, model, service, FormRequest, importer,
seeder, FE form/list), one of the *catalogue entities*: products (SKU/barcode), partners (code/VAT number),
units and unit categories, payment methods, payment repositories (drawers/safes/banks), accounts (chart codes),
tax configurations, categories, brands, product attributes, locations, POS terminals, vehicles, loyalty members
— or any new table an operator edits per company. The canonical list is the `CATALOGUE_TABLES` constant in
`apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php` (lands with lane I-2); keep the two in sync.

An in-scope lane MUST carry, in the same lane, three tests (or three assertions in one journey):

1. **Second company.** Provision a second company in the same tenant (through the real company-creation path,
   not a raw insert) and assert the entity behaves independently: the same code/SKU/name can exist in both; a
   list/lookup in company B never returns company A's row; the seeded day-one set (units, methods, drawers,
   accounts) exists for B too.
2. **Second location.** Create a second `pos_enabled` location and assert location-bound behaviour lands on the
   *selected* one (stock, drawer attribution, terminal binding) — never the default/first one.
3. **Re-run / idempotency.** Execute the same mutation twice (re-import the same file, re-post the same
   opening batch, re-seed) and assert zero duplicate rows, zero doubled balances, and an explicit
   `skipped`/`already_exists` outcome rather than silence.

Assertions are on **data meaning** (the row company B sees, the balance after the second run), not on HTTP
status or "no exception thrown".

### The architecture ratchet (the mechanical half)

`TenantOnlyUniqueOnCatalogueTablesRatchetTest` (lands with lane I-2; PG lane only) introspects the **live
PostgreSQL schema** of the migrated test database — `pg_index` / `pg_get_indexdef()`, partial and raw-SQL indexes
included — for unique keys whose leading column is `tenant_id` and which lack `company_id`, on a catalogue table.
(Migration text is not scanned: `down()` bodies re-add dropped keys, superseded `create_*` lines never go stale,
raw-SQL indexes are invisible.) Existing instances live in
`tests/Architecture/baselines/tenant-only-unique-baseline.json` (shrink-only). A new one fails CI with:

> new tenant-only unique on catalogue table `X` (index `Y`) — add `company_id` to the key, or add the entry to
> the baseline with a `waiver` reason

A waiver is legitimate when the value is tenant-global by nature (a user email, a signing-key id, an
idempotency key). Write the reason in the baseline entry; the reviewer reads it in the diff.

When a lane *fixes* a baselined instance (G-3a SKU), the live schema no longer carries the index, the entry goes
stale, and the ratchet fails until the merge deletes it — so it cannot be forgotten.

---

## Reviewer question (added to every `.claude/agents/*-reviewer.md`)

> **Second-of-everything:** does the diff touch a catalogue entity? If yes, point at the second-company,
> second-location and re-run tests in this lane (file:line). Missing any one → MAJOR. A new
> `unique(['tenant_id', …])` without `company_id` or a waiver → BLOCKER.

## Spec/brief line (added to the lane-brief and spec conventions)

Every brief for an in-scope lane carries a "Second-of-everything" section naming the three tests it will add,
before the gate is dispatched (round-0 precheck, `docs/superpowers/SPEC-GATE-ROUND0-MECHANICAL-PRECHECK.md`
check 6).

---

## Checklist for the implementer

- [ ] Does my diff touch a `CATALOGUE_TABLES` entity on any layer?
- [ ] Second-company test exists and asserts company B's view, not just "no crash".
- [ ] Second-location test exists for anything location-bound.
- [ ] Re-run test exists and asserts an explicit skipped/exists outcome.
- [ ] No new `unique(['tenant_id', …])` on a catalogue table — or it has `company_id`, or a baseline entry with a written `waiver`.
- [ ] If I removed a tenant-only unique, I deleted its baseline entry.

Related: [08-DETECTOR-LIVENESS.md](./08-DETECTOR-LIVENESS.md) (the ratchet ships with a liveness fixture),
[11-ONE-SURFACE-PER-CONCEPT.md](./11-ONE-SURFACE-PER-CONCEPT.md), `docs/qa/ONBOARDING-CAMPAIGN.md` (the
journey that exercises all three on every promotion).
