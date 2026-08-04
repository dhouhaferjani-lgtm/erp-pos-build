# W-5c findings — expenses, income, partner deposits (`MTP-TRE-60..77`, `MTP-DEP-01..03`)

**Wave:** W-5c of the pre-launch full-E2E money campaign
(`.superpowers/sdd/2026-08-02-full-e2e-campaign-plan/wave-5c-brief.md`)
**Run date:** 2026-08-04, live local stack (web :5173 → api :8010, tenant `demo-pharmacy-tn`)
**Specs:** `apps/web/e2e/money-campaign/{expenses-lifecycle,expenses-validation,expenses-analytics,income-deposits}.spec.ts`
**Zero product-code changes.** Every finding below is pinned by a **GREEN tripwire** that asserts
*today's* behaviour, so the fix turns the tripwire red — that is the signal the fix landed.

**Headline: no money defect.** Every exact-decimal assertion in the wave held. `119.000` in, `119.000`
out; `settled + credited == amount` to the millime; the linked-cost reverse nets WAC, cash and GL to
exactly zero; two recurring generations both at `333.333`. The two findings are an **input-validation
/ fiscal-authoring ordering hole** (D1) and an **i18n leak** (D2). Neither miscounts money that
actually moved; D1 does let the customer-facing deposit *record* over-state what was received.

---

## D1 (HIGH) — `POST /partners/{id}/deposits` seals a fiscal receipt BEFORE validating its references → 500 + an orphan receipt that over-states the customer's deposit history

### What happens

`apps/api/app/Modules/Partner/Presentation/Requests/RecordDepositRequest.php:28-37` validates:

```php
'payment_method_code' => ['required', 'string', 'max:64'],
'repository_id'       => ['required', 'uuid'],
```

Neither carries an **existence check**. Every other treasury FormRequest in the codebase scopes its
FK inputs — e.g. `PayExpenseRequest.php:50-60` uses
`ScopedExists::tenantAndCompany('payment_repositories', $tenantId, $companyId)` for the identical
field. This one does not, so a `repository_id` belonging to another company (or a UUID that exists
nowhere) and an arbitrary 64-character `payment_method_code` both pass validation.

`RecordCustomerDepositService::record()`
(`apps/api/app/Modules/Partner/Application/Services/RecordCustomerDepositService.php:63-95`) then does,
**in this order**:

1. `db->transaction(...)` → `appendDepositReceipt(...)` — **authors and seals the `DEPOSIT_RECEIPT`
   fiscal event into the hash chain** — plus `seedProjections($event)`, committed together;
2. `runSeededProjectionsSync($event)` — **outside** that transaction, where the Treasury bridge finally
   tries to resolve the method code / repository and throws.

So the reference is validated *after* the chain entry is immutable.

### Observed live (2026-08-04)

| Request | HTTP | Body |
|---|---|---|
| `amount 10.000`, `payment_method_code: 'W5C-NOSUCHMETHOD-…'` | **500** | `Synchronous projection run failed for fiscal_event_id=f7d4c6e4-…; rows re-queued for async retry.` |
| `amount 10.000`, `repository_id: 00000000-0000-0000-0000-000000000000` | **500** | `Synchronous projection run failed for fiscal_event_id=3d026c9c-…; rows re-queued for async retry.` |

Then, on the same customer:

```
GET /partners/{id}/deposits   -> 3 receipts, Σ 30.000
GET /partners/{id}            -> credit_balance 10.000
```

**The deposit history says the customer paid 30.000; the customer's actual credit is 10.000.** The two
orphaned receipts moved no money (`CASH-01` unchanged), granted no credit, and can never succeed on
retry — their references do not exist. *(Correction, fix round 1 / review I-4: the retry rows do NOT
churn forever — `ProjectionInvariantViolationException` extends plain `RuntimeException`, not
`NonRetryableProjectionException`, so `ApplyFiscalEventProjectionJob` retries 5× with backoff
(~21 min) and then `failed()` flips the row to `DeadLettered`, which is terminal. The permanent
artifacts are: the sealed chain event, the `pos_deposit_receipts` row, and one dead-lettered
projection row per orphan.)*

### Why this matters before launch

- The failure is reachable with **plain client input**, no privilege needed beyond `payments.create`.
- The over-stated record is the **customer-facing** one: `GET /partners/{id}/deposits` is what the
  back-office reads when a customer disputes a payment.
- A `DEPOSIT_RECEIPT` is a **sealed fiscal event**. There is no delete route — by design. Every orphan
  is permanent.
