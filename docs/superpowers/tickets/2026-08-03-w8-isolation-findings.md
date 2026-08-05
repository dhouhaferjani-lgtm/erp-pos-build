# W-8 — cross-tenant / cross-company isolation findings (money campaign)

**Filed:** 2026-08-05 · **Wave:** W-8 (`MTP-ISO-01..07`, `MTP-GL-24/25`, `MTP-PUR-13`, `MTP-LOY-15`)
**Stack:** live local (api `:8010`, web `:5173`), db-per-tenant · **Tenants:** `demo-tenant-a`,
`demo-tenant-b` (provisioned by this wave, campaign debt **C-4**)
**Specs:** `apps/web/e2e/money-campaign/w8-isolation.spec.ts`, `…/w8-cross-boundary.spec.ts`
**Product code changed by this wave: NONE.** Every finding below is pinned by a **GREEN tripwire**
that asserts *today's* behaviour and turns **red** the moment the defect is fixed.

## Headline

**The cross-TENANT boundary holds.** 24 replay probes (reads, mutations, and the `X-Company-Id`
header vector, in both directions) all fail closed with a byte-identical money snapshot before and
after — `MTP-ISO-05`, the P0 security gate of the wave, is a clean PASS.

**The cross-COMPANY boundary does not.** Inside a single tenant, the Accounting module lets one
company read *and post* another company's general journal (**F-1**), and a second company cannot
author a sales document at all (**F-2**).

| # | Sev | Case | One line |
|---|---|---|---|
| **F-1** | **P0** | `MTP-ISO-06` | `JournalEntryController` `index`/`show`/`post` (and `AccountController::index`) are **tenant-scoped only** — company A reads and **posts** company B's journal entries |
| **F-2** | **P1** | `MTP-ISO-06`, `MTP-GL-25` | `documents_tenant_id_type_document_number_unique` is tenant-scoped while the numbering counters are per-company → the **second company in a tenant cannot create its first invoice** (500) |
| **F-3** | **P1** | `MTP-GL-25` | `GET /reports/trial-balance` is **currency-blind** and internally inconsistent: `debit` at 3 dp, `credit` at 4 dp, zero side `"0.00"` — identical output for a 2-dp EUR company and a 3-dp TND company |
| **F-4** | **P2** | `MTP-LOY-15` | `GET /loyalty/programs/{id}` throws `InvalidArgumentException` → **500**, not 404, for any unknown id |
| **F-5** | **P1** | C-4 provisioning | `POST /api/v1/companies` **creates the company and then answers 500** — `formatCompany()` casts two NOT-NULL-with-DB-default columns with `(string)` before a null-guarded formatter |
| **F-6** | **P2** (fixture) | C-4 | `TwoTenantIsolationDemoSeeder` provisions databases and a raw `companies` row **only** — no users, identities, roles, CoA, tax config or `fiscal_chain_seed`. C-4 is not dischargeable by running it |

---

## F-1 — P0 · cross-COMPANY authorization break in the Accounting module

### What

Within one tenant, a principal scoped to company **A** (`X-Company-Id: <A>`, and a
`user_company_memberships` row for A only) can:

1. **list** company B's journal entries, with lines, account codes, descriptions and exact money;
2. **read** any one of them in full by id;
3. **post** company B's *draft* journal entry — a state transition on another company's GL.

### Where (verified by code read + live probe)

`apps/api/app/Modules/Accounting/Presentation/Controllers/JournalEntryController.php`

| Method | Lines | The bug |
|---|---|---|
| `index()` | 32-43 | Line 34 resolves `$companyId = $this->companyContext->requireCompanyId()` and **never uses it**. The query is `JournalEntry::query()->where('tenant_id', $tenantId)…->paginate(20)`. |
| `show()` | 125-138 | Same shape: `->where('tenant_id', $tenantId)->findOrFail($id)`. `$companyId` resolved on :127, unused. |
| `post()` | 141-171 | Same shape (:149-152), then `$this->generalLedgerService->postEntry($entry, $user, $company->currency)` on :163 — settling **another company's** entry with the **caller's** currency. |

`apps/api/app/Modules/Accounting/Presentation/Controllers/AccountController.php:28-34` — same shape:
`$companyId` resolved on :30 and unused; the query is `Account::forTenant($tenantId)`. A
multi-company tenant therefore exposes every company's chart of accounts on `GET /accounts`.

`$companyId` is *assigned*, so no "unused variable" lint or PHPStan rule fires on it. That is why
this survived.

### Why it is not "just" a read leak

The **write path was already hardened and the read path was missed in the same file.**
`CreateJournalEntryRequest::rules()` uses
`ScopedExists::tenantAndCompany('accounts', $tenantId, $companyId)` on `lines.*.account_id`, carrying
the comment *"api.accounting.003: tenant+company-scoped exists upgrades the pre-existing tenant-only
pipe-form to also pin company_id, so a sibling-company account UUID cannot satisfy the FK
validator."* Somebody fixed the input validator for exactly this class of bug and did not sweep the
`index`/`show`/`post` queries beside it.

