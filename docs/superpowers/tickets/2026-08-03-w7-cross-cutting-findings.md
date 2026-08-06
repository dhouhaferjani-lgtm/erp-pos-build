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

> **Fix round 1 (review verdict APPROVE-WITH-FIXES, 2026-08-05) is folded into
> this document.** F-6 CONFIRMED + ESCALATED (root cause and two escalations
> added); F-5 / F-7 / F-2 / F-8 / F-2b / F-4 CONFIRMED with corrections;
> **F-3 SPLIT** — the backend half is confirmed, the "the FE sends the
> parameter" half is **REFUTED**; **F-9 re-graded P1 → P2** and re-framed as an
> amendment to an existing ruling. Every correction is marked inline.

| # | Sev | Surface | One-line summary |
|---|---|---|---|
| **F-6** | **P0** | documents / payments | A payment can be allocated to a **cancelled** invoice; the document is rewritten to `paid` while `cancelled_at` stays set |
| **F-5** | **P1** (plan wording: launch-blocking) | documents | No optimistic concurrency anywhere: a stale second save silently overwrites a draft money document |
| **F-7** | **P1** | web / documents | The document TOTALS panel renders `en-US` (`1,234.567`) under `fr`, beside correctly `fr-TN`-formatted lines — a 1 000x misread of the invoice total |
| **F-2** | **P1** | api / owner reports | `FormatsReportNumbers::decimalString()` casts money to `float`, formats at scale **2**, and `rtrim`s zeros → `300.000` is emitted as `"300"` |
| **F-3** | **P1** — **FIXED** (fix lane L3, 2026-08-05) | api **+ web** / cash report | **NEITHER LAYER** implemented location scoping: the server ignored `location_ids[]` and the hook never sent it (SPLIT, fix round 1) |
| **F-9** | **P2** *(re-graded from P1, fix round 1)* | api / settings | `PATCH /settings/company` answers 200 on accept-and-drop instead of 422, and `GET` omits the fields so the drop is undetectable — an AMENDMENT to the 2026-08-02 F9 ruling |
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

### Root cause (fix round 1, I1)

The allocation path never asks what STATE the document is in, except on one
branch:

- `PaymentController.php:399-403` validates `allocations.*.document_id` with a
  bare `ScopedExists::tenantAndCompany('documents', …)` — tenant + company, **no
  status predicate**.
- The status guard at `:496` (`$document->status !== DocumentStatus::Posted` →
  422 `SUPPLIER_INVOICE_NOT_POSTED`) is scoped **inside the SupplierInvoice
  branch** opened at `:489`. The AR branch (`:566-568`) is a bare `else` that
  only increments a counter — it checks nothing.
- Five status writes then flip the document to `paid` with no terminal-state
  check, each gated only on `canTransitionToPaid()`, which is a **pure type
  match** (`DocumentType.php:93-99`: Invoice / CreditNote / SupplierInvoice →
  true) and says nothing about status:
  `PaymentController.php:1003-1005`, `:1581-1582`, `:1679`, `:1745`, and
  `PaymentAllocationService.php:235-236`.
- `previewManualAllocation` (`:650-658`) has the same hole on the read side.
- The **auto**-allocation path DOES filter `Posted` (`:471`), which is why this
  only bites the explicit-`allocations[]` path.

**Surgical fix:** reject terminal statuses in the shared guard block
`:466-481` and mirror it at `:650-658`, rather than patching the five writers.

### Escalations (fix round 1, I1)

**(a) It composes with W-6 D2 into an AUTOMATIC exploit.** `:487` reads
`$balanceDue = $document->balance_due ?? $document->total;`. Per W-6's D2,
`documents.balance_due` is a PostgreSQL trigger cache that stays **NULL** until
an allocation row exists — so a never-allocated cancelled invoice falls back to
its **full total** and presents as *fully payable*. No crafted amount is needed:
the default path offers the whole balance of a document that was withdrawn.

**(b) The DB immutability trigger is blind here.** It returns early unless
`fiscal_status = 'SEALED'`; a cancelled document is `VOIDED`, so the database
does not stop the status rewrite either.

**(c) The GL-unreversed half is CONFIRMED.** `DocumentPostingService::cancel()`
(`:109-166`) makes **no GL call at all** — the only listener on
`InvoiceCancelled` is the audit-chain one, and `GeneralLedgerService` contains
**no sales-invoice reversal** of any kind. So cancelling a POSTED invoice leaves
its revenue and receivable legs standing in the ledger, independently of the
resurrection bug above.

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

