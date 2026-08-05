# W-7 cross-cutting findings — PERM / MLC / I18N / EMPTY / CONC

**Wave:** W-7 of the pre-launch full-E2E money campaign
(`docs/qa/2026-08-02-full-e2e-campaign-plan.md`) ·
**Report:** `.superpowers/sdd/2026-08-02-full-e2e-campaign-plan/wave-7-report.md` ·
**Results:** `docs/sessions/MONEY-CAMPAIGN-RESULTS.md` § W-7 ·
**Date:** 2026-08-05 · **Stack:** local (web :5173, api :8010), tenants
`demo-pharmacy-tn` + `cafe-tunis`

**Zero product code was changed.** Every finding below is pinned by a GREEN
tripwire — the assertion records TODAY's behaviour and goes RED the moment it
changes, so a fix cannot land silently and a regression cannot hide.

| # | Sev | Surface | One-line summary |
|---|---|---|---|
| **F-6** | **P0** | documents / payments | A payment can be allocated to a **cancelled** invoice; the document is rewritten to `paid` while `cancelled_at` stays set |
| **F-5** | **P1** (plan wording: launch-blocking) | documents | No optimistic concurrency anywhere: a stale second save silently overwrites a draft money document |
| **F-7** | **P1** | web / documents | The document TOTALS panel renders `en-US` (`1,234.567`) under `fr`, beside correctly `fr-TN`-formatted lines — a 1 000x misread of the invoice total |
| **F-2** | **P1** | api / owner reports | `FormatsReportNumbers::decimalString()` casts money to `float`, formats at scale **2**, and `rtrim`s zeros → `300.000` is emitted as `"300"` |
| **F-3** | **P1** | api / cash report | `GET /reports/cash-movements` accepts `location_ids[]` and ignores it — every scope returns the whole company |
| **F-9** | **P1** | api / settings | `PATCH /settings/company` answers 200 and persists none of the pricing-policy triple; `GET /settings/company` does not expose it either |
| **F-8** | **P1** | api / pricing | The document discount cap never consults `users.max_discount_percent` — the money-test-plan's PERM-13/14 premise does not match the implementation |
| **F-1** | P2 | web / sales | The `/sales/*` UI is closed to the cashier by a ROLE-based alias while the API grants `invoices.create` |
| **F-2b** | P2 | api / payments | `allocated_amount` is emitted unscaled (`"500"`) next to a correct scale-3 `unallocated_amount` on the same payload |
| **F-4** | P2 (fixture) | seeders | `CoffeeShopSeeder` never records its users in the central identity index, so under db-per-tenant they cannot log in at all |

---

## F-6 (P0) — a payment allocated to a CANCELLED invoice resurrects it as `paid`

**Pinned by:** `MTP-CONC-06` (`apps/web/e2e/money-campaign/w7-concurrency.spec.ts`).

**Reproduction (live, API only — no race required, the concurrency case is just
the scenario that surfaces it):**

1. Post an invoice for `200.000`. Session B loads it (`status: posted`,
   `balance_due: 200.000`).
2. Session A cancels it: `POST /invoices/{id}/cancel` → **200**, `status:
   cancelled`, `cancelled_at` set.
3. Session B, still holding the stale page, records the payment:
   `POST /payments` with `allocations: [{document_id, amount: 200.000}]` →
   **201**.

**Observed after step 3:**

- `documents.status = 'paid'` with `cancelled_at` still populated
  (verified in PG on `demo-pharmacy-tn`).
- `GET /invoices/{id}` reports `status: paid`, `balance_due: 0.000` —
  indistinguishable from a legitimately settled invoice.
- `GET /documents/{id}/payments` carries a real `payment_allocations` row for
  the full `200.000`.

**Why it matters.** A cancelled sale reappears as revenue that was collected.
It is a *cancelled* document in the audit trail and a *paid* one in every report
that keys on `documents.status` — partner ledger, aged AR, dashboards. The
money is real (the repository movement happened); the document it is attached to
was withdrawn.

