# Customer-Account Deposit / Payment (Phase 5) — Back-Office Channel

**Date:** 2026-06-08
**Branch:** `feat/customer-account-deposits`
**Status:** Design — approved (brainstorming), pending spec review
**Author:** Claude (Opus 4.8)

---

## 1. Summary

Add a **back-office / mobile-web channel** for recording a **payment toward a customer's
account**. This is the server-authored counterpart to the desktop POS `ACCOUNT_PAYMENT`
flow (which is device-authored). A store owner without the desktop terminal in front of
them — on the road, on a phone, in the admin dashboard — records that a customer handed
over money against their account.

The money behaves **exactly** like the desktop flow: it **settles open invoices/charges
oldest-first (FIFO)**, and any **remainder overflows to the customer's credit balance**
(prepaid advance). There is no new accounting behavior — only a new way to *initiate* the
existing behavior.

The fiscal event type used for this channel is the scaffolded **`DEPOSIT_RECEIPT`** (enum
case + `fiscal_events` CHECK constraint already exist).

## 2. Background & key decisions

### 2.1 Why not just "prepaid credit only"?

An earlier framing proposed routing every deposit straight to the `CustomerAdvance`
(prepaid credit) liability, gated to B2C customers. **Rejected**, because:

- Even **B2C customers can be given a credit line** (sell-on-account to an individual), so
  B2C customers *can* carry open receivables.
- Routing money into "advance" while a customer has open invoices creates the nonsensical
  *unapplied-credit-while-owing* state. Industry standard (HighRadius, Sage, Oracle JDE) is
  **settle-first**: apply to outstanding invoices, only the leftover becomes credit/advance.
- The desktop `ACCOUNT_PAYMENT` path already does settle-first-then-overflow correctly.
  Duplicating a *different* rule would be exactly the "two paths to the same scenario"
  hazard the owner flagged.

### 2.2 The anti-divergence guarantee — one accounting engine

Both channels feed the **same allocation engine**:
`Treasury\Application\Services\PaymentAllocationService::applyAllocationFromCommand()` with
`AllocationMethod::FIFO`. That service:

- allocates the payment across open invoices oldest-first, and
- posts any **excess** to `SystemAccountPurpose::CustomerAdvance` via
  `GeneralLedgerService::createCustomerAdvanceJournalEntry()`.

The shipped `TreasuryAccountPaymentBridge` already delegates to it. The new
`TreasuryDepositBridge` calls the **same service** with the **same command**. The money
math is therefore identical *by construction*; only the ingress envelope and the printable
receipt record differ per channel.

### 2.3 Server-authored, like `ACCOUNT_STATUS_CHANGED`

There is no physical device for a back-office action, so `DEPOSIT_RECEIPT` is
**server-authored** through the existing `VirtualAdminFiscalEventService` +
virtual-admin-terminal hash chain — the same mechanism that already authors
`ACCOUNT_STATUS_CHANGED`. `DEPOSIT_RECEIPT` is added to both
`FiscalEventType::isImplemented()` and `isServerOnly()` (device sync must reject it).

### 2.4 New ground: server-authored events that need projections

`ACCOUNT_STATUS_CHANGED` (the only prior server-authored event) runs **no** projectors.
`DEPOSIT_RECEIPT` is the first server-authored event that must drive the projection
pipeline (printable receipt + treasury bridge). `OutboxIngestor::dispatchProjections()` is
`private` and carries device-only suppression rules (canonical-parse-failure, z-session
lifecycle) that never apply to a server-authored, always-`Verified`/`Parsed` event.

**Decision:** add a small, public `FiscalEventProjectionDispatcher` in the Fiscal module
that, given a persisted `FiscalEvent`, inserts the `fiscal_event_projections` pending rows
for the active projectors and enqueues one `ApplyFiscalEventProjectionJob` per row on
`afterCommit`. The shipped device ingest path is left untouched. The job runner, registry,
priority order, and idempotency (`(fiscal_event_id, projector_name)` unique) are all reused
as-is.

## 3. Scope