### Live evidence

Tenant `demo-tenant-a`, company 1 = `f4114e6d-…` (TND), company 2 = `019fcfc2-fde1-…` (EUR).

```
POST /api/v1/journal-entries          X-Company-Id: <company 2>   -> 201  id=019fcfd9-f43b-…
GET  /api/v1/journal-entries/019fcfd9-f43b-…
                                      X-Company-Id: <company 1>   -> 200  full entry, lines, 55.55
POST /api/v1/journal-entries/019fcfd9-f43b-…/post
                                      X-Company-Id: <company 1>   -> 200  status: "posted"
GET  /api/v1/journal-entries          X-Company-Id: <company 1>   -> 200  contains company 2's
                                                                          entry and "777.770"
GET  /api/v1/accounts?search=W8GL1    X-Company-Id: <company 1>   -> 200  company 2's account
```

DB confirmation (`tenantf6c592ac-…`): `journal_entries.company_id = 019fcfc2-fde1-…` for the entry
returned under `X-Company-Id: f4114e6d-…`.

Cross-**tenant** is correctly refused on the same route (`404`), so this is squarely a company-scope
defect, not a tenancy defect.

### Suggested fix

Add `->where('company_id', $companyId)` to all three `JournalEntryController` queries and switch
`AccountController::index()` to the tenant+company scope. Then sweep the rest of
`app/Modules/Accounting/Presentation/Controllers/` for the same `$companyId`-resolved-but-unused
shape. Consider a PHPStan rule: in a controller method that calls `requireCompanyId()`, the value
must reach a query constraint.

### Tripwires (go RED when fixed — that is intended)

`w8-isolation.spec.ts` `MTP-ISO-06`:
`TRIPWIRE F-1a` (index leaks), `F-1b` (show returns 200), `F-1c` (accounts leak), `F-1d` (cross-company
post returns 200 + `posted`).

---

## F-2 — P1 · a second company in a tenant cannot author a sales document

`documents` carries `documents_tenant_id_type_document_number_unique` on
`(tenant_id, type, document_number)`, but the numbering counters
(`companies.invoice_next_number`, `quote_next_number`, `sales_order_next_number`, …) are
**per company**. Company 2's first invoice is therefore numbered `INV-2026-0001`, which company 1
already owns, and the insert dies on the constraint:

```
POST /api/v1/invoices   X-Company-Id: <company 2>   -> 500
SQLSTATE[23505]: duplicate key value violates unique constraint
  "documents_tenant_id_type_document_number_unique"
DETAIL: Key (tenant_id, type, document_number)=(f6c592ac-…, invoice, INV-2026-0001) already exists.
```

Multi-company inside one tenant is therefore **unusable for sales documents today**, and it fails as
an unhandled 500 rather than a diagnosable error. Either the constraint must include `company_id` or
the number must carry a company discriminator — that is a data-model ruling, not a test fix.

Consequence for this campaign: `MTP-ISO-06` and `MTP-GL-25` had to source company 2's money from
posted **manual journal entries** instead of invoices. Recorded in both specs at the call site.

---

## F-3 — P1 · the trial balance is currency-blind (falsifies the plan's GL-25 premise)

`docs/qa/2026-08-01-money-test-plan.md` `MTP-GL-25` expects *"figures re-scale: EUR displays 2 dp,
TND displays 3 dp."* They do not. For a **EUR** (scale-2) company, `GET /reports/trial-balance`
returns, in one payload:

| Field | Value | Scale |
|---|---|---|
| `lines[].debit` (non-zero) | `777.770` | **3** |
| `lines[].credit` (non-zero) | `777.7700` | **4** |
| the zero side of either | `0.00` | 2 |
| `total_debit` / `total_credit` | `0.00`-shaped | 2 |

The TND company emits byte-identically. Three different scales in one response, none of them derived
from `companies.currency`. This is the same family as W-7 **F-2**
(`FormatsReportNumbers::decimalString()` float-cast) and W-6 **D6** (`formatCurrency` EUR default) but
is a distinct surface — it is the *report serializer's* scale, not the formatter's.

**Plan amendment needed** (orchestrator ruling): either GL-25's expected result is wrong and should
be restated, or this is launch-blocking for any multi-currency tenant. It is invisible on a TND-only
tenant, which is why nine waves did not see it.

Tripwire: `w8-cross-boundary.spec.ts` `MTP-GL-25`, `TRIPWIRE F-3` ×3.

---

## F-4 — P2 · `GET /loyalty/programs/{id}` answers 500 instead of 404

`apps/api/app/Modules/Loyalty/Application/Services/ProgramManagementService.php:130` throws a bare
`InvalidArgumentException("Program with ID … not found")` instead of aborting 404. A legitimate
cross-tenant refusal therefore surfaces as an unhandled server error.