**Secondary observation recorded in the same run (not separately filed):**
cancelling a POSTED invoice wrote **no reversing journal entry** — only the
original `Invoice INV-…` entry exists for that document after the cancel. If
cancel-after-post is meant to be reachable at all, its GL reversal is missing;
if it is not meant to be reachable, the route should refuse a posted document.
The plan's `MTP-DOC-09` (W-1) recorded that no UI path offers `Cancelled`; the
API route does, and it succeeds.

**Expected:** the allocation is refused with a state error ("this document is
cancelled"), and `documents.status` is never rewritten out of a terminal state.

---

## F-5 (P1 — the money-test-plan calls this shape launch-blocking) — silent last-write-wins on a money document

**Pinned by:** `MTP-CONC-01`.

Two sessions load the same DRAFT invoice. A saves a line change
(`PATCH /invoices/{id}` → 200, total `101.000` → `201.000`). B then saves a
different change **computed from the state it loaded before A's save**
→ **200**, total `301.000`. A's line is gone; neither party is told.

**Root cause.** `documents` has **no version / lock / etag column** (53 columns,
none of them a concurrency token) and `UpdateDocumentRequest` neither accepts
nor requires one, so a second writer is undetectable by construction.

**Scope of the exposure.** DRAFT documents only — a posted invoice is immutable
(`MTP-DOC-08`), so no fiscal record is at risk. A draft invoice is still the
document a user is about to bill from.

**Expected (plan §I.5 `MTP-CONC-01`):** "Second save must not silently overwrite
with stale totals — expect a conflict/refetch. … a silent last-write-wins on a
money document is launch-blocking."

---

## F-7 (P1) — two decimal conventions in one document view

**Pinned by:** `MTP-I18N-09` (`w7-i18n-money.spec.ts`).

On `/sales/invoices/{id}?lang=fr` for a TND company:

| Region | Renders | Formatter |
|---|---|---|
| Header ("Montant dû"), line cells | `2 499,000 TND` (U+202F group, comma decimal) | currency-locale (`fr-TN`) — **correct** |
| Subtotal, VAT, stamp duty, **Total**, Balance Due | `2,499.000 TND` (comma group, dot decimal) | **`en-US`** |

**Root cause.** `apps/web/src/features/documents/components/DocumentTotals.tsx:56-57`
calls `formatNumber(amount, decimals)`, and `apps/web/src/lib/format.ts:115-121`
defaults that helper's third parameter to `locale = 'en-US'`. The UI language
and the currency locale are both ignored.

**Why it matters.** To a French or Tunisian reader `500.000` is five hundred
**thousand** dinars. The mis-rendered figures are the Subtotal, the VAT, the
stamp duty, the **Total** and the Balance Due — printed directly beneath
correctly formatted line amounts, on the document a customer is invoiced from.

Same family as W-6's **D6** (`formatCurrency` defaulting to `EUR`,
`lib/format.ts:77`), different helper. A fix should sweep both.

---

## F-2 (P1) — owner-dashboard money is float-cast, scale-2 and zero-trimmed

**Pinned by:** `MTP-MLC-01` (`w7-multilocation.spec.ts`).

`app/Modules/Accounting/Application/Services/Reports/FormatsReportNumbers.php:9-14`:

```php
private function decimalString(string|int|float|null $value, int $scale = 2): string
{
    $number = number_format((float) ($value ?? 0), $scale, '.', '');

    return rtrim(rtrim($number, '0'), '.') ?: '0';
}
```

Three defects in four lines, on the TND owner dashboard:

1. **`(float)` on money** — CLAUDE.md rule 19, "never let a float touch money".
2. **Hardcoded `scale = 2`** — TND is scale 3, so the millime is truncated away
   before the string is even built.
3. **`rtrim`** — `300.000` is emitted as `"300"` and `181.100` as `"181.1"`.

Live: `GET /reports/sales/by-location` returns `gross_sales: "300"` for
STORE-TUN1 where `pos_receipts` holds `300.000`.

**Blast radius** — every consumer of the trait:
`SalesReportService::salesByLocation()` (`:63`), `topSkus()`, category revenue
(`:141-147`, which also does `(float)` arithmetic to compute a percentage) and
`paymentMethodBreakdown()` (`:285-286`). That is the entire owner dashboard.

