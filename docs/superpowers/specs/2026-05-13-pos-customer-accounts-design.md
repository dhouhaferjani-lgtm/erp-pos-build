# POS Customer Accounts — Design Spec

**Date:** 2026-05-13
**Status:** Approved through brainstorming; ready for implementation plan.
**Spec lifecycle:** brainstorm → **spec (you are here)** → implementation plan → execution → review

---

## 1. Overview

### 1.1 Problem statement

The web admin already supports B2B customer accounts: open invoices, balance, smart payment allocation (FIFO / Due-Date / Manual), overpayment routing to `CustomerAdvance` GL account. **The POS desktop application (Tauri) has zero scaffolding for customer-account flows.** Cashiers cannot:

- Search for a customer by phone / email / loyalty card / account number / name.
- Create or edit a customer at the till.
- Charge a sale to a customer's account (partial or full).
- Receive an on-account payment from a customer (no goods sold, just paying down an open balance).
- See a customer's running balance or open invoices.

The canonical use case is a Tunisian para-pharmacy with a mix of cash walk-ins and regulars on house accounts. The same model will serve French automotive workshops (Otospex) and generic retail (IziPOS), and ultimately covers both B2C and B2B customers through the existing unified `Partner` model.

### 1.2 Scope (in)

This spec covers, as a single cohesive design with a phased implementation plan:

1. **Customer management in POS** — search, create, edit, mirror sync. Foundation for everything else.
2. **Receive on-account payment** — pay-only screen, smart allocation, fiscal `Encaissement` document, hash-chained.
3. **Charge-to-account at checkout** — sale settled fully or partially by debit to balance, with min-deposit + credit-limit rules.
4. **Account policy and rules engine** — per-customer min-deposit %, credit limit, payment terms; tenant defaults; cashier permissions; approval primitive.
5. **Compliance posture** — Tunisia day 1, France next; NF525-architecture-compatible without certifying day 1; hash chains for fiscal integrity.
6. **Offline-first behavior** — POS works fully offline for everything in this spec.
7. **Unified payments architecture** — POS-emitted and web-admin-emitted payments flow through the same domain service, surface in the same admin listing.
8. **Forward-additive sync layer** — typed envelope transport supports future marketplace orders, delivery integrations, mobile app without rework.

### 1.3 Scope (out, acknowledged)

- **Deposits feature** (acompte / arrhes — payments against a specific future sale): out of this spec. Schema seam reserved (see §4.2).
- **NF525 certification submission**: out. Architecture is cert-ready; submission is a separate effort with Opus deep-research + Codex adversarial review + manual source verification.
- **V3 hash payload** (richest international payload — France/Italy/Saudi Arabia/etc.): out. We extend V2 for the new document types in this spec; V3 is a separate spec.
- **Marketplace inbound orders, delivery integration, mobile owner app**: out. Transport is designed to accept them without rework; the features themselves are separate specs.
- **Body-shop fully-offline workshop module** (multi-day offline operation with workshop projections): out. Sync architecture supports it; workshop-specific work is a separate spec.
- **Layaway-style minimum-deposit fixed-amount floor**: out. Placeholder column reserved (see §4.3).
- **Auto-freeze cron logic**: out of MVP execution; placeholder + manual freeze in scope.

### 1.4 Phasing (informs implementation plan, not this spec)

Phase 1 — Customer management foundation (search, create, edit, mirror).
Phase 2 — Receive on-account payment (Encaissement document, allocation, fiscal chain).
Phase 3 — Charge-to-account at checkout (rules engine, override flow, audit).
Phase 4 — Account status workflow (Active / Frozen / Closed / PendingKyc), web admin retrofit, unified payments listing.

---

## 2. Compliance posture

### 2.1 Jurisdictions

| Jurisdiction | Status | AML cash cap |
|---|---|---|
| **Tunisia** | Launch day 1 | 5 000 TND fiscal-penalty threshold |
| **France** | Launch next (car body shops; not NF525-cert day 1, but cert-ready) | €1 000 hard legal cap |
| **Algeria** | Future | 500 000 DZD non-cash settlement |
| **Morocco** | Future | 20 000 MAD |
| **UK** | Future | €10 000 HVD trigger |
| **Italy** | Future | €5 000 |
| **EU AMLR** | Effective 2027-07 | €10 000 |

AML cap is configurable per-tenant. Defaults follow the launch country (Tunisia default for the day-1 deployment).

### 2.2 Document type taxonomy

Six fiscal document types. Three exist; three are new.

| Type | Status | Description | Partner | AR effect |
|---|---|---|---|---|
| `Ticket` | exists | Anonymous cash sale receipt | none | none |
| `TicketIdentified` | **new** | Cash sale, customer named (no full facture mentions) | attached | none |
| `Facture` | exists | Full B2B/B2C invoice (TVA, SIRET/MF/ICE) | required | conditional |
| `OnAccountCharge` | **new** | Sale debited fully/partially to customer balance | required, Active | debit |
| `Encaissement` | **new** | Pure on-account payment received, no goods sold | required, Active | credit |
| `Avoir` | exists | Credit note / refund | depends on original | credit |
| `FactureAcompte` | reserved (future) | Acompte against a specific future sale | required + sales_order_id | conditional |