**Scope of the exposure — CORRECTED (fix round 1, I2).** Not draft-only:
`DocumentStatus::isEditable()` (`DocumentStatus.php:19-25`) returns true for
**`Draft` AND `Confirmed`**, so a CONFIRMED invoice — one that has already had
its document-level taxes applied — is inside the window too. Three aggravating
details:

- **The overwrite is DESTRUCTIVE, not a merge.** `InvoiceController.php:429-431`
  deletes the existing lines and recreates them from the payload, so the loser's
  lines are gone rather than superseded.
- **The read is outside the write transaction and takes no row lock** — the
  document is fetched at `:387-389`, the transaction opens at `:424`, and there
  is no `lockForUpdate()` anywhere on the path.
- **There is no `If-Match`/`ETag` handling anywhere in the codebase**, so even a
  client that wanted to send a precondition has nothing to send.

The posted-immutability half of the original writeup **holds**: `Posted`,
`Paid`, `Received` and `Cancelled` are all non-editable, so no fiscal record is
at risk through this path.

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

**Trigger — CORRECTED (fix round 1, M1): this is CURRENCY-driven, not
language-driven.** `formatCurrency` picks its locale from `currencyMeta.ts` by
CURRENCY (TND → `fr-TN`), while `formatNumber` is pinned to `en-US` at
`lib/format.ts:118` regardless of anything. So the split render fires in
**every UI language** — English included — and equally for a **EUR** company
(`de-DE`/`fr-FR` conventions vs `en-US`). `?lang=fr` is merely how this wave
happened to observe it, not a precondition.

**Why it matters.** To a French or Tunisian reader `1,191.000` reads as one
million one hundred ninety-one thousand. The mis-rendered figures are the
Subtotal, the VAT, the stamp duty, the **Total** and the Balance Due — printed
directly beneath correctly formatted line amounts, on the document a customer is
invoiced from. Softened from the original writeup: the misread risk is real
**above 1 000** (where the grouping separator flips meaning) and effectively
**invisible below it** (where only the decimal mark differs).

**The unit tests lock the wrong render in.**
`DocumentTotals.test.tsx:123` asserts `'1,000.000 TND'` and `:137` asserts
`'1,191.000 TND'` — i.e. the en-US shape is currently the EXPECTED value. Any
fix must update those two assertions, which is also why this cannot regress
silently in the other direction.

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

**Blast radius — WIDENED (fix round 1, I3).** Every consumer of the trait:

- `SalesReportService::salesByLocation()` (`:63`), `topSkus()` (`:109`),
  category revenue (`:141-147`, which also does `(float)` arithmetic to compute
  a percentage) and `paymentMethodBreakdown()` (`:285-286`).
- **`CashRegisterReportService.php:61-63` — `expected_cash`, `counted_cash` and
  `variance` all go through the same float + scale-2 + rtrim path.** That is the
  cash-count reconciliation figure, on the launch-critical Z/EOD surface, and it
  is the most consequential instance of this defect by some distance.
- **Quantities are pushed through the CURRENCY-shaped formatter** — a rule-19
  violation in its own right, since quantities are scale 4:
  `StockAlertReportService.php:50-51` (`quantity`, `min_quantity`) and
  `SalesReportService.php:110` / `:154` (`quantity`). A `12.5000` quantity is
  emitted as `"12.5"`.

**Guard gap to record.** The PHPStan precision rules do not see any of this:
`ForbidHardcodedBcmathScale` allow-lists bcmath functions and `number_format`
is not one of them, and `ForbidFloatCastOnDecimalProperty` matches Eloquent
model properties while these are `stdClass` rows off `DB::table()`. The
structural guards are blind to the whole reporting layer.

**Not the same ticket as** `2026-08-01-positive-refund-total-consumers.md`,
which lists `SalesReportService:51` as SAFE — that ticket is about
`receipt_type`-blind SUMs, this one is about how the SUM is rendered.

---

## F-3 (P1) — `/reports/cash-movements` ignores the location scope — **FIXED (fix lane L3, 2026-08-05)**

**Pinned by:** `MTP-MLC-08`.

Three mutually exclusive single-location scopes — including a **warehouse that
has never seen a POS receipt** — return payloads identical to the unscoped read.

**SPLIT (fix round 1, C1) — the original writeup's second sentence was wrong.**