**Not the same ticket as** `2026-08-01-positive-refund-total-consumers.md`,
which lists `SalesReportService:51` as SAFE — that ticket is about
`receipt_type`-blind SUMs, this one is about how the SUM is rendered.

---

## F-3 (P1) — `/reports/cash-movements` ignores the location scope

**Pinned by:** `MTP-MLC-08`.

Three mutually exclusive single-location scopes — including a **warehouse that
has never seen a POS receipt** — return payloads byte-identical to the unscoped
read. The front end sends the parameter (`location_ids: effectiveLocationIds`,
via `useViewScope`); the endpoint accepts it and drops it.

Nothing leaks across tenants or companies: this is an unimplemented filter, not
an isolation hole. But a multi-shop owner reading `/finance/cash-movements`
under a single-shop scope is shown the **whole company's cash** and told it is
one shop's. Every sibling scoped report (`sales/by-location`, stock) filters
correctly, which is exactly what makes this one convincing.

---

## F-9 (P1) — `PATCH /settings/company` reports success and persists nothing

**Pinned by:** `MTP-PERM-14`.

`PATCH /api/v1/settings/company` with `discount_floor_mode` /
`default_max_discount_percent` / `default_minimum_margin` answers **200** and
writes **none** of them. `PUT /api/v1/companies/{id}` with the identical body
writes all three (verified against PG on `cafe-tunis`: `Advisory|100.00` →
PATCH → `Advisory|100.00` → PUT → `Block|25.00`).

`GET /settings/company` does not return the three fields at all, so the settings
surface can neither display nor change the discount policy — while reporting
that it did. A tenant that configures its discount policy through Settings gets
a green toast and no enforcement.

---

## F-8 (P1 / plan correction) — the document discount cap is the COMPANY cap, never the user's

**Pinned by:** `MTP-PERM-14`; supersedes the premise of `MTP-PERM-13`.

The money-test-plan defines both cases in terms of "a user whose
`max_discount_percent` is 10.00 / 25.00". On the document path that column is
never read:

- `app/Shared/DTOs/DiscountPolicySubject.php:102-115` —
  `effectiveMaxDiscountPercent()` resolves **product → category → company**.
- `app/Modules/Product/Application/Services/DiscountPolicySubjectProvider.php:76-78,148`
  — feeds exactly those three (`products.max_discount_percent`,
  `categories.max_discount_percent`, `companies.default_max_discount_percent`).
- `DiscountCapResolver.php:12-15` — falls back to `100.00` when all three are
  null, i.e. **no cap at all**.

Live on `cafe-tunis` with `barista` (`users.max_discount_percent = 25.00`):

| Company policy | 25.00% | 25.01% | 40.00% |
|---|---|---|---|
| `Advisory`, no cap | 201 | 201 | **201** |
| `Block`, no cap | 201 | 201 | **201** |
| **`Block`, cap `25.00`** | **201** | **422** | 422 |

So the inclusive boundary the plan asks for **does work** — once the cap is set
where the code looks for it. Two consequences:

1. `users.max_discount_percent` is a **POS-device** cap (Identity DTO/controller
   only); the plan should say so, and `MTP-PERM-13`'s "Advisory-only" ruling
   from W-1 is true but incomplete — even under `Block`, an unset company cap
   means nothing to enforce.
2. Any launch checklist that assumes per-cashier discount limits apply to
   web-authored documents is wrong today.

---

## F-1 (P2) — the sales UI is closed to a principal the API lets author invoices

**Pinned by:** `MTP-PERM-17`.

`cashier@pharmabio.tn` holds `invoices.create` server-side and really can
`POST /invoices` (201). The `/sales/*` routes are gated on
`moduleKey="sales"` → `MODULE_PERMISSIONS.sales = ['sales.view']`, and
`sales.view` is a **UI alias resolved by ROLE**
(`apps/web/src/hooks/uiAliasPermissions.ts:4-5` → `['admin','sales','manager']`).
The cashier is not in that list, so the whole sales module redirects to
`/dashboard`.