The `Document` table extends with the new enum values; `payment_document_type ENUM('encaissement','acompte')` discriminator is added now with a CHECK constraint that only `'encaissement'` is permitted until the Deposits feature ships.

### 2.3 Acompte vs Encaissement — legal seam

Per **Art. 269 CGI** (France) and BOFIP, an **acompte** is a payment against a specific identified future contract, triggers VAT exigibility on collection, and requires emission of a `FactureAcompte`. An **encaissement** (on-account payment) is settlement of existing receivables, has no VAT effect (VAT was already declared when the underlying invoices were issued), and requires only a commercial receipt.

The seam in the data model:

| Field | Encaissement | Acompte (future) |
|---|---|---|
| `linked_to` | `partner_id` only | `partner_id` + (`sales_order_id` OR `quote_id`) |
| `vat_line` | NULL | NOT NULL (rate, base, amount) |
| `gl_credit_account` | `411` | `4191` + `44571` |
| `creates_tax_document` | No | Yes (`FactureAcompte`) |
| `sequence_prefix` | `E-` | `FA-` (reserved) |

CHECK constraints enforce this on insertion. The future Deposits feature lands with an enum flip + new rows; no breaking migration.

### 2.4 Fiscal hierarchy and sequence numbering

The product hierarchy is `Tenant → Company → Location → Terminal`. A **Company** is the legal fiscal entity (matricule fiscal in Tunisia, SIREN in France). Sequence scoping per document type:

| Type | Counter scope | Stored format | Example |
|---|---|---|---|
| `Ticket` / `TicketIdentified` | (company, location, terminal, year) | `<co>/<loc>/<term>/<YYYY>/<NNNNNN>` | `OTO/AVB/POS01/2026/000123` |
| `Facture` | (company, year) | `<co>/F/<YYYY>/<NNNNNN>` | `OTO/F/2026/000045` |
| `Encaissement` | (company, year) | `<co>/E/<YYYY>/<NNNNNN>` | `OTO/E/2026/000045` |
| `OnAccountCharge` | (company, location, terminal, year) | `<co>/<loc>/<term>/OAC/<YYYY>/<NNNNNN>` | `OTO/AVB/POS01/OAC/2026/000010` |
| `Avoir` | (company, year) | `<co>/A/<YYYY>/<NNNNNN>` | — |
| `Z-closure` | (company, location, terminal, year) | `<co>/<loc>/<term>/Z/<YYYY>/<NNNN>` | — |
| `FactureAcompte` (future) | (company, year) | `<co>/FA/<YYYY>/<NNNNNN>` | reserved |

**Print format on receipts (two lines)** for readability:

```
Otospex SAS · Av. Bourguiba · POS01
Ticket #2026/000123
```

Per-tenant uniqueness is sufficient (later: per-tenant separate databases). Within a tenant, per-company gives independent fiscal series per legal entity.

### 2.5 AML mid-transaction promote-to-identified

A walk-in cash sale may cross the local AML cap mid-tender. The POS must support attaching a Partner (existing or newly-created inline) to an in-flight anonymous sale before close-out. One fiscal hash event at close, no void-and-rekey. Same UI primitive serves two triggers: customer-requested ("can I have a facture in my name?") and legally-forced (cash exceeds AML cap).

---

## 3. Domain model

### 3.1 Partner extensions

`Partner` already exists with `CustomerCategory::Individual | Business`. Add:

```
partner.account_policy {
  account_status        ENUM(Active, Frozen, Closed, PendingKyc) NOT NULL DEFAULT 'PendingKyc'
  credit_limit          Money?   -- NULL = use tenant default (0)
  credit_used           Money    NOT NULL DEFAULT 0
  min_deposit_pct       decimal? -- NULL = use tenant default
  min_deposit_amount    Money?   -- placeholder, NULL today, additive future use (layaway-style floor)
  payment_terms_days    int?     -- NULL = use tenant default
  account_notes         text?
  pin_hash              string?  -- per-user PIN for offline override approval; lives on User, not Partner
}
```

All `account_status` mutations are append-only with `(actor_user_id, occurred_at, reason)` audit rows. Required for French/Italian fiscal-audit scrutiny + GDPR purpose-limitation marker.

### 3.2 Tenant default policy

```
tenant_account_policy {
  charge_to_account_enabled   bool     -- master switch
  default_min_deposit_pct     decimal  -- e.g. 30.00
  default_credit_limit        Money    -- e.g. 0 (must be explicitly set per customer)
  default_payment_terms_days  int      -- e.g. 30
  aml_cash_cap                Money    -- jurisdiction default, overridable
}
```

### 3.3 Override audit (Tier 3 rule)

