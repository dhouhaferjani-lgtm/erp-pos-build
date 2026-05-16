# POS Fiscal Event Engine + Customer Accounts — Design Spec v2.0

**Date:** 2026-05-13
**Status:** Major architectural pivot from v1 / v1.1. Adopts the event-sourced fiscal ledger pattern from the architecture strategy documents. Awaiting Codex round-3 review then implementation plan.
**Predecessors:**
- v1 (archived): `2026-05-13-pos-customer-accounts-design.md` — receipt-centric design; Codex round 1 BLOCK.
- v1.1 (archived): `2026-05-13-pos-customer-accounts-design-v1.1.md` — receipt-variant design; Codex round 2 BLOCK.
- v2.0 (this document) — fiscal event engine + customer accounts.
- Architectural inputs: `~/Downloads/fiscal_chain_architecture_strategy.md`, `~/Downloads/pos_printable_documents_architecture.md`.
- Codex round 1: `apps/erp/docs/superpowers/reviews/2026-05-13-pos-customer-accounts-codex-review.md`.
- Codex round 2: `apps/erp/docs/superpowers/reviews/2026-05-13-pos-customer-accounts-codex-review-round2.md`.

---

## Changelog (v1.1 → v2.0)

This is not a "v1.2 fix the round-2 findings" rewrite. It is a structural pivot. The POS fiscal layer becomes an **append-only fiscal event ledger** (the source of truth) with **typed events**, **per-(tenant_id, terminal_id) hash chain**, and a **`SignatureProviderInterface`** that abstracts country-specific signature schemes. Printable documents become **projections** from events.

What this changes:

1. **`fiscal_events` table** becomes the canonical fiscal store. The existing `pos_receipts` table continues to operate for SALE_RECEIPT (the legacy path); each SALE_RECEIPT additionally emits a `SaleReceiptBridge` event to `fiscal_events` for cross-chain integrity. The pos_receipts → fiscal_events full migration is a separate future spec.
2. **New typed events** for everything in this spec's customer-account scope: `ACCOUNT_PAYMENT`, `ACCOUNT_REFUND`, `ACCOUNT_CREDIT_ISSUE`, `ACCOUNT_CREDIT_USAGE`, `DEPOSIT_RECEIPT`, `ACCOUNT_STATUS_CHANGED`, `OVERRIDE_AUDITED`, `CASH_OUT_EXECUTED`. These emit to `fiscal_events` natively. No more pos_receipts hacks for receipt-type variants.
3. **`SignatureProviderInterface`** abstraction. V2 = current ReceiptHashService payload (legacy compat for SALE_RECEIPT bridges). V3 = the richer payload bound to event_type + tenant identity + partner identity snapshot + signature_version. Future: country-specific implementations (NF525-LNE, ZATCA, TTN) plug in.
4. **Printable documents as projections.** A `FiscalDocument` model carries a link to its source `fiscal_event_id`, the printable type, the rendered totals, and the printable template name. Reprints don't create new fiscal events; they emit a `REPRINT_COPY` audit event referencing the original.
5. **Identity-map split with sealed snapshots in event payload.** A customer's identity at the time of the event is captured in the event payload immutably; the live `partner_id` FK may change later (temp→canonical merge), but the event's snapshot is permanent.
6. **Approval primitive grounded in actual Tauri stack.** AES file key (existing `~/.izipos_key`) for PIN-hash encryption; Ed25519-signed permission snapshots; explicit threat model.
7. **Server-side audit chain** for `ACCOUNT_STATUS_CHANGED`, `OVERRIDE_AUDITED`, `CASH_OUT_EXECUTED` — these are fiscal events and chain alongside customer-account financial events.
8. **`cash_out_policy` table** with versioned per-jurisdiction thresholds, typed audit fields promoted out of `context_json`.
9. **AML state machine** as explicit per-event-type rules.
10. **Pattern-derived flags** (suspicious behavior, anomalies) are explicitly **downstream of the fiscal event stream** in TimescaleDB / ML, not POS-emitted fiscal events. The fiscal events themselves are factual.
11. **No backfill required** — first tenants are new deployments. Initial `account_status` for non-existent customers is the default (PendingKyc). Legacy `pos_receipts` rows stay unchanged.