The FE is the *tighter* gate here, so this is not a security hole — it is the
mirror image of W-6's **D5** (where the FE was looser). Net effect: "the cashier
can raise an invoice" is true of the API and false of the product. Needs a
ruling, not a blind fix.

Recorded in the same case: the cashier is correctly refused
`PATCH /invoices/{id}`, `confirm`, `post` and `cancel` (all 403 — `confirm` is
gated on `invoices.update`, `post` on `invoices.post`), and the draft is left in
`draft`.

---

## F-2b (P2) — two money scales in one payment payload

**Pinned by:** `MTP-CONC-02`.

`GET /payments/{id}` returns `allocated_amount: "500"` (unscaled) beside
`unallocated_amount: "0.000"` (correct scale-3), and
`payment_allocations[].allocated_amount` is `"500.000"`. Same family as F-2.

---

## F-4 (P2, fixture) — `CoffeeShopSeeder` users cannot log in under db-per-tenant

**Discovered while discharging campaign blocker C-8** (`cafe-tunis` credentials).

`php artisan db:seed --class=CoffeeShopSeeder` provisions the tenant, its
physical database and its users correctly — but never calls
`IdentityIndexService::record()`. `ParapharmacySeeder` does, via
`recordIdentity()` (`:280-288`), and documents exactly why: the central identity
index is what email-first (T6) login resolves the tenant from. Without those
rows `POST /auth/login` answers *"No organizations found for that email"* for
`owner@cafe-tunis.tn` and `barista@cafe-tunis.tn`, and the tenant is
unreachable.

**Worked around for this wave** by recording the two identities after the seeder
run (data only, central index, `cafe-tunis` only — nothing on
`demo-pharmacy-tn`). **The fix belongs in the seeder**: `CoffeeShopSeeder`
should call `recordIdentity()` for every user it creates, exactly as its
parapharmacy sibling does. Until it does, C-8 will re-block on any fresh stack.

---

## Cross-referenced, NOT re-filed

- **W-6 D5** (`docs/superpowers/tickets/2026-08-05-w6-finance-gl-defects.md`) —
  `reports.view` / `ledger.view` are granted to `admin` only. Re-confirmed live
  on a W-7 surface: the **accountant is 403 on `GET /reports/cash-movements`**
  (tripwire in `MTP-MLC-08`). `GET /vat/periods` is on the same grant.
- **W-6 D6** — `formatCurrency`'s `EUR` default. F-7 is its sibling in
  `formatNumber`; `MTP-I18N-11` confirms the sales-document surfaces are clean
  of the EUR variant.
- **`MTP-PERM-15`** (W-5a) — the documented `payments.reverse`-without-
  `instruments.cancel` privilege widening. Untouched by this wave.

## Verified NOT defective (recorded so a successor does not re-open them)

- `MTP-CONC-02` — two simultaneous payments allocating the same balance: exactly
  ONE allocation persists, the invoice settles once, and the loser survives in
  full as unallocated on-account credit.
- `MTP-CONC-03` — two simultaneous `POST /expenses/{id}/pay`: exactly ONE
  repository movement and ONE settlement JE. (Both racers can answer 200; the
  money effect is single. Recorded, not filed.)
- `MTP-CONC-04` — two simultaneous goods-receipt posts: stock moves once
  (`10.0000`), WAC blends once (`5.000000`), the loser gets
  `must be Draft before posting`.
- `MTP-CONC-05` — two simultaneous opening-batch creations: exactly one is
  created, the loser gets `422 Company already has an unlocked … batch`. RULING:
  the rule is "one **unlocked** batch per type", not one batch per type.
- `MTP-MLC-04` — a shop-pinned cashier is auto-clamped to their grant on an
  unscoped read and **403** on another shop's location id.
- `MTP-MLC-05` — a persisted scope naming an un-granted location is clamped
  away, with no 403 screen.
- `MTP-MLC-07` — an empty scope selection disables the query (prompt, not a
  whole-company dump).
- `MTP-PERM-08` — the bank-statement reconcile gate really is 403 (not the old
  route-binding 404) for a cashier, on a real statement id.
- `MTP-EMPTY-09..12` — remittances, instruments, statement detail and VAT
  periods all render clean, scale-correct empty states.