```
override_audit {
  id, occurred_at, actor_user_id, approver_user_id?
  override_type ENUM(BelowMinDeposit, OverCreditLimit, OverAmlCap, EnableAccountForcedKyc, RefundCustomerCreditAsCash)
  channel       ENUM(LocalPin, RemotePush, AccountantOverride)
  context_json  -- sale_id, partner_id, amount, gap, etc.
  reason        text NOT NULL
}
```

Reason text is always required.

### 3.4 Payment + Document linkage

```
payment {
  id, company_id, partner_id, amount, currency, payment_date,
  payment_method_id, payment_repository_id,
  origin           ENUM('pos','web_admin','mobile','api')
  origin_terminal_id   FK Terminal NULL  -- set when origin='pos'
  document_id          FK Document NULL  -- set when fiscal Document was emitted
  status, journal_entry_id, ...
}

document (existing table) {
  ..., type ENUM(... 'Encaissement', 'OnAccountCharge', 'TicketIdentified' ...)
  sequence_number, fiscal_hash, previous_hash, chain_sequence
  hash_payload_version    int  -- v2 today, v3 future
  terminal_id  FK NULL    -- physical terminal (for POS docs)
  payment_document_type ENUM('encaissement','acompte') NULL -- discriminator for §2.3
}

payment_allocation (existing) {
  payment_id, allocated_to_document_id, amount
}
```

The nullable `document_id` on Payment is the forward-compat seat for France-cert-mode emission of fiscal Documents on web/mobile payments.

---

## 4. Hash chain architecture

### 4.1 Hybrid: event chain + document chain

Two chains, both live, both verifiable, mutually consistent.

**Event chain** — new wiring on top of existing `audit_events` table:

- Lives in `audit_events`, extended with `previous_hash`, `chain_sequence`, `channel_key` columns.
- Built at emission of every `HashableFiscalEvent`, synchronously.
- Scope: per `channel_key` —
  - POS terminal → `pos:<terminal_id>`
  - Web admin → `web:<company_id>`
  - Mobile → `mob:<company_id>`
  - API integrations → `api:<company_id>`
- Hash: `SHA256( getHashableData() pipe-joined || previous_hash )`
- Verifier: `php artisan fiscal:verify-event-chain --channel=...`

**Document chain** — existing AutoERP implementation, unchanged:

- Lives in `documents` (`fiscal_hash`, `chain_sequence`, `previous_hash`).
- Built at document posting, synchronously.
- Scope: per `terminal_id`.
- Hash: V2 payload (existing 6-field), unchanged for this spec.
- Verifier: existing `php artisan pos:verify-chain --terminal=...`.

**Reconciliation:** every POS-issued Document N has a corresponding fiscal event E whose `hashable_data` references `N.id` + `N.fiscal_hash`. An auditor can walk either chain and cross-check.

### 4.2 Why both

- **Document chain** is the NF525-conventional, ZATCA-compatible pattern. Stays in place for POS.
- **Event chain** covers the gap: web/mobile-initiated payments don't pass through a physical terminal, so they're not in the document chain. They are in the event chain.
- **Backfilling is prohibited.** Chain integrity is emission-time, not derivable later. NF525 auditors specifically check.
- **Corrections via opposing events**, never mutation. "Accountants don't use erasers."
- **Projections must be pure and versioned.** Every Document derived from events stamps a `projection_version`.

### 4.3 Wiring work in scope

The `HashableFiscalEvent` contract is half-built: 9 events already implement `getHashableData()` but have zero callers. Concrete steps:

1. Add `previous_hash`, `chain_sequence`, `channel_key` columns to `audit_events`.
2. Define `HashableFiscalEvent` interface; existing 9 events declare conformance; 2 new events implement it (`EncaissementPosted`, `OnAccountChargePosted`).
3. Extend `DomainEventSubscriber` to compute + write chain fields when a `HashableFiscalEvent` fires, locked per channel.
4. Add `fiscal:verify-event-chain` command.
5. Guardrails in code: no backfill API, opposing events for corrections, `projection_version` stamping.
6. Existing document chain stays as-is.

### 4.4 Hash payload versioning

The current production payload (V2) is `receipt_number|posted_at|total|currency|vat_breakdown_hash|payment_methods_hash` and stays in place for this spec — Encaissement and OnAccountCharge use V2 with VAT-breakdown empty for Encaissement (no VAT line). The `hash_payload_version` column is present so V3 (richer, internationally-compliant) is additive in a future spec. V3 work flow when scheduled:

1. Opus deep-research plan against France / Italy / Saudi Arabia / etc.
2. Codex adversarial review of the plan.
3. Manual cross-check against authoritative sources.
4. Migration plan that does **not** rebuild V2 hashes (chain integrity preserved).

---

## 5. Domain services

### 5.1 EncaissementService (new)

Single entry point for "payment received from a customer." Both POS and web admin call it.