- It is a **500**, so it is indistinguishable from an outage in monitoring, and the client gets no
  actionable message.
- **Reachable by routine ops, not just malformed clients** (review escalation, 2026-08-04): both
  `TreasuryDepositBridge` resolvers filter `is_active = true` while the FormRequest checks nothing —
  so **deactivating a payment method or repository while a back-office user has the deposit dialog
  open** produces a sealed orphan + 500. This materially raises the real-world likelihood.
- **Fiscal-gate status (orchestrator, 2026-08-04): PRE-ENABLE BLOCKER.** The orphan IS hash-chained
  (valid link, false payload). It does NOT corrupt chain structure or Z/EOD aggregates (verified:
  `DEPOSIT_RECEIPT` writes only `pos_deposit_receipts`, which Z/X/EOD never aggregate), so no
  re-seal is needed — but the sealed legal record permanently attests receipts that never occurred.
  Fix before enable; then a documented decision on receipts already orphaned locally/on staging.

### Suggested fix (NOT applied — this wave changes no product code)

1. Add the existence checks the rest of Treasury already uses:
   `ScopedExists::tenantAndCompany('payment_repositories', …)` on `repository_id`, and a
   tenant/company-scoped `exists` on `payment_methods.code` for `payment_method_code`. This alone turns
   both 500s into 422s **before** any fiscal event is authored.
2. Belt-and-braces: resolve both references inside `RecordCustomerDepositService::record()` *before*
   `appendDepositReceipt(...)`, so a future caller that bypasses the FormRequest still cannot seal a
   receipt it cannot project.
3. Decide separately what to do with the receipts already orphaned on staging/local (see §3).

### Tripwire *(rewritten fix round 1, review I-2/M-4 — supersedes the two-probe description)*

`income-deposits.spec.ts` → `MTP-DEP-03`, block "FINDING W5C-D1", **env-gated behind
`MONEY_CAMPAIGN_ALLOW_CROSS_TENANT=1`** (skipped-with-annotation otherwise, so it can never mint
orphans unattended on a chain that is E-7 evidence). Asserts, as GREEN: ONE probe (unknown
`payment_method_code`) → `500` + the `Synchronous projection run failed for fiscal_event_id=`
message; the sealed event id is extracted from that message and the orphan history row is matched
**by `fiscal_event_id` and its distinct `11.111` amount** (not by position); `CASH-01` unchanged;
**history grows by 1**; `21.111` of receipts on record vs `10.000` of real credit. When the fix
lands the status becomes 422 and the history stops growing — the tripwire goes red and must be
rewritten to the fixed behaviour. *(The former unknown-`repository_id` twin probe was dropped —
same ordering hole, second permanent orphan for no extra evidence; its live observation is
preserved in the table above.)*

> **Note for whoever fixes this:** the tripwire deliberately CREATES one orphan receipt per
> opted-in run against a throwaway `W5C-DEP03-*` customer. That is the cost of pinning the defect.
> Fix D1 and the cost disappears.

---

## D2 (LOW) — three document-lifecycle refusals return the raw translation KEY as the user-facing error

`__()` echoes its key when the key is missing. These three keys are absent from **both**
`apps/api/lang/en/messages.php` and `apps/api/lang/fr/messages.php`:

| Endpoint | Source | Response body (verbatim, live 2026-08-04) |
|---|---|---|
| `PATCH /expenses/{id}` on a posted expense | `ExpenseController.php:166` | `{"error": "messages.cannot_edit_posted_document"}` |
| `DELETE /expenses/{id}` on a posted expense | `ExpenseController.php:199` | `{"error": "messages.cannot_delete_posted_document"}` |
| `POST /expenses/{id}/post` on a posted expense | `ExpenseController.php:227` | `{"error": "messages.document_already_posted"}` |

`messages.expense_settled` and `messages.income_posted` ARE present (`lang/{en,fr}/messages.php:29-30`),
so this is a gap in the same file, not a missing translation layer. `messages.expense_posted` (the
success message on `POST /expenses/{id}/post`) is also absent and leaks the same way.

`IncomeController.php:140/170/198` uses the same three keys, so `/income/*` leaks identically
(`MTP-TRE-76` asserts the 422 on a re-post; the key string itself is pinned on the expense side only).

**Impact:** cosmetic but customer-visible — a French pharmacist who tries to edit a posted expense sees
`messages.cannot_edit_posted_document`. **The refusals themselves are correct**; the money is never
touched (asserted: the total stays exactly `55.000`).