Not a data leak (nothing of the other tenant is returned) and not an existence oracle (an id that
exists nowhere throws identically) — but a 500 is never an acceptable refusal shape, it will page
whoever owns error rates, and it makes the route indistinguishable from a real outage.

Tripwire: `w8-cross-boundary.spec.ts` `MTP-LOY-15`, `TRIPWIRE F-4`.

---

## F-5 — P1 · `POST /api/v1/companies` commits the company, then answers 500

```
POST /api/v1/companies {name, country_code, currency, locale, timezone}
  -> 500  CurrencyScale::bcformatStrict() expects a numeric string; "" given.
  -> and the company IS created (visible in GET /user/companies immediately after)
```

`apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php`

- `store()` returns `$this->formatCompany($company)` on **:169**, *outside* the `DB::transaction`
  that already committed on :115.
- `formatCompany()` **:589-592** does
  `CurrencyScale::bcformatOrNull((string) $company->default_target_margin, 2)`, and **:593-596** the
  same for `default_minimum_margin`.
- Both columns are `NOT NULL` with a **database default** (`30` / `10`) and are **not** passed by
  `Company::create()` (:75-112). PostgreSQL supplies the default, but the freshly created in-memory
  Eloquent model never hydrates it — the attribute is `null` in PHP.
- `(string) null` is `""`, which defeats `bcformatOrNull`'s null guard, and `bcformatStrict` throws.

The very next field, `default_max_discount_percent` (**:597-600**), is written correctly
(`… !== null ? (string) … : null`), which shows the hazard of the `(string)` cast was already known
one line below.

**Every company created through the documented API is created-and-then-500.** The caller sees a
server error, retries, and creates a duplicate. Fix: guard the two casts the way the third one is
guarded (or `$company->refresh()` before formatting).

Not tripwired in a spec: W-8 owns no `MTP-CFG` case for `POST /companies`. Recorded here with the
exact repro. Campaign-plan surface **B.8 row 111** (`MTP-CFG-13..16`) is where a case belongs.

---

## F-6 — P2 (fixture) · `TwoTenantIsolationDemoSeeder` cannot discharge C-4 on its own

`apps/api/database/seeders/TwoTenantIsolationDemoSeeder.php` creates the two tenants, their physical
databases, and **one raw `DB::table('companies')->insert()` each** (:92-111). It creates:

- **no users** → nothing can log in;
- **no `central_identities`** → even a hand-made user could not log in, because
  `AuthController::login()` resolves the tenant from `CentralIdentity` by email (:195). This is the
  same gap W-7 recorded as **F-4** for `CoffeeShopSeeder` — the seeders systematically skip
  `IdentityIndexService::record()`;
- **no roles/permissions** → every money route would 403, making an isolation verdict vacuous;
- **no chart of accounts, tax configuration, payment methods, payment repositories or fiscal years**;
- **no `fiscal_chain_seed`** — the raw insert bypasses whatever populates it, so
  `DocumentPostingService::getCompanyGenesisSeed()` (`:510`) aborts every `POST /invoices/{id}/post`
  with *"Company is missing fiscal_chain_seed. Run migration to generate seeds."*

The C-4 debt item in `docs/qa/2026-08-02-full-e2e-campaign-plan.md` says *"already exists. Provision
credentials for both"* — that is a **substantial understatement**. See the wave-8 report §"C-4
provisioning record" for exactly what had to be added. The durable fix is a `--full` mode on the
seeder (or a `DemoIsolationTenantSeeder` extending `ParapharmacySeeder`'s foundation the way
`DemoPharmacySeeder` does), so the next agent does not repeat this.

---

## Explicitly NOT findings (recorded so nobody re-files them)

| Observation | Why it is not a defect |
|---|---|
| 404 bodies quote the requested id and the internal model FQCN (`No query results for model [App\Modules\POS\Domain\Receipt] …`) | `APP_DEBUG=true` in `apps/api/.env` on this local stack. A debug-mode artifact, not product behaviour. The specs exclude probe-supplied ids from leak scanning for exactly this reason (`findLeaks(raw, forbidden, echoed)`). |
| `GET /reports/aged-receivables` emits scale **4** (`376.0000`) on a scale-3 TND company | W-6's surface (`finance-aged.spec.ts`, `docs/superpowers/tickets/2026-08-05-w6-finance-gl-defects.md`). **Cross-referenced, not re-filed** — but pinned in `MTP-GL-24` so it cannot change silently. |
| `POST /supplier-invoices` rejects a foreign `partner_id` / `source_document_ids.*` / `product_id` with 422 rather than 404 | Correct: these are `ScopedExists::tenantAndCompany` **input** validations, not route-model bindings. `MTP-PUR-13` proves the boundary with a passing same-company control. |