### In scope
- `DEPOSIT_RECEIPT` payload contract + canonical reader + validator + registry.
- Server authoring (`appendDepositReceipt`) + `FiscalEventProjectionDispatcher`.
- Orchestrator service `RecordCustomerDepositService` (Partner module).
- `DepositReceiptProjection` (POS-core, printable record) + `pos_deposit_receipts` table.
- `TreasuryDepositBridge` (gated on Treasury) → Payment + shared FIFO allocation +
  `refreshPartnerBalance`.
- Admin API: `POST` (record) + `GET` (history) under `partners/{partner}/deposits`.
- Web admin UI on `PartnerDetailPage`: record-payment modal + history list + balances.

### Out of scope (contract notes)
- **Charge-side credit drawdown.** Spending the prepaid credit at sale time is *not* added
  here — `ACCOUNT_CHARGE` still posts full receivable. This feature only *funds*/settles via
  the account. Drawing down credit at charge time is a separate follow-up (note left for the
  charge-side team / A2 verifier).
- **Documents/orders model changes.** None. If unavoidable, leave a contract note (none
  anticipated).
- **Refund/void of a recorded deposit.** Follow-up; not in this PR.

## 4. Architecture

### 4.1 Flow

```
Admin Web (mobile)                       Server (apps/api)
─────────────────                        ─────────────────
PartnerDetailPage
  "Record payment" modal
  amount + method + repository + note
        │  POST /api/v1/partners/{id}/deposits
        ▼
                                  PartnerDepositController::store
                                        │ (can:payments.create)
                                        ▼
                                  RecordCustomerDepositService::record
                                        │  validate partner is a customer
                                        │  build canonical payload
                                        ▼
                                  VirtualAdminFiscalEventService::appendDepositReceipt
                                        │  virtual-admin terminal, hash chain
                                        │  → fiscal_events row (DEPOSIT_RECEIPT)
                                        ▼
                                  FiscalEventProjectionDispatcher::dispatch(event)
                                        │  pending rows + afterCommit jobs
                                        ▼
                        ┌───────────────┴────────────────┐
                        ▼                                 ▼
        DepositReceiptProjection (p50)        TreasuryDepositBridge (p150, gated)
        → pos_deposit_receipts                → Payment(origin=BackOffice)
          (printable record)                  → PaymentAllocationService FIFO
                                              → overflow → CustomerAdvance
                                              → refreshPartnerBalance
```

### 4.2 Canonical payload — `DEPOSIT_RECEIPT` (v1)