**Fix:** add the four keys to `lang/en/messages.php` and `lang/fr/messages.php`.

**Tripwire:** `expenses-lifecycle.spec.ts` → `MTP-TRE-67`, three `TRIPWIRE D2` assertions pinning the
literal key strings.

---

## 3. Behaviour worth recording (not defects — pinned so a change is deliberate)

| # | Behaviour | Where pinned |
|---|---|---|
| B1 | `ExpenseService::create()` defaults `is_paid` to **TRUE** when the field is omitted (`ExpenseService.php:146`). An omitted `is_paid` silently produces a PAID expense whose `post()` credits Cash instead of booking the AP liability — a caller expecting "unpaid by default" gets the wrong GL shape and no 4xx. | `w5c-support.ts` `createExpense` docblock; every unpaid fixture passes `is_paid: false` explicitly |
| B2 | Posting an expense and settling it creates **no `Payment` entity** — the money leg is a `repository_movements` row with `source_type = 'expense'`. Any report that counts `payments` under-counts expense spend. (This is the plan's own §B.5 row 71 caveat, now asserted.) | `MTP-TRE-60/61/62/63`, final block |
| B3 | `settle()` never transitions `Document.status`; a settled expense stays `posted` forever and paid-ness lives on `expense_metadata.is_paid`. `DocumentStatus::Paid` exists but is never assigned on this path. | `MTP-TRE-60/61/62/63`; already noted by `idempotency.spec.ts` `MTP-IDEM-04` |
| B4 | An **outbound** instrument can only be drawn on a **bank-account** repository; a cheque against `CASH-01` is a 422 (`Outbound instruments can only clear through a bank-account repository.`). | `MTP-TRE-65` |
| B5 | Issuing a cheque for an expense leaves `is_paid = false` and moves **no** bank cash — a cheque is a promise, settled at clear. | `MTP-TRE-65` |
| B6 | `/ledger` renders money at **scale 4** (`'119.0000'`), unlike the scale-3 treasury/document layer. Any GL assertion must use the scale-4 rendering. | `w5c-support.ts` `scale4()` |
| B7 | `ExpenseAnalyticsService::byCategory()` rounds each `share_percent` **independently** to 2 dp with no largest-remainder redistribution, so `Σ share_percent != 100` in general. Live at authoring: 7 categories, Σ = `99.99`. The documented band is `|Σ − 100| <= 0.005 × n`. | `MTP-TRE-73` |
| B8 | `mom_delta_percent` is an explicit **`null`** when the comparison period totals zero — never `Infinity`/`NaN`. The frontend must render that null as "n/a". | `MTP-TRE-74` |
| B9 | A deposit `amount` whose 4th decimal is a **trailing zero** (`'10.0000'`) is accepted and truncated to `10.000`; only a *significant* 4th decimal is `INVALID_AMOUNT`. | `MTP-DEP-03` |
| B10 | `expenses:generate-recurring` is a **scheduled console command**; no API route materializes a due recurrence template. A client (or an e2e case) cannot trigger generation through the API. | `w5c-support.ts` `generateRecurringExpenses()` |
| B11 | Only `monthly` / `quarterly` / `yearly` recurrence frequencies exist (`RecurrenceFrequency`); there is no daily option, so `lead_days` (max 60) is the only way to materialize more than one period in a single sitting. | `MTP-TRE-75` |

**Explicitly checked and NOT a defect:** the brief flagged that "expense flows in queued/projection
contexts have NO CompanyContext — if you see currency-scale 500s, that's a product defect". **No
currency-scale 500 was observed anywhere in this wave.** Every expense/income path resolved its scale
correctly, including the linked-cost capitalization and its reversal.

**N4 (open treasury minor, `2026-08-02-treasury-fix-lane-minor-followups.md`):** no W-5c case touches
reverse-after-partial, so nothing was re-filed against it.

---

## 4. Wave footprint and the exclusion rule W-6 must apply

Measured at wave close, 2026-08-04. **Do not subtract these totals** — they are a snapshot and go
stale on the next run (this wave ran three times during authoring, and each run adds to them).
**Exclude by predicate.**

### 4.1 The predicates

| Object | Predicate |
|---|---|
| Expenses | `documents.type = 'expense'` joined to `expense_metadata.vendor_name LIKE 'W5C-%'` (the linked-cost **reversal** documents copy the vendor name, so one predicate catches both legs) |
| Income | `documents.type = 'income'` joined to `income_metadata.source_name LIKE 'W5C-%'` |
| Expense/income journal entries | `journal_entries.description LIKE '%W5C-%'` (the description is `"Expense: {number} - {vendor}"` / the income equivalent) |
| Linked-cost journal entries | `journal_entries.source_type IN ('linked_cost_capitalization','linked_cost_capitalization_reversal')` whose `description` names a `W5C-` expense's `document_number` — **net effect is exactly zero**, see 4.2 |
| Repository movements | `repository_movements.source_id IN (` the expense/income predicates above `)` |
| Partners | `partners.name LIKE 'W5C-%'` (customers for `DEP`, suppliers for `TRE-71` / `DEP-03`) |
| Products / purchase chain | `products.sku LIKE 'W5C-%'`; the `TRE-71` PO + supplier invoice hang off a `W5C-` supplier |
| Outbound instruments | `payment_instruments.reference LIKE 'W5C-%'` |
| Deposit receipts | `fiscal_events.event_type = 'DEPOSIT_RECEIPT'` AND `payload->'customer'->>'name' LIKE 'W5C-%'` |
| **Orphaned** deposit receipts (D1) | the deposit predicate AND (`payload->'payment'->>'method_code' NOT IN (SELECT code FROM payment_methods)` OR `payload->'payment'->>'repository_id' NOT IN (SELECT id::text FROM payment_repositories)`) |
| Payment repositories | **W-5c provisioned NONE.** The count of active repositories is still exactly **8** (`BANK-01/02/03`, `CASH-01/02`, `SAFE-01`, `VIRT-01`, `W2A-NOGL-01`), unchanged from the W-5b close |
| Recurrence templates | **zero remain** — `MTP-TRE-75` retires its template in a gated `finally` |

Drafts are **soft-deleted** (`documents.deleted_at IS NOT NULL`), so any W-6 query must filter
`deleted_at IS NULL`; 30 soft-deleted `W5C-` drafts exist and must not be counted.

### 4.2 Snapshot at wave close (for orientation only — use the predicates above)

| Object | Count | Amount |
|---|---|---|
| Posted generic expenses | 20 | `2 164.000` |
| Posted linked-cost expenses (5 originals + 5 reversals) | 10 | `500.000` (nets to zero) |
| Posted income | 5 | `1 600.000` |
| `W5C-` repository movements | 13 `out` / 10 `in` | `886.000` out, `1 850.000` in |
| `W5C-` deposit receipts | 20 | `1 720.000` |
| — of which **orphaned** (D1) | **8** | **`80.000`** |
| `W5C-` customers / suppliers / products / instruments | 11 / 9 / 4 / 3 | — |

GL by account, expense + income sources:

| Account | Name | Debit | Credit |
|---|---|---|---|
| `401` | Fournisseurs | `476.000` | `2 004.000` |
| `4456` | TVA déductible | `76.000` | `0.000` |
| `53` | Caisse | `1 600.000` | `636.000` |
| `65` | Autres charges de gestion courante | `2 088.000` | `0.000` |
| `706` | Prestations de services | `0.000` | `1 600.000` |

Linked-cost capitalization + reversal entries, **net exactly zero**:

| Account | Name | Debit | Credit |
|---|---|---|---|
| `37` | Stocks de marchandises | `250.000` | `250.000` |
| `53` | Caisse | `250.000` | `250.000` |

### 4.3 What will break in W-6 if the rule is not applied

A W-6 case that asserts an absolute balance on **`65`**, **`401`**, **`4456`**, **`53`** or **`706`**,
or that aggregates `CASH-01` / `BANK-01` without excluding `W5C-` sources, or that reads a customer's
deposit history without excluding orphaned receipts, will fail for reasons that have nothing to do
with the product. Note in particular:

- **`53` Caisse and `CASH-01` are the busiest shared objects in this wave** — `TRE-62/63/64/71/76` and
  all three `DEP` cases move them.
- **`706` Prestations de services** is touched only by `TRE-76`; it was untouched before this wave, so
  a P&L revenue assertion is newly exposed.
- The **8 orphaned deposit receipts** (`80.000`) are visible in `GET /partners/{id}/deposits` but in no
  balance, no GL account and no repository. Any W-6 reconciliation of "deposits recorded" against
  "cash received" must exclude them or it will be short by exactly `80.000` — and that gap grows by
  `20.000` every time `MTP-DEP-03` runs, until D1 is fixed.