| Half | Verdict |
|---|---|
| The **server** accepts `location_ids[]` and ignores it | **CONFIRMED** |
| "The front end sends the parameter via `useViewScope`" | **REFUTED** |

`features/finance/hooks/useCashMovementsReport.ts:9-15` defines
`CashMovementsFilters` with `from` / `to` / `repository_id` / `direction` /
`page` and **no location field at all**; it keys the query with
`tenantScopedKey`, not `locationScopedKey`; and
`CashMovementsReportPage.tsx` never imports `useViewScope`. Contrast
`useAgedReceivables.ts:12-13`, which does all three correctly.

So **neither layer implements location scoping on this report**. The
`location_ids[]` parameter in the reproduction is one the TEST sends. A fix
therefore spans three places, not one: the backend query, the hook's filter
type, and the hook's query key (which must move to `locationScopedKey` or the
cache will serve one scope's rows under another).

Nothing leaks across tenants or companies: this is an unimplemented filter, not
an isolation hole. But the TopBar still offers a location scope while this page
is open, so a multi-shop owner is shown the **whole company's cash** under a
single-shop selection. Every sibling scoped report (`sales/by-location`, stock)
filters correctly, which is exactly what makes this one convincing.

### Fix (lane L3, branch `fix/l3-multibranch-cash`)

Both halves are implemented on the pattern the aged-* reports already use — no
third behaviour was invented:

- `GetCashMovementsRequest` declares `location_ids` / `location_ids.*` with the
  same shape-only `uuid` rules as `GetAgedReceivablesRequest:40-41`.
- `ReportsController::cashMovements` resolves the scope through the existing
  `reportLocationScope()` helper → `LocationScopeResolver`: an **unscoped** read
  is CLAMPED to the principal's grant, an **explicit** out-of-grant id is
  REFUSED with 403, and a grant covering every active location degrades to the
  unrestricted read (`LocationScopeBoundary::isUnrestricted` → `[]`) so
  NULL-location cash stays visible. The call sits deliberately outside the
  company-context `catch`, so the 403 is not swallowed into a 500 the way
  `agedReceivables`/`agedPayables`/`upcomingPayments` currently would.
- `CashMovementsReportService` filters the **payments** leg on its own
  `payments.location_id` and the **journal-lines** leg through the owning
  `payment_repositories.location_id` (active registers only). The
  de-duplication `whereNotExists` clauses are deliberately left
  scope-independent, so a payment excluded from the payments leg cannot
  reappear as its GL twin under another branch.
- The journal-lines leg **fails closed on an ambiguous cash GL account**
  (authz gate 2026-08-06, CRITICAL). `payment_repositories.gl_account_id` is
  many-to-one, and both provisioning paths — the `2026_03_02_400000` backfill
  and `PaymentRepositorySeeder` — point every cash_register/safe at the single
  company-wide `SystemAccountPurpose::Cash` account. Without the guard, one
  company-level cash line (a petty-cash `expense_settlement`, which no payment
  row backs) matched an in-scope register for **every** branch and was reported
  in full under all four of tenant #1's — a 4x overstatement that also broke
  the disjointness and Σ ≤ All invariants MTP-MLC-08 asserts. A journal line is
  now admitted under a strict scope only when every active cash register owning
  its GL account is inside that scope.