```
EncaissementService::record(input) → result
  in one DB transaction:
    1. Validate Partner is Active (or override accepted).
    2. Create Document (type=Encaissement) — origin-dependent:
         - POS: per-terminal hash chain, sequence on company.
         - Web/mobile: skip Document creation in Tunisia mode;
           emit only event-chain entry. (France-cert mode: emit
           Document on virtual admin terminal chain.)
    3. Create Payment row linked to Document (if emitted).
    4. Invoke PaymentAllocationService::apply(
         strategy = FIFO | DueDate | Manual,
         payment_id,
         partner_open_invoices)
       Excess → CustomerAdvance GL.
    5. Fire EncaissementPosted event (HashableFiscalEvent).
    6. Fire PaymentReceived event.
    7. Update Partner.balance_updated_at.
```

### 5.2 OnAccountChargeService (new)

For the charge-to-account flow at checkout (partial or full sale on credit).

```
OnAccountChargeService::record(input) → result
  in one DB transaction:
    1. Validate Partner is Active.
    2. Validate rules: tendered ≥ min_deposit_pct × total
                      AND (current_balance + (total - tendered)) ≤ credit_limit
       OR override approval present.
    3. Create Document (type=OnAccountCharge).
    4. Allocate tender to invoice (partial/full).
    5. The unpaid portion creates a receivable on Partner balance.
    6. Fire OnAccountChargePosted event.
```

### 5.3 PaymentAllocationService (existing, reused)

Already implemented in web admin. Three strategies: FIFO (oldest first), DUE_DATE (most overdue first), MANUAL (user-selected). Excess routes to `CustomerAdvance` GL. Reused as-is.

### 5.4 ApprovalService (new — primitive for overrides)

```
ApprovalService::request(type, context) → ApprovalRequest
ApprovalService::approve(request_id, approver, channel) → result

Channels (strategy pattern):
  LocalPinChannel       -- offline-capable, PIN hash on User
  RemotePushChannel     -- stub for now; future: FCM/APNs push to manager device
  AccountantOverrideChannel -- future
```

MVP wires only `LocalPinChannel`. The `RemotePushChannel` interface, settings flag, and backend endpoint are stubbed so adding push delivery later is additive.

### 5.5 CustomerService (new in POS module, with web admin retrofit)

Search, create, edit operations. Backs both POS and web admin.

```
CustomerService::search(query, scope) → Partner[]
CustomerService::create(input) → Partner   -- offline-safe with temp UUID
CustomerService::edit(partner_id, input) → Partner
CustomerService::enableAccount(partner_id, by_user) → Partner
  -- PendingKyc → Active; requires permission pos.customer.enable_account
CustomerService::changeStatus(partner_id, new_status, reason, by_user)
  -- writes append-only audit row
```

---

## 6. Permissions and approval primitive

### 6.1 Spatie permission keys (new)

```
pos.customer.search
pos.customer.create
pos.customer.edit
pos.customer.enable_account      -- PendingKyc → Active
pos.customer.change_status       -- to Frozen / Closed (typically manager)
pos.payment.receive_on_account
pos.payment.refund_customer_credit_as_cash   -- governs the "give out cash" risk
pos.charge_to_account.execute
pos.override.min_deposit
pos.override.credit_limit
pos.override.aml_cap
```

Permissions wired through the existing `auth:sanctum + SetPermissionsTeam` middleware chain on POS routes.

### 6.2 Override approval model (Hybrid C)

| Override type | Approval channel |
|---|---|
| `min_deposit` | Cashier-with-permission (`pos.override.min_deposit`), no second user |
| `credit_limit` | Manager PIN (approval primitive) |
| `aml_cap` | Manager PIN (approval primitive) |
| `enable_account` (PendingKyc → Active) | Cashier-with-permission (`pos.customer.enable_account`); appropriate for small-shop / cashier=owner setups |
| `refund_customer_credit_as_cash` | Manager PIN (approval primitive) — "give out cash" risk |

For all override types, the request flow lands an `override_audit` row regardless of channel.

### 6.3 Risk asymmetry on cash

The POS treats cash flow asymmetrically based on shop-side risk:

- **Accepting cash** (encaissement, sale tender, on-account payment) — always allowed offline, no special approval. Zero risk to the shop; server reconciles on sync.
- **Giving out cash** — requires connectivity or explicit manager approval:
  - Refunding a customer's on-account credit balance as cash → `pos.payment.refund_customer_credit_as_cash` permission + (online verify OR manager PIN).
  - Sale refunds: governed by existing Phase 1 refund flow, out of scope here.
  - Standard change-making on overpayment: not "giving out cash" in the risky sense (just arithmetic), no special handling.

---

## 7. Offline-first behavior

### 7.1 Offline envelope