Keys (lexicographically sorted, mirrors the validator's key-set contract):

```
actor_name, actor_user_id, business_date, company_id, currency_code, currency_scale,
customer, deposit_receipt_uuid, event_time_device, notes, partner_id, payment, seller,
tenant_id, terminal_id, training_flag, treasury_allocation_policy
```

- `customer` (object): `customerId, name, phone?, email?, taxNumber?, customerCategory?`
- `payment` (object): `amount` (numeric-string), `methodCode`, `repositoryId?`
- `seller` (object): reuse existing `SellerDTO` shape.
- `treasury_allocation_policy`: fixed `"FIFO"` for this channel.
- `training_flag`: `false` (no training mode for back-office in this PR).

Money is always carried as **numeric-strings** at `currency_scale`
(`CurrencyScale::for(currencyCode)`); never float / `number_format`.

### 4.3 GL effect (delegated, not re-derived)

`TreasuryDepositBridge` creates a `Payment` then calls `PaymentAllocationService` FIFO:
- **Debit** the selected payment-method repository's GL account (cash/bank), **Credit**
  `CustomerReceivable` for the settled portion (via the allocation's payment-received
  posting), and **Credit** `CustomerAdvance` for any excess (overflow). This is the shipped
  allocation behavior — unchanged.

### 4.4 Idempotency

- Projection rows are unique on `(fiscal_event_id, projector_name)`.
- `DepositReceiptProjection` no-ops if a `pos_deposit_receipts` row already exists for the
  event (`fiscal_event_id` unique).
- `TreasuryDepositBridge` no-ops if a `Payment` with that `fiscal_event_id` exists.

## 5. Data model

### `pos_deposit_receipts` (new)
| column | type | notes |
|---|---|---|
| id | uuid pk | |
| tenant_id | uuid | FK tenants, restrict |
| company_id | uuid | FK companies, restrict |
| fiscal_event_id | uuid | **unique**, FK fiscal_events, restrict |
| deposit_receipt_uuid | varchar(255) | server-generated |
| customer_id | varchar(255) | partner id |
| customer_name | varchar(255) | snapshot |
| amount | varchar(32) | numeric-string |
| currency_code | varchar(3) | |
| payload_snapshot | jsonb | full canonical payload |
| created_at / updated_at | timestamptz | |

### `payments` (reuse)
- `origin` gets a new `PaymentOrigin::BackOffice = 'back_office'` case.
- `payment_type = PaymentType::DocumentPayment`, `status = Completed`,
  `fiscal_event_id` set, `created_by = actor`.

### `partners` (reuse)
- `credit_balance`, `receivable_balance`, `net_balance` already exist and are refreshed by
  `PartnerBalanceService::refreshPartnerBalance()`.

## 6. API

- `POST /api/v1/partners/{partner}/deposits` — body: `amount`, `payment_method_id`,
  `repository_id`, `currency` (optional, default company currency), `reference?`, `note?`.
  Returns the receipt + allocation summary (settled vs. credited) + refreshed balances.
- `GET /api/v1/partners/{partner}/deposits` — paginated history from
  `pos_deposit_receipts`.
- Middleware: `['api','auth:sanctum',SetPermissionsTeam,EnforceTokenTenantClaim]` +
  `can:payments.create`.
- UUID route params validated (`whereUuid`).

## 7. Web UI

On `PartnerDetailPage`:
- **Record payment** button → fixed-size modal (per UX rule: modals never resize):
  amount, payment method (select), treasury repository (select), reference, note.
- On success: toast + show allocation outcome (settled X across N invoices, credited Y) and
  refresh balance card + history.
- **Deposit history** list: date, amount, method, reference, actor.
- Current **credit balance** + **receivable balance** shown on the balance card (existing
  pattern extended).
- Design tokens only, all strings via `t()`, React Query for fetch/mutation +
  `tenantScopedKey`.

## 8. Testing strategy (TDD)

Backend (PHPUnit, sqlite `:memory:` + RefreshDatabase + RolesAndPermissionsSeeder):
- `DepositReceiptPayload` round-trip (fromArray/toArray, key-set).
- `FiscalPayloadConstraintValidator` accepts a valid `DEPOSIT_RECEIPT` payload and rejects
  bad currency/scale/amount/missing-keys.
- `appendDepositReceipt`: virtual-admin terminal created, sequence/hash chain correct,
  `signature_status=NotRequired`, `integrity_status=Verified`, payload matches.
- `CanonicalPayloadReader::forDepositReceipt` builds the view.
- `DepositReceiptProjection`: writes one `pos_deposit_receipts`; idempotent.
- `TreasuryDepositBridge`: FIFO settles open invoice; overflow → advance; idempotent;
  `refreshPartnerBalance` updates `receivable_balance`/`credit_balance`.
- `RecordCustomerDepositService`: rejects non-customer partner; authors event + dispatches
  projections.
- Full-flow feature test: `POST` → fiscal_events + receipt + payment + allocation +
  projections `applied` + balances.

Frontend (Vitest): record-payment modal renders + validates; history list renders;
mutation invalidates the right query keys (hooks may be mocked per project convention).

## 9. Risks / notes

- **Parallel session.** Work is isolated in worktree `apps/erp.deposits` on
  `feat/customer-account-deposits`. Disjoint from the verifier sessions (A2) and
  order-routing.
- **A2 coordination.** A2 leaves a placeholder for the deposit smoke flow; this PR adds the
  smoke section the tester runs.
- **`payments.create` permission reuse.** No new permission string to seed; recording a
  customer payment is a payment-create action. (If role coverage proves wrong during impl,
  add `partners.record_payment` and seed it.)