- `useCashMovementsReport` sends `location_ids` from `useViewScope` and keys
  with `locationScopedKey` (sending without re-keying would serve one branch's
  rows from another branch's cache entry).

Tripwire `MTP-MLC-08` flipped to the expected behaviour: scoped payloads are
subsets of the unscoped read, the three single-location scopes are pairwise
disjoint, `location_ids[]` is no longer accepted-and-dropped, and Σ over the
branch scopes does not exceed the All figure.

**Residuals — all six carried in one place:**
[`docs/superpowers/tickets/2026-08-06-l3-cash-scope-residuals.md`](2026-08-06-l3-cash-scope-residuals.md).
Headline: the `LocationScopeBoundary` deactivated-location clamp unsoundness (P1, the only one with
an authorization flavour), the aged-*/upcoming-payments 403→500 swallow, the dead
`journal_entries.location_id` column, the missing `unattributed` bucket on this payload, the
pagination-vs-scope-change gap, and the three pre-existing red web tests that need an owner.

The one contract consequence worth restating here, because it is what the flipped MTP-MLC-08
asserts: **company-level cash is invisible under a branch scope.** A pure advance
(`payments.location_id` NULL by design, `PaymentController.php:596-598`), a manual JE on a
location-less safe, and cash on a GL account shared across branches are all withheld from a strict
scope and shown only on the unrestricted read. That is the documented aged-* convention
(`reportLocationScope`'s docblock), and it is why the plan's "Σ across all locations == the All
figure" is asserted as `≤` rather than `==`. Launch consequence for tenant #1: the four branch
views only foot to the company view once every cash row is location-attributed.

---

## F-9 (P2 — re-graded, fix round 1) — `PATCH /settings/company` reports success and persists nothing

**Pinned by:** `MTP-PERM-14`.

`PATCH /api/v1/settings/company` with `discount_floor_mode` /
`default_max_discount_percent` / `default_minimum_margin` answers **200** and
writes **none** of them. `PUT /api/v1/companies/{id}` with the identical body
writes all three (verified against PG on `cafe-tunis`: `Advisory|100.00` →
PATCH → `Advisory|100.00` → PUT → `Block|25.00`).

`GET /settings/company` does not return the three fields at all, so the settings
surface can neither display nor change the discount policy — while reporting
that it did.

**Re-framed (fix round 1, I4): this is an AMENDMENT, not a new defect.** The
two-routes situation is already ruled on in
`docs/superpowers/tickets/2026-08-02-company-update-route-unauthorized.md:79-87`
(finding **F9** there). What W-7 adds is only:

1. the PATCH **200s on accept-and-drop** rather than answering 422, so the caller
   is actively told the write succeeded; and
2. the **GET omits the fields**, so the drop is undetectable from the API alone
   — which is why this wave had to prove it behaviourally (set Block + a 25% cap
   via PATCH, then watch a 40% discount still be accepted).

**Re-graded P1 → P2** because **no UI path sends these fields** (zero grep hits
across `apps/web/src`): only a direct API caller can hit it today. It is
**NOT** the same as that ticket's **m6** (the dead `/company` route) — different
route, different failure mode.

---

## F-8 (P1 / plan correction) — the document discount cap is the COMPANY cap, never the user's

**Pinned by:** `MTP-PERM-14`. **SPLIT (fix round 1, M8) — it does not supersede
`MTP-PERM-13`, it halves it:** the plan's DOCUMENT half is refuted (below), while
the plan's **RECEIPT half is exactly right and stays §Z** —
`DiscountPermissionResolver::effectiveMaxPercent()` (`:60-72`, POS) reads
`$user->max_discount_percent` precisely as the plan describes. So
`users.max_discount_percent` is a real, enforced cap; it simply governs the POS
receipt path, not the web document path.

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

1. `users.max_discount_percent` is the **POS receipt** cap
   (`DiscountPermissionResolver.php:66`), never the document cap; the plan
   should say so per surface, and `MTP-PERM-13`'s "Advisory-only" ruling from
   W-1 is true but incomplete — even under `Block`, an unset company cap means
   nothing to enforce.
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
`unallocated_amount: "0.000"` (correct scale-3).

**Corrected (fix round 1, M2):** the nested per-row field on THAT endpoint is
`allocations[].amount` (`PaymentController.php:1968-1973`), not
`payment_allocations[].allocated_amount` — the latter belongs to
`GET /documents/{id}/payments`, which is a different payload and renders
correctly.

**Root cause:** `Payment::getAllocatedAmount()` (`Payment.php:243-248`) is
`$this->allocations->sum('amount')` cast to string — a `Collection::sum`
numeric collapse that drops the scale — while its sibling
`getUnallocatedAmount()` (`:255-261`) uses `bcsub(...)` and keeps it. Note that
sibling also hardcodes `int $scale = 3` rather than resolving the currency
scale, so it is correct for TND by luck of the default.

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
  repository movement AND exactly ONE settlement leg on the cash account
  (both now ASSERTED, fix round 1 I6 — the JE half was previously only claimed).
  Both racers can answer 200; the money effect is single. Each racer is also
  asserted to have reached the write path (I5), so an authz regression cannot
  turn this P0 into a false pass.
- `MTP-CONC-04` — two simultaneous goods-receipt posts: stock moves once
  (`10.0000`), WAC blends once (`5.000000`), exactly one racer 2xx and the loser
  **422 `must be Draft before posting`** (message now ASSERTED, fix round 1 I6;
  write-path admissibility asserted per I5).
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