| Operation | Offline-capable? | Local data source | Server reconciliation |
|---|---|---|---|
| Customer search | Yes | SQLite mirror | N/A (read-only) |
| Customer create (quick-create at till) | Yes | Temp UUID locally | Server dedups on phone → merge directive |
| Customer edit | Yes (limited) | Local pending change | Last-write-wins on server, audit row |
| Account enable (PendingKyc → Active) | Yes | Local + cashier permission | Audit row on sync |
| Charge-to-account at checkout | Yes | Last-known balance + queued offline charges | Server recalculates against live ledger; excess → CustomerAdvance; never reject |
| Receive on-account payment (Encaissement) | Yes | Local hash chain on terminal | Server runs `PaymentAllocationService` on live ledger; overpayment → advance |
| Credit-limit check | Yes (best-effort) | Last-known balance; staleness shown | Server enforces hard cap on sync; may flip status to `Frozen` |
| Override approval (PIN) | Yes | Local PIN hash | N/A |
| Override approval (Push) | **No** | Requires server round-trip | Falls back to PIN |
| Account-statement print | Yes | Local mirror; "as of HH:MM" footer | — |
| Refund customer credit as cash | **No by default** | Requires online verify OR explicit manager PIN override | — |

### 7.2 SQLite tables on POS

```
partners_mirror      -- inbound projection
documents_local      -- receipts, encaissements, on-account charges emitted offline
audit_events_local   -- local event chain (per-terminal)
outbox_events        -- outbound queue, FIFO, idempotent
inbox_projections    -- inbound queue (catalogue, partner updates, future marketplace orders)
override_audit_local -- override audit rows pending sync
```

### 7.3 Sync transport

Generic typed-envelope `{type, version, payload}` — POS is type-agnostic. Server applies via a type registry.

- **Outbound:** `POST /api/pos/sync/outbox` — batched, idempotency key = `{terminal_id, event_id, chain_sequence}`. Server skips already-ingested events.
- **Inbound:** `GET /api/pos/sync/since?cursor=<timestamp>` — deltas only since last cursor.
- **Cadence:** outbox flushes every 30s when online; inbox polls every 60s; both manual via Refresh button.
- **Visibility:** always-visible staleness badge "Synced 14:23" on Customers tab; red after 10 min offline.

### 7.4 Mirror refresh strategy

- **Scope:** per-company. Multi-company tenants get independent mirrors per company. Cashiers in different companies under one tenant have isolated views.
- **Initial pull:** full snapshot with progress UI on terminal startup or company switch.
- **Incremental pull:** every 60 s when online + before any "account enable" or high-value charge-to-account + manual refresh button.
- **Pull payload:** deltas — partners changed since cursor, account_status changes, balance_recompute events.

### 7.5 Conflict-resolution policy

| Conflict | Policy |
|---|---|
| Customer create duplicate (same phone) | Server returns merge directive; POS replaces temp UUID with server UUID locally; receipts referencing temp UUID get FK updated |
| Balance reconcile on payment sync | Server runs `PaymentAllocationService` against the **live** ledger at sync time; excess → `CustomerAdvance` GL |
| Account-status flip while POS offline | Server is authoritative on next sync; offline-queued charges against a now-Frozen account go through (already happened); notification surfaces on next session |
| Stale credit-limit override offline | Server records override + actor + reason + gap; no reversal — money already changed hands |
| Duplicate event submission | Idempotency key skips re-ingest |

### 7.6 Forward compatibility for fully-offline body-shop

The sync architecture above is duration-agnostic. A body-shop wanting multi-day offline operation needs only:

- (a) workshop-specific projections (vehicles, jobs, parts catalogue) — separate spec.
- (b) larger SQLite footprint sized for the planned offline window — sizing decision per deployment.
- (c) periodic local export/backup of state for recovery — separate spec.

Nothing in this spec blocks that future.

---

## 8. UI surfaces

### 8.1 POS — Customers tab (new top-level)