What round-2 BLOCKERs auto-resolve:
- RB1 receipt_type migration conflict → resolved (we don't touch pos_receipts.receipt_type).
- RB2 empty-lines CHECK violation → resolved (ACCOUNT_PAYMENT is its own event, not a payment-only receipt).
- RB3 sync DTO can't carry new fields → resolved (new sync transport for fiscal_events; new typed envelopes).
- RB4 V2 hash doesn't bind identity → resolved (V3 implementation in SignatureProviderInterface binds full event payload).

What round-2 P1s addressed explicitly: R1 (settlement vs payment), R2 (status/override inalterability), R3 (origin handling — no backfill needed now), R4 (PIN crypto for actual stack), R5 (sealed snapshot vs live FK), R6 (cash_out_policy table + audit schema), R7 (Tunisian classification — jurisdiction-parameterized projection layer), R8 (offline allocation mismatch handled via explicit reconciliation event).

What round-2 P2/P3 addressed: R9 (AML state machine), R10 (min identity offline), R11 (feature flag gates in service code), R12 (V3 framing), R13 (override mapping normative table).

---

## 1. Overview

### 1.1 Problem statement

The POS desktop application (Tauri) needs:

- **Customer management** (search, create, edit) for B2C and B2B customers, working offline.
- **On-account payments** — customers paying down balances or making deposits, not tied to a specific sale at the till.
- **Charge-to-account** — sales settled partially or wholly by debit to a customer's account.
- **Account policy** — per-customer credit limit, minimum deposit %, status (Active/Frozen/Closed/PendingKyc), approval gates.
- **Risk-asymmetric cash-handling controls** — accept-cash freely; gate give-out-cash operations.

Underneath: the fiscal layer that records all of this needs to be **scalable across jurisdictions** (Tunisia, France, Saudi Arabia, EU AMLR-2027, etc.) without being rewritten each time, and needs to satisfy **NF525-style inalterability** for the operations that warrant it (account-status changes, override approvals, cash movements, customer-account financial events).

The right shape for that fiscal layer is an **append-only event ledger** with typed events and country-pluggable signature schemes — not a receipt-flag table. v1 and v1.1 attempted to make the existing `pos_receipts` table absorb new variants; Codex correctly demonstrated that this fights the schema, the CHECK constraints, the sync DTO, the receipt-payment service, and the hash payload simultaneously. v2.0 adopts the event-sourced architecture instead.

### 1.2 Scope (in)

1. **Fiscal Event Engine** — new `fiscal_events` table, `FiscalEventEngine` service (emit + verify + project), `SignatureProviderInterface` (V2 + V3 implementations), per-(tenant_id, terminal_id) chain.
2. **Customer-account fiscal events** — `ACCOUNT_PAYMENT`, `ACCOUNT_REFUND`, `ACCOUNT_CREDIT_ISSUE`, `ACCOUNT_CREDIT_USAGE`, `DEPOSIT_RECEIPT`.
3. **Audit / control fiscal events** — `ACCOUNT_STATUS_CHANGED`, `OVERRIDE_AUDITED`, `CASH_OUT_EXECUTED`.
4. **Bridge event** for legacy SALE_RECEIPT — `SALE_RECEIPT_BRIDGE` emitted by every pos_receipts finalization, referencing the receipt and preserving chain continuity in fiscal_events.
5. **Customer management** — POS UI (Customers tab) for search, create, edit; identity-map with sealed snapshots in event payload; multi-factor server-side dedup; minimum identity fields per trigger (ordinary vs AML).
6. **Account policy + rules engine** — tier-3 (tenant default → per-partner override → per-transaction override), persisted in `tenant_account_policy`, `partner.account_policy`, and `override_audit` (which is itself materialized from `OVERRIDE_AUDITED` events).
7. **Approval primitive** — `LocalPinChannel` (offline-capable; grounded in actual Tauri AES file-key crypto + Ed25519 signed permission snapshots) and `RemotePushChannel` stub interface.
8. **`CashOutPolicy`** — per-jurisdiction versioned thresholds; promoted audit fields.
9. **AML state machine** — explicit per-jurisdiction states with per-event-type rules.
10. **POS↔Server sync transport** — typed envelope for fiscal events; outbox/inbox; idempotency.
11. **Printable document projection** — `FiscalDocument` model + per-event-type templates per jurisdiction; full fiscal sequence printed once.
12. **Unified admin view** — existing web admin payments listing extended with `origin` column (conditional, not blanket backfill) and `fiscal_event_id` reference.
13. **B2B path retrofit (minimal)** — existing `POST /api/v1/payments` flow gains an optional `Treasury.Payment.origin` column (set forward, no backfill since no existing tenants); existing `PaymentRecorded` event continues firing and is bridged into the new chain for the cases where Treasury Payment originates from POS sync (already-existing `payment_type=PaymentType::POS`).
14. **Permissions** — Spatie permission keys + normative override-mapping table.
15. **Feature-flag gate points** — explicit in service code, route middleware, sync ingestion, UI visibility.
16. **Testing strategy** — chain walker, signature-provider conformance tests, projection determinism, compensating-event correctness.

### 1.3 Scope (out — deferred with named follow-up)

- **Full SALE_RECEIPT migration to fiscal_events** — legacy pos_receipts path continues; bridge event preserves chain. Migration is a separate future spec.
- **Cash drawer fiscal events** (`OPENING_FLOAT`, `CASH_IN`, `CASH_OUT`, `SAFE_DROP`) — existing cash-drawer flows continue; future spec migrates them.
- **Session fiscal events** (`SESSION_OPEN`, `SESSION_CLOSE`, `X_REPORT`, `Z_REPORT`) — existing Z-report flow continues; future spec migrates.
- **B2B document events** (`InvoicePosted`, `DeliveryNoteConfirmed`, etc.) — existing 9 `getHashableData()` stubs are ready to plug into the engine when B2B fiscal-chaining is in scope; not addressed here.
- **`SUSPICIOUS_OPERATION` and pattern-derived analytic flags** — downstream of the fiscal stream in TimescaleDB / ML. Not POS-emitted.
- **NF525 certification submission** — architecture cert-ready; submission is a separate effort with AFNOR reference doc acquisition + Opus deep-research + Codex adversarial review + manual source verification.
- **Country-specific signature providers** (NF525-LNE, ZATCA, TTN) — `SignatureProviderInterface` defines the contract; concrete implementations beyond V2/V3 ship per-country as the market is entered.
- **Marketplace inbound orders, delivery integration, mobile owner app** — sync transport reserves the namespace; each feature is its own spec.
- **Body-shop fully-offline workshop** — sync architecture supports it; workshop-specific projections are a separate spec.
- **Layaway-style minimum-deposit fixed-amount floor** — `min_deposit_amount` reserved placeholder.
- **Auto-freeze cron** — manual freeze (via web admin) in MVP.
- **ML downstream pipeline** — fiscal events flow to TimescaleDB; analytics pipeline is owned separately.

### 1.4 Phasing (informs implementation plan)

Locked with the owner:

- **Phase 1** — `fiscal_events` table + `FiscalEventEngine` service + `SignatureProviderInterface` (V2 + V3) + `SALE_RECEIPT_BRIDGE` event + chain verifier command. Foundation.
- **Phase 2** — Customer management + `ACCOUNT_PAYMENT` event + projection to printable `ACCOUNT_PAYMENT_RECEIPT`. Smallest customer-facing slice.
- **Phase 3** — Charge-to-account flow: SALE_RECEIPT (existing path, with bridge event) + `ACCOUNT_PAYMENT` projection per doc-2 §18 Option B. Rules engine + override flow integrated.
- **Phase 4** — Account status workflow (`ACCOUNT_STATUS_CHANGED`) + override audit events (`OVERRIDE_AUDITED`) + cash-out events (`CASH_OUT_EXECUTED`). Unified admin view.
- **Phase 5** — Refinements: identity-map full reconciliation (`IDENTITY_ALIAS_RECONCILED`), per-deposit flow (`DEPOSIT_RECEIPT`), allocation reconciliation events, deposit-tied-to-sale linkage.

---

## 2. Architecture: Fiscal Event Engine

### 2.1 Core principle

**Append-only event ledger.** Every fiscal or monetary operation is an immutable event. Corrections happen via compensating events that reference the original — never via mutation, never via soft-delete. The chain proves chronological integrity.

This applies to: ACCOUNT_PAYMENT, ACCOUNT_REFUND, ACCOUNT_CREDIT_ISSUE/USAGE, DEPOSIT_RECEIPT, ACCOUNT_STATUS_CHANGED, OVERRIDE_AUDITED, CASH_OUT_EXECUTED, and SALE_RECEIPT_BRIDGE for legacy receipts.

This does NOT apply to: ML-derived analytic flags, draft carts (pre-fiscalization), reprint operations beyond the audit log entry.

### 2.2 `fiscal_events` table

```
fiscal_events {
  id                       UUID PK
  tenant_id                UUID NOT NULL                    -- chain partition
  company_id               UUID NOT NULL                    -- legal entity
  terminal_id              UUID NOT NULL                    -- physical or virtual; partitions chain head
  operator_id              UUID NOT NULL                    -- user attribution
  fiscal_session_id        UUID NULL                        -- nullable until SESSION_* events migrate later
  event_type               VARCHAR(64) NOT NULL             -- typed; see §3
  event_version            SMALLINT NOT NULL DEFAULT 1      -- payload schema version per event_type
  signature_version        SMALLINT NOT NULL                -- which SignatureProvider sealed this
  sequence_number          BIGINT NOT NULL                  -- per (tenant_id, terminal_id), monotonic
  event_timestamp          TIMESTAMPTZ NOT NULL             -- wall clock at emission (UTC)
  business_date            DATE NOT NULL                    -- fiscal day attribution (cashier's local day)
  reference_event_id       UUID NULL                        -- for compensating events
  reference_document_id    UUID NULL                        -- cross-link to pos_receipts.id, documents.id, etc.
  partner_id               UUID NULL                        -- live FK; may update after identity merge
  partner_identity_snapshot JSONB NULL                      -- immutable identity at time of event
  payload                  JSONB NOT NULL                   -- event-specific payload (see §3)
  totals                   JSONB NULL                       -- {gross_cents, net_cents, vat_cents} when applicable
  vat_breakdown            JSONB NULL                       -- per-rate breakdown when applicable
  payment_breakdown        JSONB NULL                       -- per-method tender breakdown when applicable
  previous_hash            BYTEA NULL                       -- previous chain hash; NULL only for genesis
  current_hash             BYTEA NOT NULL                   -- this event's hash
  sync_status              VARCHAR(16) NOT NULL DEFAULT 'pending'  -- pending|synced|verified
  created_at               TIMESTAMPTZ NOT NULL DEFAULT NOW()
}

INDEX idx_fiscal_events_chain (tenant_id, terminal_id, sequence_number)
INDEX idx_fiscal_events_business_date (tenant_id, business_date)
INDEX idx_fiscal_events_partner (tenant_id, partner_id) WHERE partner_id IS NOT NULL
INDEX idx_fiscal_events_reference (reference_event_id) WHERE reference_event_id IS NOT NULL
INDEX idx_fiscal_events_sync (sync_status) WHERE sync_status != 'verified'

UNIQUE (tenant_id, terminal_id, sequence_number)
CHECK (sequence_number > 0)
CHECK (event_type IN (...exhaustive list per §3))
```

**Immutability enforcement:** DB-level trigger `fiscal_events_immutability` rejects any UPDATE or DELETE on fiscal_events rows. Compensating events (a new INSERT with `reference_event_id`) are the only correction mechanism.

**Local SQLite mirror** on POS: `fiscal_events_local` with the same schema minus server-only columns (sync_status defaults to `pending`). Outbox sync flushes to server.

### 2.3 `FiscalEventEngine` service

```
interface FiscalEventEngine {
  // Append a new event to the chain.
  // Locks terminal chain head, computes hash via SignatureProvider, persists.
  // Atomic with the business transaction that triggered it.
  append(EventEmissionRequest $request, SystemContext $context): FiscalEvent;

  // Emit a compensating event referencing an original.
  // Same semantics as append but enforces reference_event_id is set
  // and the compensation type is allowed for the original event_type.
  compensate(UUID $originalEventId, CompensatingEventRequest $request, SystemContext $context): FiscalEvent;

  // Walk the chain for verification.
  verifyChain(UUID $tenantId, UUID $terminalId, ?int $fromSequence = null): VerificationResult;

  // Project events to printable FiscalDocument.
  project(UUID $eventId): FiscalDocument;
}
```

`append()` runs inside the calling DB transaction. If the caller's transaction rolls back, the event is never persisted — eliminating the round-1 B1 concern about events firing after commit and silently failing.

`compensate()` enforces a static map of allowed compensations: ACCOUNT_PAYMENT → ACCOUNT_REFUND; ACCOUNT_CREDIT_ISSUE → ACCOUNT_CREDIT_USAGE (or its own ACCOUNT_CREDIT_ISSUE_REVERSAL); SALE_RECEIPT_BRIDGE → (no direct compensation; legacy refund flow continues to emit through pos_receipts and bridge a new SALE_RECEIPT_BRIDGE).

### 2.4 `SignatureProviderInterface`

```
interface SignatureProvider {
  // Stable identifier for this version (e.g. 'v2', 'v3', 'nf525-lne-v1').
  version(): int|string;

  // Serialize the event's hashable subset to canonical bytes.
  // Implementations decide which fields are bound (event_type, payload,
  // partner identity snapshot, totals, timestamp, sequence number, etc.).
  serialize(FiscalEvent $event): bytes;

  // Compute the current event hash given previous_hash + serialized payload.
  hash(bytes $previousHash, bytes $serialized): bytes;

  // Verify a single event row matches its declared hash.
  verify(FiscalEvent $event): bool;
}

class V2SignatureProvider implements SignatureProvider {
  // Legacy compatibility for SALE_RECEIPT_BRIDGE referencing pos_receipts
  // whose original chain ran the current ReceiptHashService payload:
  //   receipt_number | posted_at | total | currency | vat_breakdown_hash | payment_methods_hash
  // The bridge event wraps that hash + signed metadata.
}

class V3SignatureProvider implements SignatureProvider {
  // Canonical v2.0 payload for all new event types.
  // Binds:
  //   event_type, event_version, signature_version
  //   tenant_id, company_id, terminal_id, operator_id
  //   sequence_number, event_timestamp (YYYYMMDDTHHmmssZ), business_date
  //   reference_event_id (if present)
  //   partner_identity_snapshot (canonical JSON)
  //   payload (canonical JSON, see §3)
  //   totals, vat_breakdown, payment_breakdown (canonical JSON)
  // Serialization: UTF-8 canonical JSON (RFC 8785 / JCS), pipe-joined where the
  //   payload-already-JSON convention applies. Monetary values are integer cents.
  // Hash: SHA-256(previous_hash_bytes || serialized_bytes).
  // Genesis: previous_hash = terminal.genesis_seed (existing 256-bit hex on Terminal).
}
```

V2 stays in place for any legacy chain walks against pre-v2.0 pos_receipts rows; the existing ReceiptHashService remains the authority for pos_receipts hash computation. V3 is the canonical version for all events in this spec.

The interface allows future country-specific providers (e.g., `NF525LneV1SignatureProvider`, `ZatcaPhase2SignatureProvider`, `TtnV1SignatureProvider`) to ship without changing event ingestion code — only the terminal's `signature_version` setting changes.

**Per-terminal signature_version cutover:** terminals carry `fiscal_schema_version` (existing column). When a terminal's version flips to V3, all new events on that terminal use V3SignatureProvider. Old events remain verifiable with their original provider via `signature_version` column lookup.

### 2.5 Hash chain semantics

- **Scope:** per (tenant_id, terminal_id). Each terminal has its own chain head.
- **Genesis:** the terminal's `genesis_seed` (existing 256-bit hex) is the `previous_hash` of the first event on that terminal. Documented per terminal at creation; signed by server when the terminal is provisioned.
- **Chain progression:** `current_hash = SignatureProvider.hash(previous_hash, serialized_event)`.
- **Sequence:** `sequence_number` is incremented per (tenant_id, terminal_id) under pessimistic row lock on `terminals` row during `FiscalEventEngine::append()`.
- **Year boundary:** sequence does NOT reset at year boundary. The chain is continuous across fiscal years. Year is recoverable from `business_date`. This is a simpler invariant than v1.1's year-reset proposal and matches the BSI TR-03153 model for the German TSE.

### 2.6 Compensating events policy

For each event type, the spec declares which compensations are valid and the validation rules:

| Original event | Allowed compensations | Validation |
|---|---|---|
| ACCOUNT_PAYMENT | ACCOUNT_REFUND | `amount_refunded ≤ original.amount`; if partial, original remains in "partially_refunded" projection state |
| ACCOUNT_CREDIT_ISSUE | ACCOUNT_CREDIT_USAGE, ACCOUNT_CREDIT_REVERSAL | sum(usages + reversals) ≤ original.amount |
| DEPOSIT_RECEIPT | DEPOSIT_REFUND, DEPOSIT_CONSUMED_IN_SALE | linked sale must reference deposit |
| ACCOUNT_STATUS_CHANGED | (none — replace by emitting a new ACCOUNT_STATUS_CHANGED with new state) | append-only history |
| OVERRIDE_AUDITED | (none — record-only) | append-only |
| CASH_OUT_EXECUTED | CASH_OUT_REVERSED | rare, requires manager override audit chain |
| SALE_RECEIPT_BRIDGE | (none directly — pos_receipts refund emits its own bridge) | bridge-only |

### 2.7 Projection rules

`FiscalEventEngine::project(eventId)` returns a `FiscalDocument` — the printable representation. Projection is **pure** (deterministic given the event and the projection-version), **versioned** (`projection_version` stamped on the FiscalDocument), and **idempotent** (re-projecting the same event always yields the same FiscalDocument bytes, modulo non-fiscal fields like print timestamps).

Projections are read-side; they never mutate the source event.

---

## 3. Event types in scope

For each event type: event_version, what it represents, payload schema, totals/vat/payment_breakdown applicability, allowed compensations, printable mapping.

### 3.1 ACCOUNT_PAYMENT (event_version 1)

**What:** customer pays toward existing receivables. No goods sold.

**Payload:**
```json
{
  "amount_cents": 10000,
  "currency": "TND",
  "payment_lines": [
    { "method": "cash", "method_code": "CASH", "amount_cents": 10000, "repository_id": "uuid" }
  ],
  "allocation_intent": {
    "strategy": "fifo|due_date|manual",
    "targets": [ { "invoice_id": "uuid", "amount_cents": 4000 }, ... ],
    "notes": null
  }
}
```

`totals.gross_cents = totals.net_cents = sum(payment_lines.amount_cents)`. `vat_breakdown` is null. `payment_breakdown` mirrors `payload.payment_lines`.

**Compensation:** `ACCOUNT_REFUND` with `reference_event_id = this.id`.

**Printable type:** `ACCOUNT_PAYMENT_RECEIPT` (Reçu d'encaissement).

**Side effects on projection:**
- Treasury `Payment` row created (origin='pos' for POS-emitted; origin='web_admin' for web-emitted; origin='mobile' for future mobile-emitted), linked to `fiscal_events.id` via `payment.fiscal_event_id`.
- `PaymentAllocationService.applyAllocation()` runs against live ledger using system actor.
- Overpayment → GL `CustomerAdvance` (unconditional, no `Auth::user()` dependency).
- Allocation reconciliation: if server allocation differs from `allocation_intent` (e.g., another payment closed an invoice between offline emit and sync), emit a `ACCOUNT_PAYMENT_RECONCILED` audit event referencing this one with the actual allocation result. Printable receipt does NOT claim allocations as authoritative — see §6.

### 3.2 ACCOUNT_REFUND (event_version 1)

**What:** refund of a previous ACCOUNT_PAYMENT.

**Payload:**
```json
{
  "amount_cents": 5000,
  "currency": "TND",
  "refund_method_lines": [
    { "method": "cash", "amount_cents": 5000 }
  ],
  "reason": "duplicate payment"
}
```

Must reference `ACCOUNT_PAYMENT` event via `reference_event_id`. Validation: `amount_cents ≤ original.amount_cents - sum(prior_refunds.amount_cents)`.

**Compensation:** none (this is itself a compensation).

**Printable type:** `ACCOUNT_REFUND_RECEIPT`.

**Cash-out flag:** if `refund_method_lines` contains a cash refund, triggers `CashOutPolicy` evaluation — see §7 and §10.

### 3.3 ACCOUNT_CREDIT_ISSUE (event_version 1)

**What:** issue store credit / wallet balance / loyalty credit to a customer (e.g., refund-as-credit, promotional grant).

**Payload:**
```json
{
  "amount_cents": 2000,
  "currency": "TND",
  "credit_type": "refund_to_credit|promotional|loyalty",
  "expires_at": "2027-05-13T00:00:00Z",
  "source": { "kind": "refund_receipt", "reference": "pos_receipts.id|..." }
}
```

**Compensations:** `ACCOUNT_CREDIT_USAGE`, `ACCOUNT_CREDIT_REVERSAL`.

**Printable type:** `STORE_CREDIT_RECEIPT`.

### 3.4 ACCOUNT_CREDIT_USAGE (event_version 1)

**What:** customer redeems store credit toward a sale or balance settlement.

**Payload:**
```json
{
  "amount_cents": 500,
  "currency": "TND",
  "used_in": { "kind": "sale_receipt|account_payment", "reference": "..." },
  "credit_sources": [
    { "issue_event_id": "uuid", "amount_cents": 500 }
  ]
}
```

Sum of `credit_sources.amount_cents` must equal `amount_cents`. Each referenced issue must have remaining balance ≥ requested.

**Compensation:** `ACCOUNT_CREDIT_REVERSAL` (e.g., when the parent sale is voided).

**Printable type:** `STORE_CREDIT_USAGE_RECEIPT`.

### 3.5 DEPOSIT_RECEIPT (event_version 1)

**What:** customer pays a deposit toward a specific future sale (acompte / arrhes / down payment).

**Payload:**
```json
{
  "amount_cents": 30000,
  "currency": "TND",
  "payment_lines": [ {"method": "cash", "amount_cents": 30000, "repository_id": "uuid"} ],
  "deposit_kind": "acompte|arrhes",
  "linked_to": { "kind": "sales_order|quote|future_sale", "reference": "uuid|null", "description": "Special-order part for X" },
  "expected_completion_date": "2026-06-01",
  "vat_treatment": {
    "vat_exigible_on_collection": true,
    "vat_rate_pct": 19.00,
    "base_ht_cents": 25210,
    "vat_amount_cents": 4790
  }
}
```

**`vat_exigible_on_collection`:** when true, the deposit triggers VAT (Art. 269 CGI or successor in France; mirror in Tunisia). `vat_breakdown` is populated. Projection produces a `DEPOSIT_RECEIPT` printable AND, in jurisdictions that require it, a `FACTURE_ACOMPTE` printable with the regulated mentions.

**Refundability:** carried in payload but enforced by `deposit_kind` (acompte = non-refundable; arrhes = refundable per Art. 1590 Code civil, default consumer presumption).

**Compensations:** `DEPOSIT_REFUND`, `DEPOSIT_CONSUMED_IN_SALE`.

**Printable types:** `DEPOSIT_RECEIPT` for the customer-facing copy; `FACTURE_ACOMPTE` for the fiscal document where required by jurisdiction.

### 3.6 ACCOUNT_STATUS_CHANGED (event_version 1)

**What:** transition of a customer's `account_status` (Active / Frozen / Closed / PendingKyc).

**Payload:**
```json
{
  "partner_id": "uuid",
  "previous_status": "PendingKyc",
  "new_status": "Active",
  "reason": "KYC approved by manager X",
  "credit_limit_cents": 50000,
  "min_deposit_pct": 30.00
}
```

`partner_identity_snapshot` captures the partner's identity at the moment of state change (immutable). `partner_id` FK may later be rewritten by identity-map merge; the snapshot in the event is permanent.

**Compensation:** none — emit a new `ACCOUNT_STATUS_CHANGED` to reverse.

**Printable type:** none (internal audit event). Materialized in the admin "Account history" view.

### 3.7 OVERRIDE_AUDITED (event_version 1)

**What:** record of an override decision (min-deposit waived, credit limit exceeded, AML cap acknowledged, etc.).

**Payload:**
```json
{
  "override_type": "below_min_deposit|over_credit_limit|over_aml_cap|cash_out_refund_credit|cash_out_drawer_payout|cash_out_large_change|enable_account_forced_kyc",
  "actor_user_id": "uuid",
  "approver_user_id": "uuid",
  "channel": "local_pin|remote_push|accountant_override",
  "context": {
    "sale_id_or_event_id": "uuid",
    "amount_cents": 10000,
    "gap_cents": 3000,
    "jurisdiction": "TN",
    "policy_version": 3,
    "permission_snapshot_version": 7,
    "sale_total_cents": 10000,
    "change_ratio_pct": null
  },
  "reason_text": "Loyal customer, paying balance Friday"
}
```

Required typed fields are promoted out of `context` for schema enforcement (see §7.3 cash_out_policy):
- `amount_cents`
- `gap_cents` (where applicable)
- `policy_version`
- `permission_snapshot_version`

**Compensation:** none — append-only.

**Printable type:** none (internal audit event).

### 3.8 CASH_OUT_EXECUTED (event_version 1)

**What:** record of cash leaving the till — refund-as-cash, drawer payout, large-change-over-threshold.

**Payload:**
```json
{
  "amount_cents": 50000,
  "currency": "TND",
  "operation": "customer_credit_refund|drawer_payout|large_change_on_overpayment|sale_refund_cash",
  "destination": { "kind": "customer|safe|petty_cash|other", "reference": "..." },
  "linked_event_id": "uuid|null",
  "override_event_id": "uuid|null",
  "policy_version": 3,
  "threshold_id_used": "uuid|null"
}
```

When `operation = customer_credit_refund`, must reference the originating `ACCOUNT_CREDIT_ISSUE` or `ACCOUNT_PAYMENT`. When `operation = large_change_on_overpayment`, must reference the parent `SALE_RECEIPT_BRIDGE` event and include `change_ratio_pct` in payload.

**Compensation:** `CASH_OUT_REVERSED` (rare; requires its own override).

**Printable type:** `CASH_OUT_SLIP` (per doc-2 §20).

### 3.9 SALE_RECEIPT_BRIDGE (event_version 1)

**What:** bridge event emitted for every finalized POS Receipt (the existing pos_receipts flow). Preserves chain continuity in fiscal_events without migrating the receipt itself.

**Payload:**
```json
{
  "pos_receipt_id": "uuid",
  "pos_receipt_number": "OTO/AVB/POS01/2026/000123",
  "pos_receipt_hash": "<existing_v2_hash_bytes_hex>",
  "totals_cents": { "gross": 10000, "net": 8403, "vat": 1597 },
  "vat_breakdown": [...],
  "payment_breakdown": [...],
  "partner_id": "uuid|null",
  "partner_identity_snapshot": { "name": "Aymen Khlifa", "phone_e164": "+216 55123456", "national_id": null }
}
```

Emitted by `ReceiptFinalizationService` after the existing `pos_receipts` row is finalized and ReceiptHashService has computed its V2 hash. The bridge event's signature_version is V3; its payload references the V2-hashed pos_receipts row.

**Compensation:** none. Refunds of the underlying SALE go through the existing refund flow (which emits its own pos_receipts row + new SALE_RECEIPT_BRIDGE).

**Printable type:** none directly (the underlying pos_receipts already has its printable).

**Why this matters:** the fiscal_events chain has 100% coverage of fiscal-relevant operations from day 1 of v2.0 — even though SALE_RECEIPT itself stays in pos_receipts for now. When the future spec migrates SALE_RECEIPT to fiscal_events natively, the bridge events provide a clean audit trail of "this is the moment we cut over."

### 3.10 ACCOUNT_PAYMENT_RECONCILED (event_version 1)

**What:** server-side reconciliation note after an offline ACCOUNT_PAYMENT syncs and the server's authoritative `PaymentAllocationService` allocation differs from the offline `allocation_intent`.

**Payload:**
```json
{
  "original_event_id": "uuid",
  "actual_allocations": [ { "invoice_id": "uuid", "amount_cents": 4000 }, ... ],
  "excess_to_advance_cents": 1000,
  "reconciliation_reason": "invoice_closed_concurrently|allocation_strategy_overridden|other"
}
```

**Compensation:** none.

**Printable type:** none directly. If `excess_to_advance_cents > 0` or the actual allocation materially differs, the projection layer may emit an `ACCOUNT_PAYMENT_RECEIPT_AMENDED` printable for the customer (mailed/emailed; not reprinted at the till offline).

**Why this matters:** addresses round-2 R8. The original offline receipt is not invalidated, but the reconciliation event is durable, auditable, and projects to a customer-facing amendment if material.

### 3.11 IDENTITY_ALIAS_RECONCILED (event_version 1)

**What:** server-side reconciliation when an offline-created Partner (temp_uuid) is merged with an existing canonical Partner.

**Payload:**
```json
{
  "temp_uuid": "uuid",
  "canonical_uuid": "uuid",
  "scoring_basis": ["national_id_exact_match", "phone_normalized_exact", "name_fuzzy:0.85"],
  "decision": "auto_merge|manual_review_queued|rejected_distinct"
}
```

Updates `partner_aliases` table for downstream FK resolution. **Does not mutate prior fiscal events**: those events retain `partner_id = temp_uuid` and the immutable `partner_identity_snapshot`. Lookup follows the alias chain.

**Compensation:** none (manual unmerge would be `IDENTITY_ALIAS_UNRECONCILED` — out of scope; rare).

**Printable type:** none (internal).

### 3.12 Out-of-scope events (named for engine compatibility)

The engine accepts these event_type values reservation-only; they're emitted by future spec work and validated when the corresponding implementation lands:

- `SALE_RECEIPT` (full migration from pos_receipts — future)
- `SALE_VOID`, `SALE_CORRECTION` (compensations to SALE_RECEIPT — future)
- `REFUND_RECEIPT`, `PARTIAL_REFUND`, `RETURN_WITHOUT_RECEIPT` (refund-flow migration — future)
- `OPENING_FLOAT`, `CASH_IN`, `CASH_OUT`, `SAFE_DROP`, `CASH_CORRECTION` (cash-drawer migration — future)
- `SESSION_OPEN`, `SESSION_CLOSE`, `X_REPORT`, `Z_REPORT` (session-flow migration — future)
- `RECEIPT_REPRINT`, `FAILED_LOGIN`, `OFFLINE_MODE_ENTERED`, `OFFLINE_MODE_EXITED` (audit-flow migration — future)
- `INVOICE_POSTED`, `DELIVERY_NOTE_CONFIRMED`, `INVOICE_CLOSED_WITH_TOLERANCE`, `LOYALTY_POINTS_EARNED|REDEEMED|EXPIRED|ADJUSTED` (B2B + loyalty migration — future, when those flows enter fiscal-chain scope)

Out of scope **entirely**:
- `SUSPICIOUS_OPERATION` and any pattern-derived flag. These are ML-downstream of the fiscal stream, materialized in TimescaleDB analytic tables, not in the fiscal event chain. The fiscal chain is factual.

---

## 4. Printable document projection layer

### 4.1 `FiscalDocument` model

```
fiscal_documents {
  id                       UUID PK
  fiscal_event_id          UUID NOT NULL FK
  document_type            VARCHAR(64) NOT NULL  -- printable type per §4.2
  template_name            VARCHAR(128) NOT NULL -- e.g., "account_payment_receipt.fr-FR.v1"
  projection_version       INT NOT NULL          -- bump when projection logic changes
  rendered_payload         JSONB NOT NULL        -- the structured data the template renders
  rendered_at              TIMESTAMPTZ NOT NULL
  reprint_count            INT NOT NULL DEFAULT 0
  last_reprinted_at        TIMESTAMPTZ NULL
}

UNIQUE (fiscal_event_id, document_type, projection_version)
INDEX idx_fiscal_documents_event (fiscal_event_id)
```

A single fiscal event may project to one or more `FiscalDocument` rows depending on jurisdiction (e.g., DEPOSIT_RECEIPT in France projects to both `DEPOSIT_RECEIPT` and `FACTURE_ACOMPTE` printables).

Reprints increment `reprint_count`, update `last_reprinted_at`, and emit a separate `RECEIPT_REPRINT` event (future spec; for now logged in `audit_events`).

### 4.2 Printable type taxonomy (in this spec's scope)

| Printable type | Source event | Jurisdictions | Fiscal? |
|---|---|---|---|
| `ACCOUNT_PAYMENT_RECEIPT` | ACCOUNT_PAYMENT | all | yes |
| `ACCOUNT_PAYMENT_RECEIPT_AMENDED` | ACCOUNT_PAYMENT + ACCOUNT_PAYMENT_RECONCILED | all (when material difference) | yes (amendment ref) |
| `ACCOUNT_REFUND_RECEIPT` | ACCOUNT_REFUND | all | yes |
| `STORE_CREDIT_RECEIPT` | ACCOUNT_CREDIT_ISSUE | all | yes |
| `STORE_CREDIT_USAGE_RECEIPT` | ACCOUNT_CREDIT_USAGE | all | yes |
| `DEPOSIT_RECEIPT` | DEPOSIT_RECEIPT | all | yes |
| `FACTURE_ACOMPTE` | DEPOSIT_RECEIPT | FR (and successor jurisdictions per Art. 289 CGI / Code des impositions) | yes — full mentions |
| `CASH_OUT_SLIP` | CASH_OUT_EXECUTED | all | audit |

Printable types NOT in scope (legacy or future): `SALE_RECEIPT`, `REFUND_RECEIPT`, `OPENING_FLOAT_SLIP`, `CASH_IN_SLIP`, `SAFE_DROP_SLIP`, `X_REPORT`, `Z_REPORT`, `REPRINT_COPY`.

### 4.3 Per-jurisdiction template selection

Each printable type maps to one or more `template_name` values keyed by jurisdiction + version:

```
account_payment_receipt.tn-TN.v1
account_payment_receipt.fr-FR.v1
account_payment_receipt.dz-DZ.v1
...
deposit_receipt.fr-FR.v1
facture_acompte.fr-FR.v1     -- emitted alongside deposit_receipt in FR
```

Template selection rule (server-side, executed at projection time):

```
templates_for(event) =
  let jurisdiction = event.company.jurisdiction
  let language = event.partner?.preferred_language ?? jurisdiction.default_language
  return TEMPLATE_REGISTRY[event_type][jurisdiction][language] ++ ADDITIONAL_TEMPLATES[event_type][jurisdiction]

ADDITIONAL_TEMPLATES["DEPOSIT_RECEIPT"]["fr-FR"] = ["facture_acompte.fr-FR.v1"]
```

### 4.4 Receipt content rules

**Full fiscal sequence appears verbatim at least once on every printable.** Friendly two-line split is optional decoration.

```
Otospex SAS · Av. Bourguiba · POS01
Reçu d'encaissement N° OTO/AVB/POS01/2026/E/000045
                                                 ↑
                                                 full stored sequence
                                                 (also in QR payload + exports)

Client : Aymen Khlifa (+216 55 123 456)
Encaissé : 100,00 TND (Espèces)
[...]
```

**Encaissement allocation display rule (addresses round-2 R8):**

The offline-emitted `ACCOUNT_PAYMENT_RECEIPT` shows:
- Amount tendered (this is fact).
- Customer balance **before** this payment, marked "Solde au moment du paiement, au HH:MM" (as observed locally, may have changed).
- Customer balance **after** this payment as a local estimate, marked "Estimation locale".

It does NOT claim a definitive allocation per-invoice. Allocation per-invoice is shown ONLY on the server-projected `ACCOUNT_PAYMENT_RECEIPT_AMENDED` document, emitted post-sync, with the actual server allocations.

Customer-facing implication: the till receipt is durable and never wrong (only conservative); the authoritative allocation receipt comes after sync, electronically, or as a re-print on next visit.

---

## 5. Customer management

### 5.1 Customer entity

`Partner` already exists with `category` (`Individual | Business`), `receivable_balance`, `credit_balance`, `balance_updated_at`, `credit_limit`, `payment_terms_days`. v2.0 adds:

```
partner.account_policy {
  account_status        ENUM(Active, Frozen, Closed, PendingKyc) NOT NULL DEFAULT 'PendingKyc'
  min_deposit_pct       decimal?    -- NULL = use tenant default
  min_deposit_amount    Money?      -- placeholder, NULL today, future layaway-style floor
  account_notes         text?
}
```

`credit_used` is **computed** through `PartnerBalanceService`, not stored. All credit-limit checks use the derived value.

`partner_aliases` table for identity-map reconciliation:

```
partner_aliases {
  temp_uuid      UUID NOT NULL,
  canonical_uuid UUID NOT NULL,
  reconciled_at  TIMESTAMPTZ NOT NULL,
  reconciliation_event_id UUID NOT NULL FK fiscal_events(id),
  PRIMARY KEY (temp_uuid)
}
```

Server-side FK resolution: when reading a fiscal event with `partner_id = temp_uuid`, the read-model follows the alias chain to the canonical. The event itself is never mutated.

### 5.2 Customer search

Server-side (web admin): direct query with combined indexes on phone (normalized E.164), email, loyalty card, national_id, name.

POS (offline): SQLite mirror with same indexes. Normalized E.164 phone column populated via libphonenumber (cross-compiled WASM or native) at terminal startup; mirror refresh recomputes.

Search input is matched against any of the indexed fields. Results show name, phone, balance badge (red if owed; green if credit), `account_status` badge, partner_kind icon (individual vs business).

### 5.3 Customer create

Web admin: server-assigned canonical UUID; immediate persistence.

POS (online or offline): local SQLite write with `temp_uuid` (UUIDv4). Outbox queues a `CustomerCreated` (not a fiscal event — purely operational) outbound envelope. On sync, server runs dedup (§5.4); response is either "accepted as canonical" (canonical_uuid = temp_uuid) or "merge directive" (emit `IDENTITY_ALIAS_RECONCILED` event; populate `partner_aliases`).

**Minimum required fields per trigger:**

| Trigger | Required fields |
|---|---|
| Ordinary at-till create (no AML) | name + (phone OR email) |
| AML threshold crossed (jurisdiction-dependent) | name + phone (E.164) + national_id (format-validated locally via `TaxIdValidationService` ported to POS) |
| B2B charge-to-account | name + tax_id (format-validated) + address + payment_terms_days |

POS UI enforces the minimum field set per trigger before allowing fiscal seal (Encaissement or charge-to-account close-out). If AML threshold breached and `national_id` format is invalid for the jurisdiction, the operation is blocked offline (cashier sees "Validation impossible offline; please connect" — see §10 AML state machine).

### 5.4 Server-side dedup (multi-factor scoring)

When an offline-created Partner syncs to server:

1. **High-confidence merge** (auto-execute):
   - `national_id` exact match (validated format) AND name normalized similarity ≥ 0.7.
2. **Mid-confidence** (auto-merge if name similarity high; else manual review):
   - `phone_e164 + country` exact match AND name normalized similarity ≥ 0.85.
3. **Low-confidence** (flag for manual review; temp_uuid becomes canonical pro tem):
   - Phone match without country normalization OR name+address fuzzy ≥ 0.7.
4. **Distinct** (accept as new): no match.

Decisions emitted as `IDENTITY_ALIAS_RECONCILED` events. Manual review queue in web admin shows pending merges with side-by-side comparison; manual decision emits the event.

### 5.5 Identity-map propagation (sealed snapshots)

**Critical rule:** rewriting live `partner_id` FK on existing fiscal events is FORBIDDEN. Events are immutable.

Read-time alias resolution:
- Read a fiscal event by ID → returns event as-is, including `partner_id = temp_uuid`.
- Read partner balance / statement → join via `partner_aliases` to canonical.
- Print or display customer name → use `partner_identity_snapshot.name` from the event itself (the printed receipt name matches the historic event name).

Statements + summaries are produced by walking events and aggregating by canonical_uuid (via alias chain). The temp_uuid never disappears; it remains a permanent alias in `partner_aliases`.

This addresses round-2 R5: there's no "two identities" problem because the event's snapshot is fact, the alias chain is fact, and the live partner is fact — they cohere.

### 5.6 AML state machine

Per-(jurisdiction, operation) state machine evaluating the cash tender. State is determined by jurisdiction config + cumulative cash tendered in the current transaction:

| State | Definition (TN example: cap 5000 TND) | Action |
|---|---|---|
| `below_id_threshold` | cash tender < soft threshold (e.g., 1000 TND TN, €500 FR) | Anonymous transaction permitted; no Partner required. |
| `above_id_threshold` | cash tender ≥ soft threshold AND < hard cap | Partner attachment required. POS prompts mid-transaction promote-to-identified flow. If offline and no Partner attached and `national_id` not validated locally, blocked; cashier must come online or attach an already-identified Partner. |
| `above_hard_cap` | cash tender ≥ hard cap (5000 TND TN, €1000 FR legal cap) | In TN: warning + Partner+ID identification still required (fiscal-penalty regime). In FR: tender is illegal — POS refuses cash above €1000 between pro↔consumer. Cashier must split tender or use non-cash. |

Configuration (per-jurisdiction defaults seeded; per-tenant overridable):

```
aml_jurisdiction_policy {
  jurisdiction_code  VARCHAR(8) PK
  soft_id_threshold_cents  BIGINT  -- e.g., 100000 (1000 TND) or 50000 (€500)
  hard_legal_cap_cents     BIGINT  -- e.g., 500000 (5000 TND) or 100000 (€1000)
  hard_cap_treatment       ENUM('refuse_cash', 'warn_with_id', ...)
  policy_version           INT
}
```

POS evaluates per-tender: before drawer open and before fiscal seal, runs `AmlEvaluator(jurisdiction, cumulative_cash_tender)` which returns `{state, required_actions}`. If `required_actions` is non-empty and unsatisfied, fiscal seal is blocked.

---

## 6. Account policy + rules engine

### 6.1 Three-tier rules schema

**Tier 1 — Tenant default (`tenant_account_policy`):**
```
tenant_account_policy {
  tenant_id                    UUID PK
  charge_to_account_enabled    bool
  default_min_deposit_pct      decimal
  default_credit_limit_cents   BIGINT
  default_payment_terms_days   int
}
```

**Tier 2 — Per-Partner override (`partner.account_policy` columns, §5.1):**
NULL columns inherit tenant defaults.

**Tier 3 — Per-transaction override:**
Materialized as `OVERRIDE_AUDITED` fiscal event. The override_audit table (already conceived in v1.1) becomes a **projection** of OVERRIDE_AUDITED events for query convenience:

```
override_audit_projection (projected from OVERRIDE_AUDITED events) {
  id, occurred_at, fiscal_event_id, actor_user_id, approver_user_id?, override_type,
  channel, amount_cents, gap_cents, jurisdiction, policy_version,
  permission_snapshot_version, reason_text
}
```

Rebuilt from the event chain on demand (or maintained incrementally via projector).

### 6.2 Approval primitive — `ApprovalService`

```
interface ApprovalService {
  request(override_type, context): ApprovalRequest
  approve(request_id, approver_user_id, channel, channel_token): ApprovalResult
}

ApprovalChannel:
  LocalPinChannel       (offline-capable; §6.5)
  RemotePushChannel     (online-only; stub interface; future spec)
  AccountantOverrideChannel  (future)
```

The approval primitive returns a successful approval token. The downstream service (EncaissementService, ChargeToAccountService, CashOutService, etc.) emits `OVERRIDE_AUDITED` referencing the approval before emitting its own primary event.

### 6.3 Normative override mapping table

This is the single source of truth used by the approval endpoint, frontend rendering, seeders, and tests:

```
override_mapping (data table, seeded from spec) {
  override_type           PK
  required_permission     -- Spatie permission key
  allowed_channels        -- array<channel>
  offline_policy          -- ENUM(pin_allowed_offline | push_required_online_only | blocked_offline)
  audit_required_fields   -- array<field>
  translation_key         -- for UI strings
}
```

Seeded values:

| override_type | required_permission | allowed_channels | offline_policy | translation_key |
|---|---|---|---|---|
| `below_min_deposit` | `pos.override.min_deposit` | local_pin (self) | pin_allowed_offline (self-approve) | `pos.overrides.below_min_deposit` |
| `over_credit_limit` | `pos.override.credit_limit` | local_pin, remote_push | pin_allowed_offline | `pos.overrides.over_credit_limit` |
| `over_aml_cap` | `pos.override.aml_cap` | remote_push | push_required_online_only | `pos.overrides.over_aml_cap` |
| `cash_out_refund_credit` | `pos.payment.refund_customer_credit_as_cash` | remote_push | push_required_online_only | `pos.overrides.cash_out_refund_credit` |
| `cash_out_drawer_payout` | `pos.cashout.drawer_payout` | local_pin, remote_push | pin_allowed_offline | `pos.overrides.cash_out_drawer_payout` |
| `cash_out_large_change` | `pos.cashout.large_change_over_threshold` | local_pin, remote_push | pin_allowed_offline | `pos.overrides.cash_out_large_change` |
| `enable_account_forced_kyc` | `pos.customer.enable_account` | local_pin (self) | pin_allowed_offline (self-approve) | `pos.overrides.enable_account_forced_kyc` |

### 6.4 Permission keys

```
pos.customer.search
pos.customer.create
pos.customer.edit
pos.customer.enable_account
pos.customer.change_status
pos.payment.receive_on_account
pos.payment.refund_customer_credit_as_cash
pos.charge_to_account.execute
pos.deposit.collect
pos.override.min_deposit
pos.override.credit_limit
pos.override.aml_cap
pos.cashout.drawer_payout
pos.cashout.large_change_over_threshold
fiscal.events.verify_chain          -- for the verifier command
```

Server-side `ApprovalService.approve()` re-reads §6.3 mapping for the requested `override_type`, finds `required_permission`, and verifies the approver holds it (NOT the previously hardcoded `pos.close_shift_with_variance`).

### 6.5 PIN crypto on actual Tauri stack

Codebase reality (from prior research): no Tauri keyring plugin; existing `~/.izipos_key` AES file key is the local crypto root.

**Spec:**

- **At-rest encryption of PIN hashes:** `LocalPinChannel` reads PIN hashes via SQLite columns encrypted with AES-256-GCM using `izipos_key` as the master. Hashes never stored plaintext.
- **Threat model:** an attacker with filesystem access can read `izipos_key` and decrypt PIN hashes. Mitigations: file permissions 0600; `izipos_key` rotated per-terminal-provisioning; in-flight rotation feasible (rewrap PIN hashes on the new key).
- **Brute-force counter:** per `(approver_user_id, terminal_id)`. 5 failed attempts within 5-minute sliding window → 15-minute lockout. Stored in `pin_attempt_log` SQLite table (encrypted). Failure events queued to outbox for server alerting.
- **Offline TTL of permission snapshots:** signed permission snapshots are valid for 7 days offline. After 7 days, the terminal must reconnect before any PIN approval is accepted; offline ApprovalService returns "snapshot expired".
- **Signed permission snapshots:** server signs `{user_id, permissions[], snapshot_version, effective_at}` with Ed25519. Public key bundled in POS app binary; per-tenant rotation pushes the next-key, with overlap period for in-flight terminals. Signed snapshot stored in encrypted `permission_snapshots` SQLite table.
- **No client-authored PIN hashes for fiscal approvers:** server enforces — if user has any `pos.override.*` permission, the `/pos/auth/sync-pins` endpoint rejects client-submitted hashes for that user. PIN setup uses a server-round-trip flow with proof of identity. Non-fiscal till PINs (operate-terminal-only) can continue to use the existing self-set path.
- **Rotation:** server-authoritative. New hash pushed via signed envelope. Old hash invalidated.
- **Revocation:** revoking `pos.override.X` server-side bumps `snapshot_version`. Old snapshot rejected by offline LocalPinChannel on next approval request.

### 6.6 `ManagerPinController` refactor

Existing `ManagerPinController` endpoint (which hardcodes `pos.close_shift_with_variance`) is deprecated for fiscal overrides. New endpoints:

```
POST /api/pos/approval/request
  body: { override_type, context }
  returns: { request_id, required_permission, allowed_channels, offline_policy }

POST /api/pos/approval/approve
  body: { request_id, approver_user_id, channel, channel_token }
  returns: { approved: bool, fiscal_event_id, override_audit_id }
```

Approve endpoint, when invoked online, emits `OVERRIDE_AUDITED` server-side. When invoked offline (locally on POS), emits to local fiscal_events_local; outbox queues it for server replay on next sync.

The existing shift-variance flow continues to use its own dedicated endpoint with `pos.close_shift_with_variance`. Out of scope for fiscal-override unification.

---

## 7. CashOutPolicy

### 7.1 `cash_out_policy` table

```
cash_out_policy {
  id                        UUID PK
  tenant_id                 UUID NOT NULL
  jurisdiction_code         VARCHAR(8) NOT NULL
  operation_type            ENUM(customer_credit_refund, drawer_payout, large_change, sale_refund_cash) NOT NULL
  threshold_amount_cents    BIGINT NULL                -- absolute amount trigger
  threshold_ratio_pct       DECIMAL(5,2) NULL          -- ratio trigger (e.g., change > 50% of sale)
  allowed_channels          JSONB NOT NULL             -- array<approval_channel>
  offline_policy            ENUM('pin_allowed', 'push_required', 'blocked') NOT NULL
  policy_version            INT NOT NULL
  effective_at              TIMESTAMPTZ NOT NULL
  superseded_at             TIMESTAMPTZ NULL
}

UNIQUE (tenant_id, jurisdiction_code, operation_type, policy_version)
```

Effective policy lookup: `latest non-superseded row for (tenant, jurisdiction, operation_type)`.

### 7.2 Default policies (seed values)

| Tenant | Jurisdiction | Operation | Threshold amount | Threshold ratio | Allowed channels | Offline |
|---|---|---|---|---|---|---|
| default | TN | customer_credit_refund | any | — | remote_push | blocked |
| default | TN | drawer_payout | 100000 cents (1000 TND) | — | local_pin, remote_push | pin_allowed |
| default | TN | large_change | 50000 cents (500 TND) | 50% | local_pin, remote_push | pin_allowed |
| default | FR | customer_credit_refund | any | — | remote_push | blocked |
| default | FR | drawer_payout | 50000 cents (€500) | — | local_pin, remote_push | pin_allowed |
| default | FR | large_change | 20000 cents (€200) | 50% | local_pin, remote_push | pin_allowed |

Thresholds are stored alongside the override; the `OVERRIDE_AUDITED` event records which `policy_version` was applied.

### 7.3 Audit field promotion

`OVERRIDE_AUDITED.payload` and `CASH_OUT_EXECUTED.payload` carry the typed fields directly (not buried in free-form `context_json`):

- `amount_cents` (required)
- `sale_total_cents` (required when operation = large_change)
- `change_ratio_pct` (required when operation = large_change)
- `policy_version` (required)
- `threshold_id_used` (required when policy threshold triggered)
- `permission_snapshot_version` (required)
- `jurisdiction` (required)
- `reason_text` (required)

JSON schema validation on emission: malformed payloads rejected before fiscal seal.

---

## 8. POS↔Server sync transport

### 8.1 Typed envelope

```
SyncEnvelope {
  envelope_id        UUID
  envelope_version   INT
  type               ENUM('FISCAL_EVENT', 'CUSTOMER_CREATED', 'CUSTOMER_UPDATED', 'OUTBOX_HEARTBEAT', ...)
  payload_version    INT
  payload            JSON
  emitted_at         TIMESTAMPTZ
  origin_terminal_id UUID NULL
  origin_user_id     UUID NULL
  idempotency_key    string  -- for FISCAL_EVENT: terminal_id + sequence_number
}
```

Outbox is a SQLite FIFO of envelopes. Server `POST /api/pos/sync/outbox` accepts batches, applies via type registry, idempotent.

Inbound (`GET /api/pos/sync/since?cursor=<ts>`): server pushes deltas including partner mirror updates, account_status changes, `ACCOUNT_PAYMENT_RECONCILED` notifications (for receipt amendments), `IDENTITY_ALIAS_RECONCILED` directives (for local alias resolution).

### 8.2 Idempotency

- Fiscal events: `(terminal_id, sequence_number)` is the idempotency key. Server unique constraint blocks duplicates.
- Customer create: `temp_uuid` is the idempotency key. Duplicate sync replays return the same canonical_uuid.

### 8.3 Server-side fiscal chain verification on ingestion

When the server receives a batch of fiscal events from a terminal:

1. Verify each event's signature with the declared `signature_version` provider.
2. Verify chain linkage: `previous_hash` of event N matches `current_hash` of event N-1 (per the same terminal_id).
3. Reject any event whose chain is broken (returns error to terminal; terminal must repair locally or escalate).
4. On success, write to server `fiscal_events` table; set sync_status='synced'.
5. Run projection asynchronously; printable `FiscalDocument` rows materialized.
6. Set sync_status='verified' after successful projection.

### 8.4 Conflict resolution

Per round-1/2 user direction: server is authoritative. Listed conflicts and policies:

| Conflict | Policy |
|---|---|
| Customer dedup directive vs local temp_uuid | Local resolved via `partner_aliases`. Events keep temp_uuid; reads follow alias. |
| ACCOUNT_PAYMENT allocation differs from server reconciliation | Server emits `ACCOUNT_PAYMENT_RECONCILED` event. Customer-facing receipt amended if material. |
| Account-status flip while POS offline | Server applies on receipt of sync; subsequent OVERRIDE attempts against now-Frozen account fail server-side (the offline charge already happened, but next charge will be blocked). Operator notified on next session start. |
| Duplicate event ingestion | Idempotency key skips re-ingest. |
| Permission snapshot version mismatch | Offline LocalPinChannel rejects approval; cashier sees "permission outdated; reconnect required". |

---

## 9. Migration + bridge strategy

### 9.1 Legacy SALE_RECEIPT path

`pos_receipts` continues to operate exactly as today. `ReceiptCreationService`, `ReceiptHashService`, `ReceiptSyncService`, `ReceiptFinalizationService` are unchanged.

**New:** `ReceiptFinalizationService` additionally calls `FiscalEventEngine::append()` with `event_type = SALE_RECEIPT_BRIDGE` referencing the just-finalized receipt. The bridge event uses `V3SignatureProvider` and chains in `fiscal_events` per-terminal.

Refunds against legacy SALE_RECEIPTs continue through existing refund flow; each refund emits its own pos_receipts row + bridge event.

### 9.2 No backfill

First tenants are new deployments. Existing data is empty for the customer-account features. No backfill required. Migration plan is purely additive:

- New tables: `fiscal_events`, `fiscal_events_local` (SQLite), `fiscal_documents`, `partner_aliases`, `tenant_account_policy`, `aml_jurisdiction_policy`, `cash_out_policy`, `pin_attempt_log` (SQLite, encrypted), `permission_snapshots` (SQLite, encrypted).
- New columns: `partner.account_status`, `partner.min_deposit_pct`, `partner.min_deposit_amount`, `partner.account_notes`, `terminal.fiscal_event_chain_head_hash` (cached), `terminal.fiscal_event_sequence` (cached).
- New permission keys.

`Treasury.Payment.origin` column added forward only. No existing Treasury payments need backfill (first tenants are new). When the future migration of legacy tenants happens (if ever), conditional backfill rule per round-2 R3: `payment_type='pos' → origin='pos'`; web-controller-originated → origin='web_admin'; ambiguous → origin='unknown_legacy'.

### 9.3 Feature flags + gate points

Per-tenant flag `pos_fiscal_event_engine_enabled` (master). Sub-flags:

- `pos_customer_management` (Phase 2 — search/create/edit)
- `pos_on_account_payment` (Phase 2 — ACCOUNT_PAYMENT event)
- `pos_charge_to_account` (Phase 3 — SALE_RECEIPT + ACCOUNT_PAYMENT projection)
- `pos_account_status_workflow` (Phase 4 — ACCOUNT_STATUS_CHANGED, OVERRIDE_AUDITED, CASH_OUT_EXECUTED)
- `pos_deposit_collection` (Phase 5 — DEPOSIT_RECEIPT)

**Gate points (explicit in spec):**

- **Route middleware:** every new POS route checks the relevant flag via `Feature::active()`.
- **POS config sync:** terminal config payload includes active flags; POS UI hides corresponding screens/buttons.
- **Local UI visibility:** React components check flag from local config store; render nothing when disabled.
- **Server command handlers:** every `FiscalEventEngine::append()` call for new event types early-returns with `FeatureDisabledException` when the relevant flag is off.
- **Receipt sync ingestion:** server `OutboxIngestor` rejects (with retry-impossible status) any fiscal event whose event_type's feature flag is disabled for the tenant.
- **Migration seed behavior:** flags default to false; explicit operator action enables per tenant.

---

## 10. Data flow scenarios

### 10.1 Receive on-account payment in POS (offline)

1. Cashier opens Customers tab, searches "+216 55 123 456", taps result.
2. Customer Detail shows balance 245 TND owed (as of last sync 14:23).
3. Cashier taps "Receive Payment", enters 100 TND, picks Cash tender.
4. POS UI shows local FIFO simulation preview for allocation (informational only).
5. Confirm → POS:
   - Increments local terminal sequence_number for the chain.
   - Locks local terminal row.
   - Computes `current_hash = V3SignatureProvider.hash(terminal.last_hash, serialize(event))`.
   - Writes to `fiscal_events_local` with event_type=ACCOUNT_PAYMENT, payload, totals, payment_breakdown, partner_id (temp_uuid if customer was offline-created), partner_identity_snapshot.
   - Atomic with: write to `Treasury.Payment` (origin='pos'), increment terminal counters.
6. POS prints `ACCOUNT_PAYMENT_RECEIPT` showing tendered amount, balance-before (marked "au HH:MM"), local estimate of balance-after.
7. Drawer opens; cash goes in.
8. Outbox queues the SyncEnvelope `{type: FISCAL_EVENT, payload: event}`.
9. On reconnect: server verifies chain, runs `PaymentAllocationService.applyAllocation()` against live ledger using system actor. If concurrent web-admin payment closed an invoice in the meantime, server emits `ACCOUNT_PAYMENT_RECONCILED` event with actual allocations; excess → CustomerAdvance.
10. Customer may receive `ACCOUNT_PAYMENT_RECEIPT_AMENDED` via email/print-on-next-visit if material difference.

### 10.2 Charge-to-account at till (online, with min-deposit override)

1. Cashier scans items, total = 100 TND.
2. Cashier attaches customer (Active, credit_limit=200, current_balance=60, min_deposit_pct=30%).
3. Cashier enters cash tender = 20 TND.
4. POS validates: 20 < 30% × 100 = 30 → below_min_deposit. Override modal opens.
5. Cashier has `pos.override.min_deposit` → enters reason → confirms.
6. POS validates credit: 60 + 80 = 140 ≤ 200 ✓.
7. Confirmation modal: "Will add 80 TND to Aymen's account balance. Confirm?"
8. Confirm:
   - Emits `OVERRIDE_AUDITED(below_min_deposit, ...)` to fiscal_events_local.
   - Emits SALE_RECEIPT via existing flow to pos_receipts (with payment line for 20 TND cash + 80 TND on-account-marker).
   - `ReceiptFinalizationService` emits `SALE_RECEIPT_BRIDGE` to fiscal_events_local.
   - Emits `ACCOUNT_PAYMENT` for the implicit "credit-line draw" with amount_cents = 8000 and `payload.linked_to = {sale_receipt_id: pos_receipts.id}`.
9. GL posting:
   - The bridge layer projects the SALE_RECEIPT_BRIDGE + ACCOUNT_PAYMENT pair into journal entries: Cash 20 debit, AR 80 debit, Revenue 84.75 credit, VAT 15.25 credit.
   - Existing `GeneralLedgerService.createPOSPaymentEntry()` is extended (or wrapped by a new `createPOSPaymentEntryWithAccountTender()`) to handle this composite posting. This is the round-2 R1 fix.
10. Two printables: SALE_RECEIPT (per existing template) showing items + 20 TND paid + 80 TND on account + "Solde après ce ticket: 140 TND". Optional ACCOUNT_PAYMENT_RECEIPT for the on-account portion (configurable per tenant whether printed separately).

### 10.3 Customer create offline with AML trigger

1. Walk-in wants 6000 TND cash purchase.
2. Cumulative cash tender enters AML state machine: `above_hard_cap` (TN: 5000 TND).
3. POS shows AML alert: identification required (national_id format-validated locally).
4. Cashier opens search → "Create new" → enters name + phone + national_id.
5. POS validates national_id format via local `TaxIdValidationService` port. If invalid, blocks fiscal seal: "National ID format invalid; please correct or contact manager."
6. Valid → POS creates local Partner with temp_uuid, account_status=PendingKyc, emits `CustomerCreated` to outbox.
7. Attaches Partner to in-flight sale.
8. Sale closes:
   - SALE_RECEIPT (existing flow) + SALE_RECEIPT_BRIDGE.
   - Bridge event payload includes `partner_id=temp_uuid` and `partner_identity_snapshot={name, phone_e164, national_id}`.
9. On sync: server dedups against existing Partners. High-confidence merge or accept-as-canonical. Emits `IDENTITY_ALIAS_RECONCILED`.

### 10.4 Refund customer credit as cash (online required)

1. Customer has 50 TND credit, wants cash back.
2. Cashier opens Customer Detail → "Refund credit as cash" (visible only with `pos.payment.refund_customer_credit_as_cash` and online).
3. POS calls `ApprovalService.request(cash_out_refund_credit, ...)`. Override mapping (§6.3) says `push_required_online_only`.
4. If offline → modal: "Cette opération nécessite l'approbation d'un manager en ligne. Veuillez vous reconnecter."
5. If online → push notification to manager device. Manager approves with token.
6. Server verifies credit balance is currently 50 TND; emits `OVERRIDE_AUDITED(cash_out_refund_credit, ...)`.
7. POS:
   - Emits `ACCOUNT_CREDIT_USAGE(amount=5000c, used_in={...}, credit_sources=[...])`.
   - Emits `CASH_OUT_EXECUTED(amount=5000c, operation=customer_credit_refund, linked_event_id=<usage_id>, override_event_id=<override_audited_id>, policy_version=3, threshold_id_used=...)`.
8. Cashier hands over cash. Drawer reflects payout. POS prints `CASH_OUT_SLIP`.

### 10.5 Account freeze cross-channel (admin freezes while POS offline)

1. Admin on web freezes Partner X (over 90 days overdue).
2. Server emits `ACCOUNT_STATUS_CHANGED(partner_id=X, previous=Active, new=Frozen, reason=...)` to its terminal chain (or its admin-owned channel — see §11.1).
3. POS is offline. POS's last-known partner cache says Partner X is Active.
4. Cashier attempts charge-to-account against Partner X. POS allows (based on stale local state). Sale closes; SALE_RECEIPT_BRIDGE + ACCOUNT_PAYMENT events emitted offline.
5. POS reconnects. Inbox pull delivers `ACCOUNT_STATUS_CHANGED`. Outbox pushes the offline-emitted events.
6. Server applies status change to live ledger. Subsequent charges against now-Frozen account will fail server-side. The offline charge stays (money was tendered, not reversible).
7. POS UI shows notification banner: "Compte gelé pour client X depuis HH:MM; les opérations futures seront bloquées".

Audit trail: both events (`ACCOUNT_STATUS_CHANGED` and the offline `SALE_RECEIPT_BRIDGE` + `ACCOUNT_PAYMENT`) exist in the chain with their timestamps. The "freeze→offline-charge" sequence is provable from the chain — addressing round-2 R2 without requiring transactional cross-channel chaining.

---

## 11. Server-side admin event chains

### 11.1 Admin-channel terminal

For events that originate on the server (web admin, scheduled jobs, future mobile app) and don't have a physical POS terminal, the chain runs on a per-(tenant, company) **virtual admin terminal** with `is_virtual=true`. Same chain mechanics — sequence_number, previous_hash, current_hash — anchored on a virtual terminal row in `terminals`.

Web-admin-initiated `ACCOUNT_PAYMENT` (B2B or B2C admin-recording-a-cheque) emits on the virtual admin terminal's chain. `ACCOUNT_STATUS_CHANGED` (admin freezes a customer) emits on the same.

### 11.2 Unified admin payments listing

Existing `/treasury/payments` lists Treasury Payment rows. v2.0 adds:

- `payment.origin` column (display as Source).
- `payment.fiscal_event_id` column (link to the fiscal event that emitted this Payment).
- Filter by source (POS / web admin / mobile / api).
- Filter by terminal_id (for POS) or virtual admin terminal (for web/api).

The Treasury Payment row remains the accounting projection; the fiscal_event is the underlying fiscal-chained truth. Both link via `fiscal_event_id`.

### 11.3 Customer detail page enhancements

`PartnerDetailPage` extended:

- Account status badge (Active / Frozen / Closed / PendingKyc) with audit history (projected from ACCOUNT_STATUS_CHANGED events).
- Account policy (credit_limit, min_deposit_pct, payment_terms_days).
- Account notes.
- Aliases (showing temp_uuid → canonical_uuid history if merged).
- Receipts + Payments + Statement tabs (projected from events, joined via alias chain).

---

## 12. Testing strategy

### 12.1 Backend (PHPUnit)

- **FiscalEventEngine unit tests:** `append()`, `compensate()`, `verifyChain()`, `project()` with real DB. Concurrency tests for sequence_number locking under load.
- **SignatureProvider conformance tests:** for each provider implementation, golden-vector tests for canonical serialization, hash computation, chain verification. Tampering tests must fail verification.
- **Per-event-type tests:** payload schema validation, totals/vat/payment_breakdown population, compensating-event allowed mapping enforcement.
- **Projection determinism tests:** project event → assert FiscalDocument bytes match across runs; re-project produces same output.
- **GL extension tests:** charge-to-account flow posts AR debit correctly; standard cash receipts unchanged.
- **PaymentAllocationService tests (refactored):** system actor produces CustomerAdvance entries; offline replay path verified; authenticated web flow unchanged.
- **AML state machine tests:** per-jurisdiction transitions, blocked_offline behavior, online-required behavior.
- **ApprovalService tests:** override mapping enforcement, LocalPinChannel offline TTL, brute-force lockout, snapshot version mismatch handling.
- **Identity-map tests:** alias resolution joins; event partner_identity_snapshot immutability; multi-factor scoring decisions.

### 12.2 Frontend POS (Vitest)

- Customers tab CRUD components.
- Offline-mode tests: outbox queues; staleness badge; PIN lockout UI; push-required-online-only blocks offline.
- Override modal flows per override_type.
- Min-identity-fields-per-trigger enforcement.
- Receipt print rendering: full fiscal sequence appears verbatim once.

### 12.3 Compliance / verifier

- `php artisan fiscal:verify-chain --terminal=...` walks fiscal_events for a terminal, verifies each event's signature and chain linkage, fails on tampering. CI runs on every PR with seeded chain fixtures.
- Drift detection: chain head hash + sequence_number recorded in CI fixtures; PR must update intentional changes.

### 12.4 E2E (manual + scripted)

- Walk-through 10.1 (offline ACCOUNT_PAYMENT) and 10.4 (cash-out refund online-required) verified on a Tauri test build.
- Walk-through 10.5 (admin-freeze-cross-channel) verified by orchestrating offline POS + online admin.
- Walk-through 10.3 (offline customer-create with AML) verified including post-sync IDENTITY_ALIAS_RECONCILED.

---

## 13. Out-of-scope items / future work

Tracked as named follow-ups (not blocked by this spec, not in scope):

1. **Full SALE_RECEIPT migration to fiscal_events** — current bridge mechanism in place; future spec replaces pos_receipts as the source.
2. **Cash drawer events** (OPENING_FLOAT, CASH_IN, CASH_OUT, SAFE_DROP) as fiscal events.
3. **Session events** (SESSION_OPEN, SESSION_CLOSE, X_REPORT, Z_REPORT) as fiscal events with cross-day rollups.
4. **B2B fiscal chaining** — the 9 existing `getHashableData()` stubs wired into the engine via `SignatureProviderInterface` implementations.
5. **NF525 certification submission** — AFNOR reference doc + cert dossier + LNE submission.
6. **Country-specific signature providers** — `NF525LneV1SignatureProvider`, `ZatcaPhase2SignatureProvider`, `TtnV1SignatureProvider`, etc.
7. **ML-downstream pattern detection** — TimescaleDB-fed anomaly detection, fraud-pattern flagging. Emits to a separate `analytic_alerts` store, never to fiscal_events.
8. **Marketplace inbound orders + delivery integration + mobile owner app** — typed envelope reserves transport namespace; each is its own spec.
9. **Body-shop fully-offline workshop** — workshop-specific projections (vehicles, jobs); sync architecture supports it natively.
10. **Auto-freeze cron** for delinquent accounts — manual freeze in MVP.
11. **Layaway-style minimum-deposit fixed-amount floor** — `min_deposit_amount` reserved.
12. **Reprint as fiscal event** (`RECEIPT_REPRINT`) — currently logged in audit_events; future spec moves to fiscal chain.

---

## Appendix A — Authoritative citations

**French fiscal:**
- BOI-TVA-DECLA-30-10-30 §50 (payments in inalterability scope), §140 (chaînage des enregistrements).
- Art. 269 CGI — VAT exigibility on acompte; abrogated 2026-09-01 per Ord. 2025-920. Successor in Code des impositions sur les biens et services (CIBS) to be sourced during France-cert preparation.
- Art. 289 CGI — facture d'acompte obligation (pending successor).
- L.123-22 Code de commerce — 10-year accounting retention.
- Art. 1590 Code civil — arrhes default presumption.

**Tunisian fiscal:**
- Art. 18 Code de la TVA — facture numbering ininterrompue (applies to factures; tickets/encaissements are commercial receipts not subject to gapless rule by the literal text, but per round-2 R7 a Tunisian legal counsel should confirm whether identified charge-to-account sales function as documents in lieu of invoices; conservative default: issue Facture for identified B2B charge-to-account).
- TEIF 2026 obligation (INNORPI / TTN).
- Plan Comptable Tunisien — 411 + 4191.

**NF525 reference (deferred):**
- AFNOR NF525 (paywalled).
- BOI-TVA-DECLA-30-10-30 (BOFiP).

**Event-sourced fiscal architecture (industry):**
- BSI TR-03153 — German TSE explicit event-chained regulation.
- Pat Helland, *Immutability Changes Everything* — accounting as event sourcing.
- Greg Young, CQRS/ES talks.
- ZATCA Phase 2 — document-chained pattern (different from event-chained; engine supports both via SignatureProviderInterface).

**AML:**
- service-public.fr F10999 — France €1000 cap.
- EU AMLR (Council adopted 2024-05-30) — €10000 EU-wide from 2027-07.

**Project memory:**
- `feedback_codex_review_to_file.md` — Codex review output file convention.
- `project_monetary_precision.md` — integer cents / `bcformat` discipline.
- `feedback_cross_app_deprecation_check.md` — cross-app deprecation check.

---

## Appendix B — Decisions log (v1 → v1.1 → v2.0)

| Decision | v1 | v1.1 | v2.0 |
|---|---|---|---|
| Architectural pattern | Receipt-centric with new DocumentType values | Receipt-centric with receipt_type variants + V2 hash | **Event-sourced fiscal ledger** with typed events + SignatureProviderInterface |
| Source of fiscal truth | pos_receipts | pos_receipts | fiscal_events (new); pos_receipts continues for legacy SALE_RECEIPT via bridge events |
| Hash payload | extend V2 with new fields | reuse V2 unchanged | V3 (canonical event-payload binding); V2 stays for legacy SALE_RECEIPT |
| Sequence scope | per (company, year) for Encaissement | per (company, location, terminal, year) | per (tenant, terminal), continuous across years |
| Event chain on server-side | Hybrid event + document chain | Deferred entirely | Fiscal events on fiscal_events table; server is authoritative |
| Server admin payments | virtual terminal chain (v1) / none (v1.1) | none | virtual admin terminal chain per (tenant, company) |
| OnAccountCharge | New DocumentType enum value | POS Receipt with on_account tender line | SALE_RECEIPT_BRIDGE + ACCOUNT_PAYMENT pair; GL composite posting |
| Encaissement | New DocumentType enum value | POS Receipt receipt_type=Encaissement | ACCOUNT_PAYMENT fiscal event with projection to ACCOUNT_PAYMENT_RECEIPT |
| Acompte / Deposit | Reserved namespace, future | Reserved namespace, future | **In scope**: DEPOSIT_RECEIPT event with VAT-on-collection rules in payload |
| Override audit | Reason text + audit row | LocalPin + push fallback matrix | **OVERRIDE_AUDITED fiscal event** with typed audit fields; CashOutPolicy table; immutable chain |
| Cash-out controls | Single permission for credit refund | CashOutPolicy prose | **cash_out_policy table** with versioned thresholds; CASH_OUT_EXECUTED fiscal event |
| PIN crypto | Tauri keyring (nonexistent) | Encrypted PIN cache (handwavy) | AES file key (existing `.izipos_key`) + Ed25519 signed permission snapshots + per-tenant rotation |
| Identity-map | Rewrite live FKs to canonical | Rewrite outbox, leave fiscal snapshots | **Sealed snapshots in event payload** + `partner_aliases` table + read-time alias resolution; no event mutation ever |
| Backfill | First-tenant Tunisia allowed | Same | None required (first tenants are new deployments) |
| Tunisia receipt classification | Per-terminal across all | Per-terminal except Facture/Avoir per-company | Per-(tenant, terminal); legal decision table; conservative default issue Facture for B2B charge-to-account |
| Allocation mismatch | Provisional / definitive split | Receipt stays final, server reroutes silently | **Receipt does not claim allocations as authoritative**; ACCOUNT_PAYMENT_RECONCILED event emits server allocation; optional amended printable |
| Suspicious operation detection | Not addressed | Not addressed | Explicitly **out** of fiscal chain — downstream ML in TimescaleDB |
| Feature flags | Listed | Listed | **Listed with explicit gate points** in service code, route middleware, sync ingestion, UI visibility |
| Override mapping | Implicit | Implicit | **Normative table** as single source of truth (`override_mapping` data table) |

---

**End of spec v2.0.**