- Added as 5th route: `/`, `/sales`, `/customers` ← new, `/reports/z`, `/settings`.
- Layout: search bar at top (phone / email / loyalty / account# / name); result list with name, phone, balance badge (red if owed, green if credit), `account_status` badge.
- Each result row clickable → Customer Detail screen.

### 8.2 POS — Customer Detail screen

- Header: name, phone, email, account_status, account_notes.
- Balance card: current balance, credit_limit, last-synced-at indicator.
- Tabs: "Open invoices", "Recent payments", "Activity".
- Primary CTAs: **Receive Payment** (Encaissement), **New Sale** (returns to till with customer attached).
- Edit pencil → Customer Edit screen.

### 8.3 POS — Receive Payment screen

- Step 1: amount numpad.
- Step 2: tender method picker (reuses existing `AdvancedPaymentsModal` payment-method tiles + repositories).
- Step 3: allocation preview — fetches `/api/v1/smart-payment/preview-allocation` when online; offline runs a local FIFO simulation against last-known open invoices.
- Step 4: confirm → emits `Encaissement` Document + `Payment` + allocations; prints a `Reçu d'encaissement`.
- Staleness banner if offline > 10 min.

### 8.4 POS — Charge-to-Account flow at checkout

- Existing till payment screen extended with a **"Charge to account"** tender option (visible only when a Partner with `account_status=Active` is attached).
- Cashier enters tendered amount (cash/card/etc.).
- If `tendered < min_deposit_pct × total` OR `(current_balance + remaining) > credit_limit` → override modal:
  - "Below minimum deposit" → cashier-with-permission can override inline.
  - "Over credit limit" → manager PIN required (approval primitive).
- Confirmation modal before close: "Will add 50 TND to Khlifa Aymen's account balance. Confirm?"
- On confirm: emits `OnAccountCharge` Document + Payment for the tendered portion + AR receivable for the unpaid portion.

### 8.5 POS — Customer Search inline (mid-sale)

- "Attach customer" button on till; opens search modal.
- Same search semantics as Customers tab.
- Inline "Create new" option for quick-create (name + phone, optional national_id when AML threshold active).
- Promotes anonymous in-flight sale to identified before close-out.

### 8.6 Admin — Unified Payments listing

- Existing `/treasury/payments` listing extended:
  - New column `Source` (POS01 / Web admin / Mobile / API).
  - New column `Doc#` (fiscal sequence; `—` for legacy pre-spec).
  - Filterable by source.
- Existing B2B payments still render correctly with `origin='web_admin'` and `Doc#='—'`.

### 8.7 Admin — Customer Detail (existing, extended)

- `PartnerDetailPage` already shows balance + Documents tab + Payments tab.
- Extended: account_status badge, credit_limit, min_deposit_pct, payment_terms_days, account_notes.
- New section: "Account history" — append-only audit log of status changes.
- Existing "Receive Payment" form retains its current B2B flow; on payment creation, `origin='web_admin'` is stamped (no behavioural change for B2B users).

### 8.8 Customer-facing print documents

- **Reçu d'encaissement** — proof of payment for the customer; shows partner identity, amount, method, allocated invoices, new balance, fiscal sequence, hash signature.
- **Account statement** — printable on request, separate document with full invoice/payment/balance history.
- **Sale receipt footer** — when Partner with `account_status=Active` is attached, adds one line: `Solde du compte après ce ticket: X TND`.

---

## 9. Data flow scenarios

### 9.1 Receive on-account payment in POS (offline)

1. Cashier opens Customers tab, searches "55 123 456", taps result.
2. Customer Detail shows balance 245 TND owed.
3. Cashier taps "Receive Payment", enters 100 TND, picks Cash.
4. POS computes local FIFO allocation preview from last-known open invoices.
5. Confirm → POS creates local `Document(Encaissement)` with sequence `OTO/E/2026/000045` (reserved from a local sequence pre-allocator), local hash chain entry on terminal, local `Payment` row, local allocation rows.
6. POS prints `Reçu d'encaissement`.
7. Drawer opens, cash goes in.
8. Outbox queues the event bundle.
9. On reconnect: server ingests, runs `PaymentAllocationService` against live ledger (which may have changed if customer paid online in meantime), applies allocations or routes excess to `CustomerAdvance`. Cashier sees no rejection.

### 9.2 Receive payment in web admin (B2B, today's flow extended)

1. Admin opens partner detail, clicks "Record Payment".
2. Existing `PaymentForm` runs — fields unchanged.
3. On submit, `EncaissementService::record` is called with `origin='web_admin'`.
4. Service creates `Payment` row (Tunisia mode: no `Document`).
5. Allocation runs; existing UI unchanged.
6. **New:** a `PaymentReceived` event (HashableFiscalEvent) fires; the event chain on `web:<company_id>` gets a new row with chain hash.

The behaviour visible to a B2B user is identical. The event chain is invisible plumbing.

### 9.3 Charge-to-account at till (online)

1. Cashier scans items, total = 100 TND.
2. Cashier attaches customer (Active, credit_limit=200, current_balance=60, min_deposit_pct=30%).
3. Cashier enters cash tender = 20 TND.
4. POS validates: 20 < 30% × 100 = 30 → below min deposit. Override modal opens.
5. Cashier has `pos.override.min_deposit` permission → enters reason "loyal customer, paying balance Friday" → confirms.
6. POS validates credit: current 60 + remaining 80 = 140 ≤ 200 ✓.
7. Confirmation modal: "Will add 80 TND to Khlifa Aymen's account balance. Confirm?"
8. Confirm → emits `OnAccountCharge` Document, Payment for 20 TND, AR receivable for 80 TND.
9. Receipt prints with `Solde du compte après ce ticket: 140 TND` footer.

### 9.4 Customer create offline (quick-create at till)

1. Walk-in customer wants to pay 6 000 TND in cash for a big purchase — exceeds Tunisia AML cap (5 000 TND).
2. POS shows AML warning, requires identification.
3. Cashier opens search → "Create new" → enters name + phone + national_id.
4. POS creates local Partner with temp UUID, `account_status=PendingKyc`.
5. Attaches Partner to in-flight sale, promotes anonymous to identified.
6. Sale closes as `TicketIdentified` with promoted hash chain entry.
7. On sync, server:
   - If phone matches existing Partner → merge directive; local temp UUID swapped to server UUID; document FK updated.
   - If no match → server accepts the new Partner; temp UUID becomes canonical.

### 9.5 Refund customer credit as cash (give-out-cash risk)

1. Customer has 50 TND credit balance, wants it back in cash.
2. Cashier opens Customer Detail → "Refund credit as cash".
3. POS checks: this operation needs connectivity OR manager PIN override.
4. If online → server verifies credit balance is currently 50 TND, approves.
5. If offline → modal: "This operation gives out cash and requires manager approval to act on offline data." → manager enters PIN.
6. Cashier hands over 50 TND. POS records the cash-out as an opposing event to the original credit.
7. Audit row captures: actor, approver, channel, reason, gap (if any).

---

## 10. Migration plan

### 10.1 Database migrations

- Add new `DocumentType` enum values: `Encaissement`, `OnAccountCharge`, `TicketIdentified`.
- Add `payment_document_type` ENUM + CHECK constraint (only `'encaissement'` permitted today).
- Add Partner account-policy columns (`account_status`, `credit_limit`, `credit_used`, `min_deposit_pct`, `min_deposit_amount` placeholder, `payment_terms_days`, `account_notes`).
- Add `tenant_account_policy` columns or row.
- Add `audit_events` chain columns (`previous_hash`, `chain_sequence`, `channel_key`).
- Add `payment.origin`, `payment.origin_terminal_id`, `payment.document_id` columns.
- Add `override_audit` table.
- Add Spatie permission keys.

All migrations are **additive**. No data rewrite. No hash rebuild. Chain integrity preserved.

### 10.2 Backfill

**None for fiscal data.** Backfilling defeats chain integrity. Existing pre-spec receipts stay with their V2 hashes; new documents enter the chains forward from cutover.

For non-fiscal data:

- Partner `account_status` initialised to `PendingKyc` for all existing Partners (legacy B2B partners that already carry balances are migrated to `Active` based on `total_receivable > 0 OR has_open_invoices`).
- `audit_events` chain seed: first row per channel after migration has `previous_hash=""`, `chain_sequence=1`.

### 10.3 Feature flags

Per-tenant flag `pos_customer_accounts_enabled`. Default false for existing tenants (zero behaviour change). On first POS-only tenant rollout, enable.

Sub-flags for granular rollout:
- `pos_customer_management` (Phase 1).
- `pos_on_account_payment` (Phase 2).
- `pos_charge_to_account` (Phase 3).
- `account_status_workflow` (Phase 4).

### 10.4 Tunisia → France path

When France becomes a launch target with NF525 cert in scope:

1. Run the V3 hash payload spec (Opus + Codex + manual verification).
2. Enable `chain_admin_payments=true` per French Company.
3. Create one virtual admin Terminal per Company (`is_virtual=true`).
4. `EncaissementService` emits Document on virtual terminal chain for web/mobile payments.
5. Pre-cutoff web/mobile payments stay `document_id IS NULL`, grandfathered.
6. Cert submission with cert dossier explaining both chains.

---

## 11. Testing strategy

### 11.1 Backend (PHPUnit)

- **Domain tests** — `EncaissementService`, `OnAccountChargeService`, `ApprovalService` unit-tested with real Eloquent models + `RolesAndPermissionsSeeder`. No mocked DB.
- **Chain tests** — `fiscal:verify-event-chain` and `pos:verify-chain` both pass against seeded fixtures. Tamper-detection: mutate a row, verify command must fail.
- **Allocation tests** — FIFO / DueDate / Manual strategies regression-tested. Excess → CustomerAdvance posted to correct GL.
- **Acompte boundary tests** — CHECK constraint rejects `payment_document_type='acompte'` until feature flag flips.

### 11.2 Frontend POS (Vitest)

- Customer search, create, edit components render and submit correctly.
- Offline-mode tests: outbox queues correctly; staleness badge updates.
- Override modal flows: PIN entry, audit-row creation.
- Charge-to-account flow: rule violations trigger override; confirmation modal blocks until confirmed.

### 11.3 Compliance / verifier

- After Phase 2 lands: `php artisan fiscal:verify-event-chain --channel=pos:T01` on every CI run.
- Drift detection: chain length and last-hash recorded in CI fixtures; PR must update them to match if intentional, fails otherwise.

### 11.4 End-to-end (manual + scripted)

- Tunisian para-pharmacy golden path:
  1. Cashier creates customer offline.
  2. Customer makes 100 TND on-account payment offline.
  3. Terminal reconnects; sync drains; admin sees payment in unified listing with `Source=POS01`.
  4. Web admin records additional 50 TND payment for same customer.
  5. Customer returns; cashier sees updated balance after next sync.
- B2B regression: existing Web Admin Payment flow remains unchanged.

---

## 12. Open items / future work

These are deliberately deferred. Listed so they're tracked:

1. **V3 hash payload spec** — internationally-compliant payload (France/Italy/Saudi Arabia/etc.). Process: Opus deep-research plan → Codex adversarial review → manual source verification.
2. **NF525 certification submission** — separate effort. Architecture is cert-ready; submission requires AFNOR reference doc (paywalled), virtual-terminal-chain emission for web/mobile payments, cert dossier.
3. **Deposits feature (acompte / arrhes)** — `FactureAcompte` document type, VAT-on-collection per Art. 269 CGI, GL 4191 + 44571 posting. Schema seam reserved.
4. **Layaway-style min-deposit floor** — `min_deposit_amount` column reserved as placeholder. Add when shop demand surfaces.
5. **Auto-freeze cron** — background job that flips `account_status=Active → Frozen` when balance > N days overdue. Tenant-configurable N. Manual freeze available in MVP; auto is post-MVP.
6. **Marketplace inbound orders** — `inbox_projections` already accommodates; specific feature spec needed for "Online Orders" tab.
7. **Delivery-company integration** — webhook → `DeliveryStatusChanged` event → projected to inbox. Separate spec.
8. **Mobile owner app + RemotePush approval channel** — separate spec. Approval primitive interface already exists.
9. **Body-shop fully-offline workshop** — multi-day offline operation, workshop projections (vehicles, jobs). Separate spec.
10. **AML threshold table for Algeria + EU AMLR (2027)** — values configured per tenant; add to defaults list when those markets approach.
11. **Italy lottery-vs-codice-fiscale mutual exclusion** — when Italy enters scope, enforce mutual exclusion at document emission.
12. **Algeria stamp-duty calculator** — when Algeria enters scope, 1% / 1.5% / 2% tiered calculation per cash-paid receipt.

---

## Appendix A — Authoritative citations

**French fiscal:**
- BOI-TVA-DECLA-30-10-30 — inalterability scope (§50 payments in scope; §140 chaînage des enregistrements).
- Art. 269 CGI — VAT exigibility on acompte.
- Art. 289 CGI — facture d'acompte obligation.
- Service-public.gouv.fr F31187 — acompte / arrhes / avance distinction.
- L.123-22 Code de commerce — 10-year accounting retention.
- Art. 1590 Code civil — arrhes default presumption.

**Tunisian fiscal:**
- Art. 18 Code de la TVA — invoice numbering ininterrompue.
- TEIF 2026 obligation (INNORPI / TTN).
- Plan Comptable Tunisien — account 411 + 4191 mirror French PCG.

**NF525:**
- BOI-TVA-DECLA-30-10-30 + AFNOR NF525 (paywalled, to acquire before cert submission).
- Infocert NF525 guide; LNE certification specs; Dolibarr NF525 wiki (most-explicit public implementation reference).

**Industry / event sourcing:**
- Pat Helland, *Immutability Changes Everything* (CIDR 2015 / ACM Queue 2016).
- Greg Young, CQRS/ES talks.
- BSI TR-03153 (German TSE — event-chained fiscal regulation).
- ZATCA Phase 2 E-Invoicing Implementation Resolution (document-chained PIH).

**AML:**
- service-public.fr F10999 — France €1 000 cap.
- EU AMLR (Council adopted 2024-05-30) — €10 000 EU-wide from 2027-07.
- HM Revenue & Customs — HVD registration under MLR 2017.

---

## Appendix B — Decisions log (from brainstorming)

| Decision | Resolution |
|---|---|
| Scope packaging | One cohesive spec, four-phase implementation |
| Customer identity model | Soft attach + `account_status` enum + mid-tx promote |
| Override approval | Hybrid C (cashier permission for min-deposit; PIN/push for credit-limit + AML) |
| Approval as pluggable primitive | Yes — `LocalPinChannel` for MVP, stubs for `RemotePushChannel` |
| Min-deposit field | `min_deposit_pct` only; `min_deposit_amount` reserved placeholder |
| Credit limit | Hard cap (override required to exceed) |
| Auto-freeze on overdue | Deferred (manual freeze in MVP) |
| Enable-account permission | Cashier-level via `pos.customer.enable_account` |
| Fiscal document taxonomy | `Ticket`, `TicketIdentified`, `Facture`, `OnAccountCharge`, `Encaissement`, `Avoir`, `FactureAcompte` (reserved) |
| Sequence scoping | Per-company for Facture/Encaissement/Avoir/FactureAcompte; per-terminal for Ticket/Z |
| Hash payload version | V2 (existing) for this spec; V3 future spec |
| Print footer for house accounts | One-line "Solde après ce ticket" + separate Statement document |
| Acompte forward-compat | Lock seam now: discriminator + CHECK constraints + reserved `FA-` namespace |
| Hash chain architecture | Hybrid — event chain (new wiring, all channels) + document chain (existing, POS only) |
| Web admin payment changes | Existing B2B flow unchanged; new `origin` field stamped; participates in event chain |
| Offline envelope | Full — customer mgmt + payments + charge-to-account all run offline |
| Sync transport | Generic typed envelope; future marketplace + delivery additive |
| Mirror scope | Per-company; 60s incremental; staleness badge always visible |
| Risk asymmetry on cash | Accept-cash always allowed offline; give-out-cash requires connectivity or manager PIN |
| Refund customer credit as cash | New permission `pos.payment.refund_customer_credit_as_cash`; online verify or manager PIN; audit row |

---

**End of spec.**
