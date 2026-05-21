# POS Customer Accounts — Design Spec v1.1

**Date:** 2026-05-13
**Status:** v1 reviewed adversarially by Codex (3 BLOCKER, 9 P1, 8 P2, 1 P3). v1.1 incorporates findings + owner pushbacks. Awaiting owner review then implementation plan.
**Predecessor:** `2026-05-13-pos-customer-accounts-design.md` (v1, archived for audit)
**Codex review:** `apps/erp/docs/superpowers/reviews/2026-05-13-pos-customer-accounts-codex-review.md`

---

## Changelog v1 → v1.1

Major structural changes (architecture):

1. **Encaissement and on-account-charge are POS Receipt variants, not new DocumentTypes.** The `DocumentType` enum (Quote, SalesOrder, Invoice, CreditNote, DeliveryNote, ReturnNote, Expense) is B2B-only. POS receipts live in `pos_receipts` with their own type. Adds `receipt_type ENUM(Sale, Encaissement, Refund)` on the existing table. Existing `ReceiptHashService` V2 payload handles all three.
2. **Server-side event chain deferred entirely.** Owner decision: events are async by design; first tenants are POS-only; cross-channel chain isn't needed yet. POS local chain (synchronous, in-transaction, per-terminal) is the fiscal proof. Server-side event chain wiring becomes a separate future spec ("audit-chain-hardening") triggered when B2C web/mobile payments become common OR France NF525-cert enters scope.
3. **Per-terminal sequences for Encaissement / on-account-charge.** Owner decision: Art. 18 Code TVA TN gapless applies to factures, not to tickets/encaissements. Multi-terminal per-terminal sequences are legally sufficient. No provisional/definitive split needed.

Substantive additions:

4. **Approval-token protocol** (encrypted per-terminal PIN cache, brute-force counters, TTL, signed permission snapshot, server-authoritative rotation).
5. **Per-override-type fallback policy** (which overrides allow offline PIN, which require online push).
6. **CashOutPolicy** unifying customer-credit-refund, sale-refund, drawer-payout, petty-cash, large-change-on-overpayment. Jurisdiction- and channel-aware.
7. **Multi-factor customer dedup with identity-map table** (replaces naive phone-match).
8. **ManagerPin endpoint refactor** — replace per-permission hardcoded check with override-type-driven approval contract.
9. **i18n namespaces + TypeScript regeneration + decimal-string handling** added to migration checklist.
10. **Legal citations updated** for Art. 269 CGI abrogation (2026-09-01) + successor in Code des impositions; ViDA milestone reference.
11. **First-tenant backfill caveat** for Tunisia day-1 deployment.
12. **Marketplace/delivery/mobile compatibility claims downgraded** to "typed envelope convention only".
13. **Print receipts show full stored fiscal sequence at least once**, with friendly two-line split optional.

Removed:

14. `OnAccountCharge` as a DocumentType enum value. Subsumed into POS Receipt + on_account tender.
15. `credit_used` stored field on Partner. Duplicates existing `receivable_balance - credit_balance`; compute through `PartnerBalanceService`.
16. Event-chain channel keys + virtual web/mobile terminals. Deferred.
17. Provisional/definitive numbering split for offline Encaissement.

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

1. **Customer management in POS** — search, create, edit, mirror sync. Foundation.
2. **Receive on-account payment** — POS Receipt with `receipt_type=Encaissement`, partner attached, payment-only (no goods lines), allocation against open invoices, hash-chained via existing `ReceiptHashService`.
3. **Charge-to-account at checkout** — POS Receipt with normal goods lines + tender split including `payment_type='on_account'` line. The unpaid portion creates an AR receivable. Hash-chained via existing `ReceiptHashService`. Subject to min-deposit + credit-limit rules.
4. **Account policy and rules engine** — per-customer min-deposit %, credit limit, payment terms; tenant defaults; cashier permissions; approval primitive (LocalPin MVP; RemotePush stub).
5. **Compliance posture** — Tunisia day 1 (no NF525 enforcement), France next (cert-architecture-ready), AML thresholds configurable per tenant.
6. **Offline-first behavior** — POS works fully offline for everything in this spec. Risk-asymmetric cash policy (accept-cash always; give-out-cash gated).
7. **Unified payments view in admin** — POS-emitted receipts and web-admin-emitted payments listed together with a `source` discriminator. Both surface through existing Treasury listing; no data-model merge needed.
8. **Forward-additive sync transport** — typed envelope convention; future features get their own designs.

### 1.3 Scope (out, acknowledged)

- **Deposits feature** (acompte / arrhes — payments against a specific future sale): out. Acompte requires VAT-on-collection (Art. 269 CGI / successor), FactureAcompte issuance, GL 4191 + 44571 posting — none of which is in this spec. Namespace reservation only (see §2.3).
- **NF525 certification submission**: out. Architecture compatible; submission is separate effort.
- **V3 hash payload** (richer international payload): out. Encaissement and on-account-charge use existing V2 payload through the existing `ReceiptHashService`.
- **Server-side event chain (cross-channel hash chain)**: out. Deferred to a future "audit-chain-hardening" spec.
- **Marketplace inbound orders, delivery integration, mobile owner app**: out. Each is a separate design.
- **Body-shop fully-offline workshop module**: out. Sync architecture supports it; workshop-specific design is separate.
- **Layaway-style minimum-deposit fixed-amount floor**: out. `min_deposit_amount` column reserved as placeholder.
- **Auto-freeze cron**: out of MVP execution; placeholder + manual freeze in scope.

### 1.4 Phasing (informs implementation plan)

- **Phase 1** — Customer management foundation (search, create, edit, mirror sync, identity-map).
- **Phase 2** — Receive on-account payment (POS Receipt `Encaissement` variant, allocation invocation, existing hash chain).
- **Phase 3** — Charge-to-account at checkout (on_account tender line, GL extension for AR posting, rules engine, override flow).
- **Phase 4** — Account status workflow (Active / Frozen / Closed / PendingKyc), web admin retrofit (origin stamping), unified payments listing.

---

## 2. Compliance posture

### 2.1 Jurisdictions

| Jurisdiction | Status | AML cash cap | Notes |
|---|---|---|---|
| **Tunisia** | Launch day 1 | 5 000 TND fiscal-penalty threshold | NF525 not applicable. Art. 18 gapless rule applies to factures, not encaissements. |
| **France** | Launch next (car body shops; not NF525-cert day 1) | €1 000 hard legal cap | NF525-cert-ready architecture. Art. 269 CGI abrogated 2026-09-01; use successor in Code des impositions thereafter. |
| **Algeria** | Future | 500 000 DZD non-cash settlement | Stamp duty 1%/1.5%/2% to add when in scope. |
| **Morocco** | Future | 20 000 MAD | E-invoicing 2026. |
| **UK** | Future | €10 000 HVD trigger | MLR 2017 HVD registration. |
| **Italy** | Future | €5 000 | SdI + lottery-vs-codice-fiscale mutex when in scope. |
| **EU AMLR** | Effective 2027-07 | €10 000 EU-wide | Add when relevant. |

AML cap is configurable per-tenant, with jurisdiction defaults. Day-1 default: Tunisia.

### 2.2 Document / Receipt type taxonomy

**Two surfaces with distinct fiscal models:**

**B2B / Web admin (Document table, existing — `DocumentType` enum unchanged):**

| Type | Status | Purpose | AR effect |
|---|---|---|---|
| `Invoice` | exists | B2B invoice (TVA, SIREN/MF/ICE on customer) | debit AR on posting |
| `CreditNote` | exists | B2B credit note / refund | credit AR |
| `DeliveryNote`, `ReturnNote`, `Quote`, `SalesOrder`, `PurchaseOrder`, `Expense` | exist | Operational documents | various |

**POS terminal (pos_receipts table, new field):**

| `receipt_type` value | Status | Purpose | Partner attached | Goods lines | Payment lines |
|---|---|---|---|---|---|
| `Sale` | implicit today | Standard cash/card sale | optional | yes | tender(s) totaling sale total |
| `Sale` + `on_account` tender | **new** | Charge-to-account at checkout | required, Active | yes | tender(s) + at least one `payment_type=on_account` line; unpaid portion → AR |
| `Encaissement` | **new** | Pure on-account payment received | required, Active | empty | tender(s) totaling amount received |
| `Refund` | exists | Refund flow (Phase 1 refund-flow) | depends | yes (negative) | tender(s) negative |

This avoids a parallel B2B/POS DocumentType nomenclature collision. POS Receipts retain their own fiscal pipeline (`ReceiptHashService`, `Terminal.current_sequence`, `Terminal.last_hash`); the new variants are additive on the existing table.

### 2.3 Acompte vs Encaissement vs on-account-charge — legal seam

Three distinct concepts. Two are in scope; one (acompte) is reserved as a future feature.

| Concept | What | VAT treatment | GL credit | Doc type | In this spec? |
|---|---|---|---|---|---|
| **Encaissement** (on-account payment) | Customer pays toward already-existing receivables | None (VAT was already declared when underlying invoices issued) | 411 | POS Receipt `receipt_type=Encaissement` | Yes |
| **On-account charge** | Sale partially or fully settled by debit to customer's balance | Normal (VAT on the goods sold) | 411 (for the unpaid portion) + Revenue + VAT | POS Receipt `receipt_type=Sale` + `on_account` tender | Yes |
| **Acompte** (deposit on a specific future sale) | Customer pays toward a specific future contract that hasn't been invoiced yet | **VAT exigible on collection** (Art. 269 CGI / successor) | 4191 + 44571 | `FactureAcompte` (new Document type) | **No — future Deposits spec** |

**Acompte boundary**: namespace reserved on `payment_document_type` discriminator (currently unused on Encaissement records). Future Deposits work will define the discriminator's `'acompte'` value, the GL posting branch, the FactureAcompte document type, the VAT-on-collection rules, the sales-order linkage, and the validation differences. **No compatibility claim is made beyond namespace reservation.**

### 2.4 Fiscal hierarchy and sequence numbering

The product hierarchy is `Tenant → Company → Location → Terminal`. A **Company** is the legal fiscal entity (matricule fiscal in Tunisia, SIREN in France).

| Document | Counter scope | Stored format | Example |
|---|---|---|---|
| POS Receipt (Sale / Encaissement / Refund) | (company, location, terminal, year) | `<co>/<loc>/<term>/<YYYY>/<NNNNNN>` | `OTO/AVB/POS01/2026/000123` |
| `Facture` (B2B) | (company, year) | `<co>/F/<YYYY>/<NNNNNN>` | `OTO/F/2026/000045` |
| `Avoir` (B2B credit note) | (company, year) | `<co>/A/<YYYY>/<NNNNNN>` | — |
| Z-closure (POS) | (company, location, terminal, year) | `<co>/<loc>/<term>/Z/<YYYY>/<NNNN>` | — |
| `FactureAcompte` (future) | (company, year) | `<co>/FA/<YYYY>/<NNNNNN>` | reserved |

**Why per-terminal for POS Receipts (incl. Encaissement and on-account-charge):** Tunisian Art. 18 Code TVA gapless requirement applies to factures, not to tickets de caisse or receipts. NF525 chain convention is per-register. Multi-terminal each with its own gapless sequence is legally sufficient and operationally simple (no cross-terminal locking).

**Why per-company for Facture and Avoir:** these are B2B fiscal documents subject to Art. 18 gapless across the legal entity, regardless of issuance terminal. Web-admin originated, no terminal context.

**Print format on receipts (full sequence + friendly split):**

```
Otospex SAS · Av. Bourguiba · POS01
Reçu N° OTO/AVB/POS01/2026/000123                  ← full stored sequence printed once
Ticket #000123                                       ← short version optional for human readability
```

The full stored fiscal sequence appears verbatim at least once on the customer-facing receipt, in QR payloads, in exports, and in audit trail. The short version is decorative.

### 2.5 AML mid-transaction promote-to-identified

A walk-in cash sale may cross the local AML cap mid-tender. The POS supports attaching a Partner (existing or newly-created inline) to an in-flight anonymous sale before close-out. One fiscal hash event at close, no void-and-rekey. Same UI primitive serves two triggers: customer-requested ("can I have a facture in my name?") and legally-forced (cash exceeds AML cap).

For an in-flight POS Receipt:

1. Cashier opens the inline Search modal on the till.
2. Selects existing Partner OR creates a new one (name + phone, plus national_id if AML threshold breached).
3. The in-flight Receipt now has `partner_id` populated.
4. Receipt closes with hash chain entry over the identified payload (customer name/identity in the hashable data).

---

## 3. Domain model

### 3.1 Partner extensions

`Partner` already has:
- `category` (`Individual | Business` — existing)
- `receivable_balance` (cached, debit AR — existing)
- `credit_balance` (cached, advance/prepayment — existing)
- `balance_updated_at` (existing)
- `credit_limit` (existing)
- `payment_terms_days` (existing)

Add:

```
partner.account_policy {
  account_status        ENUM(Active, Frozen, Closed, PendingKyc) NOT NULL DEFAULT 'PendingKyc'
  min_deposit_pct       decimal?    -- NULL = use tenant default
  min_deposit_amount    Money?      -- placeholder, NULL today, future layaway-style floor
  account_notes         text?
}
```

`credit_used` is **NOT** a stored field. It is computed via `PartnerBalanceService` from `receivable_balance` and `credit_balance` (or directly from GL via `getCustomerReceivableBalance()` / `getCustomerAdvanceBalance()`). All credit-limit checks use this derived value.

All `account_status` mutations are append-only with `(actor_user_id, occurred_at, reason)` audit rows.

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

### 3.3 Override audit

```
override_audit {
  id, occurred_at, actor_user_id, approver_user_id?
  override_type ENUM(BelowMinDeposit, OverCreditLimit, OverAmlCap,
                    EnableAccountForcedKyc, CashOutRefundCredit,
                    CashOutDrawerPayout, CashOutLargeChange)
  channel       ENUM(LocalPin, RemotePush, AccountantOverride)
  context_json  -- sale_id, partner_id, amount, gap, jurisdiction, etc.
  reason        text NOT NULL
  permission_snapshot_version  int  -- which approver-permission version was relied upon
}
```

Reason text is always required. `permission_snapshot_version` lets fiscal audit verify the approver had the relevant permission at the time of override (see §6.4).

### 3.4 POS Receipt extensions

POS Receipt model (`pos_receipts` table) adds:

```
receipt.receipt_type   ENUM(Sale, Encaissement, Refund) NOT NULL DEFAULT 'Sale'
receipt.partner_id     FK Partner NULL  -- required when receipt_type in (Encaissement, Refund-with-customer); required when Sale has on_account tender
```

ReceiptPayment (existing): adds `on_account` to the `PaymentInstrumentKind` enum (existing kinds: cash, card, store_voucher, restaurant_voucher, gift_card, none).

### 3.5 Customer identity map (offline-safety)

```
customer_identity_map (local SQLite + server-side mirror) {
  temp_uuid           UUID PK   -- assigned locally on offline create
  canonical_uuid      UUID?     -- assigned by server after dedup; NULL until reconciled
  status              ENUM(Pending, Reconciled, Rejected_Merged) NOT NULL
  reconciled_at       timestamp?
  merge_into_uuid     UUID?     -- if dedup merged this into an existing Partner
}
```

Offline-created Partners get a temp_uuid. On sync, the server runs scored matching (§7.5) and either accepts the temp_uuid as canonical or returns a merge directive. The identity map allows atomic rewrite of every local reference (outbox events, allocations, receipt FKs, vouchers, loyalty) to the canonical UUID, preserving immutable printed snapshots (the printed receipt keeps the temp_uuid as historical record).

---

## 4. Hash chain (existing infrastructure, used as-is)

### 4.1 POS local chain (per terminal, synchronous)

Existing AutoERP implementation:

- `ReceiptHashService` computes SHA-256 over the V2 payload: `receipt_number|posted_at|total|currency|vat_breakdown_hash|payment_methods_hash`.
- `Terminal.last_hash` tracks the per-terminal chain head; `Terminal.genesis_seed` is the bootstrapping value at terminal creation.
- Chain is built **synchronously in the same transaction** as receipt finalization.
- Verifier: `php artisan pos:verify-chain --terminal=...`.

### 4.2 V2 payload applies to all receipt types

- **Sale** (existing behavior): VAT breakdown filled, payment_methods filled, total = goods total.
- **Encaissement** (new): VAT breakdown empty (no goods sold, no VAT line), payment_methods filled (the actual cash/card received), total = amount received.
- **Sale with on_account tender** (new): VAT breakdown filled (goods sold), payment_methods filled (includes both cash AND on_account tender lines), total = goods total. The unpaid portion is captured as an `on_account` payment line — it's part of the payment_methods_hash.

No changes to `ReceiptHashService`. No changes to the V2 payload format. No new fiscal-chain infrastructure.

### 4.3 Server-side event chain — deferred

The original v1 proposed wiring `audit_events` into a per-channel hash chain to cover web/mobile-initiated payments. Per owner decision and given the first tenants are POS-only, this is deferred to a future "audit-chain-hardening" spec. Triggers for revisiting:

- B2C payments via web admin become common (i.e., the gap between POS chain coverage and total payment surface widens).
- France NF525 certification submission becomes a near-term goal.
- A regulator-equivalent regime requires cross-channel inalterability.

The deferred work would design a transactional outbox for `HashableFiscalEvent` emission, modify `DomainEventSubscriber` to fail-loud on audit-persistence errors for fiscal events, and add per-channel chain rows to `audit_events`.

### 4.4 V3 payload — future spec

The current V2 payload is sufficient for Tunisia day 1 and France pre-cert. V3 (richer NF525/ZATCA/EU-VAT-in-the-Digital-Age compatible payload) is a separate spec with process: Opus deep-research plan → Codex adversarial review → manual source verification. Migration from V2 to V3 will be additive (new column `hash_payload_version`, V2 rows stay verifiable forever, new rows use V3).

---

## 5. Domain services

### 5.1 EncaissementService (new)

Single entry point for "on-account payment received from a customer at the POS." Web-admin-initiated payments retain the existing Treasury Payment flow (see §5.6).

```
EncaissementService::record(input) → result
  in one DB transaction:
    1. Validate Partner is Active (or override accepted).
    2. Create POS Receipt:
         - receipt_type = Encaissement
         - partner_id = input.partner_id
         - lines = []  (no goods)
         - payment_lines = input.tender (cash, card, etc.)
         - total = sum(payment_lines.amount)
         - sequence = next per-(company, location, terminal, year)
         - chain hash via ReceiptHashService (same V2 payload)
    3. Create Treasury Payment row linked to the Receipt
       (payment.origin = 'pos', payment.origin_terminal_id = receipt.terminal_id).
    4. Invoke PaymentAllocationService.applyAllocation(
         strategy = FIFO | DueDate | Manual,
         payment_id,
         partner_open_invoices,
         actor = SystemActor::fromPosReceipt(receipt))
       Excess → CustomerAdvance GL (unconditional, regardless of Auth::user() presence).
    5. Fire PaymentRecorded event (existing event, NOT a new event named PaymentReceived).
    6. Update Partner.balance_updated_at.
```

### 5.2 OnAccountChargeService (new — POS receipt with on_account tender)

For the charge-to-account flow at checkout. Does not introduce a new document type.

```
OnAccountChargeService::record(input) → result
  in one DB transaction:
    1. Validate Partner is Active.
    2. Validate rules:
         tendered ≥ min_deposit_pct × total
         (current_balance + (total - tendered)) ≤ credit_limit
       OR override approval present (see ApprovalService).
    3. Create POS Receipt:
         - receipt_type = Sale
         - partner_id = input.partner_id
         - lines = input.lines  (goods)
         - payment_lines = input.tender + on_account_line(amount = total - tendered)
         - total = goods total (TTC)
         - chain hash via ReceiptHashService
    4. Extend GL posting:
         When receipt has on_account tender line, GeneralLedgerService posts:
           Cash debit (for cash/card tender amount)
           AR debit (411, for on_account amount) ← NEW
           Revenue credit (HT)
           VAT credit (TVA collectée)
    5. Fire ReceiptIssued event (or POS-equivalent existing event).
    6. Update Partner.balance_updated_at.
```

The GL extension is the load-bearing change: today's POS receipt GL posting recognizes revenue from the full tender amount; the new behavior posts to AR for the on_account portion.

### 5.3 PaymentAllocationService (refactor required — P1.3)

The existing service has two known issues blocking POS reuse:

1. **Method naming**: actual method is `applyAllocation`, not `apply`. v1.1 corrects this throughout.
2. **Auth dependency**: existing code uses `Auth::user()` when creating CustomerAdvance GL entries (`PaymentAllocationService.php:231` and `:260`). POS offline replay has no `Auth::user()` context. CustomerAdvance creation is currently conditional on user presence — so offline-replayed encaissements with overpayment may post Payment but skip the 4191 advance entry, leaving GL inconsistent.

**Required refactor**: introduce a command DTO:

```
ApplyAllocationCommand {
  tenant_id, company_id, payment_id,
  strategy, manual_allocations[],
  actor: ActorContext  -- {kind: 'user'|'system', user_id?, source: 'web_admin'|'pos_offline_sync'|...}
}
```

`PaymentAllocationService::applyAllocation(ApplyAllocationCommand)` is unconditional in GL behavior: CustomerAdvance entries are created when the business condition is met (excess > 0), regardless of actor kind. System actor is used for POS offline replay. Tests cover: authenticated web payment, offline POS replay, excess allocation, sales-order advance handling.

### 5.4 ApprovalService (new — primitive for overrides)

```
ApprovalService::request(type, context) → ApprovalRequest
ApprovalService::approve(request_id, approver_user_id, channel, token) → result

Channels (strategy pattern):
  LocalPinChannel       -- offline-capable; see §6.4 for protocol
  RemotePushChannel     -- stub; FCM/APNs push to manager device; online-only
  AccountantOverrideChannel -- future
```

MVP wires only `LocalPinChannel`. `RemotePushChannel` interface, settings flag, and backend endpoint are stubbed. See §6 for full approval semantics including override-type-driven channel selection (§6.5).

### 5.5 CustomerService (new — POS + web admin)

Search, create, edit operations.

```
CustomerService::search(query, scope, channel) → Partner[]
  -- scope = (tenant, company); channel = 'pos'|'web_admin'
  -- POS: queries local SQLite mirror with normalized search
  -- Web: queries server directly
CustomerService::create(input, channel) → Partner
  -- POS offline: assigns temp_uuid locally; queues outbox event
  -- Web online: server-assigned canonical_uuid immediately
CustomerService::edit(partner_id, input) → Partner
CustomerService::enableAccount(partner_id, by_user) → Partner
  -- PendingKyc → Active; requires permission pos.customer.enable_account
CustomerService::changeStatus(partner_id, new_status, reason, by_user)
  -- writes append-only audit row
```

### 5.6 Web admin Payment retrofit (clarified — P1.2)

Existing web admin payment flow stays semantically the same for B2B users. The change is mechanical:

- **Schema:** add `payment.origin` enum column (default `'web_admin'` for backfilled rows; explicitly set per call going forward).
- **API:** `PaymentController::formatPayment()` adds `origin` and `origin_terminal_id` to the response payload.
- **Generated TypeScript types:** rerun `php artisan typescript:transform`; commit generated files in `apps/web/src/types/generated.ts` and `packages/shared/types/generated.d.ts`.
- **Web UI:** `PaymentListPage` shows a new `Source` column (read from API).
- **Event:** existing `PaymentRecorded` event continues firing. No new event class.
- **Fixtures + tests:** update Payment fixtures to assert the new field's presence.

This is a real schema + API + UI change. It is intentionally non-disruptive (additive column, default value, no removed fields) but it is not a no-op.

---

## 6. Permissions and approval primitive

### 6.1 Spatie permission keys (new)

```
pos.customer.search
pos.customer.create
pos.customer.edit
pos.customer.enable_account
pos.customer.change_status            -- to Frozen / Closed (typically manager)
pos.payment.receive_on_account
pos.payment.refund_customer_credit_as_cash
pos.charge_to_account.execute
pos.override.min_deposit
pos.override.credit_limit
pos.override.aml_cap
pos.cashout.drawer_payout              -- existing or new (consolidate with §6.6)
pos.cashout.large_change_over_threshold
```

Permissions wired through the existing `auth:sanctum + SetPermissionsTeam` middleware chain on POS routes.

### 6.2 Override approval matrix (per-override-type, per-channel)

Each override type has a defined approval channel policy. Offline behavior is explicit per row.

| Override type | Online policy | Offline policy |
|---|---|---|
| `BelowMinDeposit` | Cashier-with-permission inline (no second user) | Same — `pin_allowed_offline` |
| `OverCreditLimit` | Manager PIN OR Push | `pin_allowed_offline` (no push offline; PIN required) |
| `OverAmlCap` | Manager Push (push_required_online_only) | **Blocked offline** — cashier must come online; cannot bypass with PIN |
| `EnableAccountForcedKyc` | Cashier-with-permission inline | Same |
| `CashOutRefundCredit` | Manager Push (push_required_online_only) | **Blocked offline** — cashier must come online |
| `CashOutDrawerPayout` | Manager PIN OR Push | `pin_allowed_offline` |
| `CashOutLargeChange` | Manager PIN OR Push | `pin_allowed_offline` |

The matrix prevents the "unplug network → downgrade to PIN" attack on the highest-risk overrides (AML cap, customer-credit-refund-as-cash). Push-only-online means those operations are not exercisable on a disconnected terminal at all; the cashier must wait or refuse the operation.

### 6.3 CashOutPolicy (unified)

A single policy governs all cash-out operations:

| Operation | Threshold | Approval | Audit |
|---|---|---|---|
| Customer-credit-refund-as-cash | Any amount | Manager Push (online-only) | Required |
| Sale-refund — cash | Threshold per jurisdiction | Manager PIN or Push | Required (existing Phase 1 refund flow) |
| Drawer-payout (petty cash, supplier, etc.) | Threshold per jurisdiction | Manager PIN or Push | Required |
| Large change on overpayment | Configurable threshold (default 200 TND / €100 / etc.) | Manager PIN or Push when above threshold | Required when above threshold |
| Normal change (under threshold) | — | None | None |

Thresholds default by jurisdiction (Tunisia first-tenant default established at rollout). Each cash-out emits an `override_audit` row through `ApprovalService`.

This consolidates the v1 spec's narrow `pos.payment.refund_customer_credit_as_cash` permission into a coherent policy covering all give-out-cash paths, including existing `pos.operate_terminal`-gated drawer payouts that v1 missed.

### 6.4 Approval-token protocol (PIN handling)

`LocalPinChannel` requires explicit protocol to address the existing PIN-replication codepath (`PosAuthController.php:117` returns operator PIN hashes; `apps/pos/src/lib/db/migrations.ts:72` stores them locally).

Protocol:

- **At-rest encryption**: PIN hashes are encrypted in SQLite using a per-terminal key derived from a device secret (Tauri keyring). Hashes are not stored plaintext in SQLite.
- **Brute-force counter**: per-approver, per-terminal counter. After N=5 failed attempts within a sliding window, that approver is locked out from this terminal for T=15 minutes; failure event queued to outbox for server-side alerting.
- **Offline TTL**: PIN hashes are valid offline only while the most recent permission snapshot is < 7 days old. After 7 days offline, the terminal must reconnect to refresh the snapshot before any PIN approval is accepted.
- **Signed permission snapshot**: server signs `(user_id, permissions[], snapshot_version, effective_at)` and POS validates the signature before honoring an offline PIN approval. The `permission_snapshot_version` is recorded in the override_audit row.
- **Rotation**: server-authoritative. When a manager's PIN is rotated server-side, the next sync pushes the new hash to all terminals; old hash is rejected.
- **Revocation**: revoking a manager's `pos.override.X` permission server-side propagates on next sync; the signed permission snapshot version increments. Offline PIN approval keyed to the old snapshot version fails.
- **No client-authored hashes**: the existing client-PIN-update-sync codepath is closed for fiscal-relevant override PINs. PIN setup uses a dedicated verified flow with server round-trip. (Existing till-PIN-self-set flows for non-fiscal operations can stay.)

### 6.5 Risk asymmetry on cash flow

- **Accepting cash** (sale tender, on-account payment, on-account-charge tender split) — always allowed offline, no special approval. Zero risk to the shop; server reconciles on sync (allocation may differ from POS-computed FIFO; excess routes to CustomerAdvance).
- **Giving out cash** — governed by CashOutPolicy (§6.3) with explicit per-operation thresholds and approval channels.

### 6.6 ManagerPinController refactor (P1.9)

The existing `ManagerPinController` (`apps/api/app/Modules/POS/Presentation/Controllers/ManagerPinController.php:76`) hardcodes `pos.close_shift_with_variance` as the required permission. Reusing it for fiscal overrides would authorize the wrong thing.

Refactor: replace with a generic approval endpoint:

```
POST /api/pos/approval/request
  body: { override_type, amount, jurisdiction, source, partner_id?, ... }
  returns: { request_id, required_permission, allowed_channels[] }

POST /api/pos/approval/approve
  body: { request_id, approver_user_id, channel, token }
  returns: { approved: bool, override_audit_id }
```

The endpoint maps `override_type → required_permission` (see table in §6.2) and checks that the approver holds it. The existing `pos.close_shift_with_variance`-specific endpoint is retired for fiscal overrides; shift-close variance continues to use its own dedicated flow.

---

## 7. Offline-first behavior

### 7.1 Offline envelope

| Operation | Offline-capable? | Local data source | Server reconciliation |
|---|---|---|---|
| Customer search | Yes | SQLite mirror | N/A (read-only) |
| Customer create (quick-create at till) | Yes | Temp UUID locally | Server multi-factor dedup; identity-map rewrites local refs on sync (§7.5) |
| Customer edit | Yes (limited) | Local pending change | Last-write-wins on server, audit row |
| Account enable (PendingKyc → Active) | Yes | Local + cashier permission | Audit row on sync |
| Charge-to-account at checkout | Yes | Last-known balance + queued offline charges | Server recalculates against live ledger; excess → CustomerAdvance; never reject |
| Receive on-account payment (Encaissement) | Yes | Local hash chain on terminal; receipt is final at emission (not provisional) | Server runs `PaymentAllocationService` on live ledger; overpayment → CustomerAdvance |
| Credit-limit check | Yes (best-effort) | Last-known balance; staleness shown | Server enforces hard cap on sync; may flip status to `Frozen` |
| Override approval (PIN — allowed offline) | Yes | Local PIN hash via §6.4 protocol | Audit row on sync |
| Override approval (Push — online-only) | **No** | — | N/A; cashier must come online |
| Account-statement print | Yes | Local mirror; "as of HH:MM" footer | — |
| Refund customer credit as cash | **No** | — | Push approval required; cashier must come online |

### 7.2 SQLite tables on POS

```
partners_mirror             -- inbound projection
documents_local             -- N/A: POS Receipts live in pos_receipts (existing offline table)
pos_receipts_local          -- existing offline table, extended with new receipt_type values
audit_events_local          -- local event chain (per-terminal) for non-fiscal audit
outbox_events               -- outbound queue, FIFO, idempotent
inbox_projections           -- inbound queue (catalogue, partner updates)
override_audit_local        -- override audit rows pending sync
customer_identity_map       -- temp_uuid ↔ canonical_uuid (§3.5)
```

### 7.3 Sync transport

Generic typed-envelope `{type, version, payload}` — POS is type-agnostic. Server applies via a type registry.

- **Outbound:** `POST /api/pos/sync/outbox` — batched, idempotency key = `{terminal_id, event_id, chain_sequence}`. Server skips already-ingested events.
- **Inbound:** `GET /api/pos/sync/since?cursor=<timestamp>` — deltas only since last cursor.
- **Cadence:** outbox flushes every 30 s when online; inbox polls every 60 s; both manual via Refresh button.
- **Visibility:** always-visible staleness badge "Synced 14:23" on Customers tab; red after 10 min offline.

**Note on forward-compat:** the typed envelope convention is the only thing claimed additive. Each future feature (marketplace orders, delivery webhooks, mobile owner app) requires its own design covering schema, sync route, conflict policy, conversion service, GL behavior, and UI state machine. v1.1 makes no claim that those features are "free."

### 7.4 Conflict-resolution policy

| Conflict | Policy |
|---|---|
| Customer create duplicate (multi-factor match) | Server scored matching (§7.5); returns merge directive; identity-map rewrites all pending local refs |
| Balance reconcile on payment sync | Server runs `PaymentAllocationService.applyAllocation` against the **live** ledger at sync time using `SystemActor`; excess → `CustomerAdvance` GL |
| Account-status flip while POS offline | Server is authoritative on next sync; offline-queued charges against a now-Frozen account go through (already happened, the money's in the drawer); notification surfaces on next session |
| Stale credit-limit override offline | Server records override + actor + reason + gap; no reversal — money already changed hands; surfaces in AR-aging follow-up workflow (out of scope here — see Phase 4) |
| Duplicate event submission | Idempotency key skips re-ingest |
| Printed offline receipt differs from server allocation | Receipt is final (per §B2 decision); server-recomputed allocation simply reroutes excess to CustomerAdvance silently. No amended receipt needed — the printed payment amount matches what was tendered; only the internal allocation routing differs |

### 7.5 Customer dedup — multi-factor scoring (P1.8)

Replaces v1's naive phone-equality match.

**Inputs:** normalized E.164 phone + country, tax/national ID, name, email, address.

**Server scoring algorithm:**

- Exact match on tax/national ID → high-confidence merge candidate.
- Exact match on normalized E.164 phone + country → mid-confidence; requires name-similarity ≥ threshold to merge.
- Phone match without country normalization → low-confidence; flag for manual review.
- Name + address fuzzy match (Levenshtein-based) → very-low-confidence; flag for manual review.

**Auto-merge** only on high-confidence. **Flag for manual review** on mid/low — server returns the local temp_uuid as canonical pro tem; admin reviews queued merges in web UI; merge executes asynchronously with identity-map propagation.

**Identity-map propagation** (atomic on the POS):

When server returns a merge directive (`temp_uuid X → canonical_uuid Y`):

1. POS locks identity_map row.
2. Rewrites every local reference to `X`: outbox events, pending allocations, queued receipts, vouchers, loyalty grants, override audits.
3. Printed fiscal snapshots (the customer-facing receipt that already left the store) keep the temp_uuid as historical record — they are not modified.
4. Server-side: the canonical Partner inherits all reconciled state.

### 7.6 Mirror refresh strategy

- **Scope:** per-company. Multi-company tenants get independent mirrors per company.
- **Initial pull:** full snapshot with progress UI on terminal startup or company switch.
- **Incremental pull:** every 60 s when online + before any "account enable" or high-value charge-to-account + manual refresh button.
- **Pull payload:** deltas — partners changed since cursor, account_status changes, balance_recompute events.

---

## 8. UI surfaces

(Unchanged from v1, except for the receipt-print format in §8.8.)

### 8.1 POS — Customers tab (new top-level)

Added as 5th route: `/`, `/sales`, `/customers` ← new, `/reports/z`, `/settings`. Search bar at top; result list with name, phone, balance badge, `account_status` badge.

### 8.2 POS — Customer Detail screen

Header (name, phone, email, account_status, account_notes), balance card (current balance, credit_limit, last-synced-at), tabs ("Open invoices", "Recent payments", "Activity"), primary CTAs (Receive Payment, New Sale).

### 8.3 POS — Receive Payment screen

Step 1: amount numpad. Step 2: tender method picker. Step 3: allocation preview (online via `/api/v1/smart-payment/preview-allocation`; offline local FIFO simulation). Step 4: confirm → emits POS Receipt `receipt_type=Encaissement` + Treasury Payment + allocations; prints Reçu d'encaissement. Staleness banner if offline > 10 min.

### 8.4 POS — Charge-to-Account flow at checkout

Till payment screen extended with "Charge to account" tender option (visible only when Partner with `account_status=Active` is attached). Rule violations (below min deposit, over credit limit) trigger override modal per §6.2. Confirmation modal before close. On confirm: emits POS Receipt with `on_account` tender line; AR posting per §5.2.

### 8.5 POS — Customer Search inline (mid-sale)

"Attach customer" button on till; opens search modal. Same search semantics as Customers tab. Inline "Create new" for quick-create. Promotes anonymous in-flight sale to identified before close-out.

### 8.6 Admin — Unified Payments listing

Existing `/treasury/payments` extended with `Source` column (POS01 / Web admin / Mobile / API). Filterable by source. Legacy pre-spec rows show `Source = '—'` or default `web_admin` based on backfill.

### 8.7 Admin — Customer Detail (existing, extended)

`PartnerDetailPage` adds: account_status badge, credit_limit, min_deposit_pct, payment_terms_days, account_notes, "Account history" audit log section.

### 8.8 Customer-facing print documents

- **Reçu d'encaissement** — printed at POS for Encaissement receipts. Shows partner identity, amount, method, allocated invoices, new balance, **full stored fiscal sequence verbatim at least once**, hash signature.
- **Account statement** — printable on request, separate document with full invoice/payment/balance history.
- **Sale receipt footer** — when Partner with `account_status=Active` is attached, adds one line: `Solde du compte après ce ticket: X TND`.

**Print format example** (full sequence printed once):

```
Otospex SAS · Av. Bourguiba · POS01
Reçu N° OTO/AVB/POS01/2026/000123          ← full stored sequence
Ticket #000123                              ← friendly short version, optional
2026-05-13 14:23
Client : Aymen Khlifa (+216 55 123 456)
Encaissé sur compte : 100,00 TND
[...]
```

The full stored fiscal sequence appears verbatim at least once. QR payloads and exports use the same identifier.

---

## 9. Data flow scenarios

### 9.1 Receive on-account payment in POS (offline)

1. Cashier opens Customers tab, searches "55 123 456", taps result.
2. Customer Detail shows balance 245 TND owed.
3. Cashier taps "Receive Payment", enters 100 TND, picks Cash.
4. POS computes local FIFO allocation preview from last-known open invoices.
5. Confirm → POS creates local POS Receipt `receipt_type=Encaissement` with sequence `OTO/AVB/POS01/2026/000045` (from local terminal counter), local `ReceiptHashService` chain entry on terminal, local Treasury Payment row, local allocation rows.
6. POS prints Reçu d'encaissement (full fiscal sequence on it).
7. Drawer opens, cash goes in.
8. Outbox queues the event bundle.
9. On reconnect: server ingests, runs `PaymentAllocationService.applyAllocation` against live ledger using `SystemActor`. If customer paid online in meantime → server reroutes excess to `CustomerAdvance` silently. Cashier sees no rejection. The printed receipt's amount is still correct; only internal allocation routing may differ.

### 9.2 Receive payment in web admin (B2B, retrofit details)

1. Admin opens partner detail, clicks "Record Payment".
2. Existing `PaymentForm` runs — fields unchanged from user perspective.
3. On submit, existing controller path runs. **New:** Payment created with `origin='web_admin'`.
4. `PaymentAllocationService.applyAllocation` runs (same code path, command-DTO refactor applied; actor = real Auth::user()).
5. Existing `PaymentRecorded` event fires.
6. Response payload (`PaymentController::formatPayment()`) now includes `origin` and `origin_terminal_id` fields.
7. Generated TypeScript types refreshed (`php artisan typescript:transform`); web UI's `PaymentListPage` now shows `Source` column.

Behavior visible to a B2B user: identical to before, plus a new column in the payments listing.

### 9.3 Charge-to-account at till (online)

1. Cashier scans items, total = 100 TND.
2. Cashier attaches customer (Active, credit_limit=200, current_balance=60, min_deposit_pct=30%).
3. Cashier enters cash tender = 20 TND.
4. POS validates: 20 < 30% × 100 = 30 → below min deposit. Override modal opens.
5. Cashier has `pos.override.min_deposit` permission → enters reason "loyal customer, paying balance Friday" → confirms.
6. POS validates credit: current 60 + remaining 80 = 140 ≤ 200 ✓.
7. Confirmation modal: "Will add 80 TND to Aymen Khlifa's account balance. Confirm?"
8. Confirm → POS Receipt with goods lines + tender split (Cash 20, on_account 80).
9. GL posts: Cash 20 debit, AR 80 debit, Revenue 84.75 credit, VAT 15.25 credit (rough).
10. Receipt prints with `Solde du compte après ce ticket: 140 TND` footer and the full fiscal sequence.

### 9.4 Customer create offline (quick-create at till, with AML trigger)

1. Walk-in customer wants to pay 6 000 TND in cash for a big purchase — exceeds Tunisia AML cap (5 000 TND).
2. POS shows AML warning, requires identification.
3. Cashier opens search → "Create new" → enters name + phone + national_id.
4. POS creates local Partner with `temp_uuid`, `account_status=PendingKyc`.
5. Attaches Partner to in-flight sale, promotes anonymous to identified.
6. Sale closes as POS Receipt with `partner_id = temp_uuid`, including the customer identity in the hashable payload.
7. On sync, server runs multi-factor dedup:
   - High-confidence match on national_id → merge directive returned; identity_map updates locally; outbox events and queued allocations get rewritten atomically to canonical UUID.
   - No match → server accepts temp_uuid as canonical.

### 9.5 Refund customer credit as cash (give-out-cash, online-only)

1. Customer has 50 TND credit balance, wants it back in cash.
2. Cashier opens Customer Detail → "Refund credit as cash".
3. POS checks: this is `CashOutRefundCredit` → `push_required_online_only`.
4. **If offline:** modal: "This operation requires online manager approval. Please retry when connected." Cashier waits or refuses operation.
5. **If online:** ApprovalService routes to RemotePushChannel → manager receives push on their device → approves with remote token → operation proceeds.
6. Server verifies credit balance is currently 50 TND, approves the cash-out.
7. Cashier hands over 50 TND. POS records the cash-out as an opposing event to the original credit.
8. `override_audit` captures: actor, approver, channel=`RemotePush`, reason, permission_snapshot_version.

---

## 10. Migration plan

### 10.1 Database migrations

All migrations are **additive**:

- POS Receipts: add `receipt_type ENUM(Sale, Encaissement, Refund) DEFAULT 'Sale'`, `partner_id FK NULL`.
- PaymentInstrumentKind enum: add `on_account`.
- Partner: add `account_status`, `min_deposit_pct`, `min_deposit_amount` (placeholder), `account_notes`. (Existing `credit_limit`, `payment_terms_days`, `receivable_balance`, `credit_balance`, `balance_updated_at` are kept.)
- New table: `tenant_account_policy`.
- New table: `override_audit`.
- New table: `customer_identity_map` (server side, mirroring SQLite schema).
- Payment table: add `origin ENUM` (default `'web_admin'`), `origin_terminal_id FK NULL`.
- Spatie permission keys added via seeder.

### 10.2 Backfill

- **First Tunisian tenant (current deployment):** account_status backfilled from balances — Active where `total_receivable > 0 OR has_open_invoices`, else PendingKyc. **This backfill is acceptable** because (a) Tunisia day 1 has no NF525 enforcement, (b) account_status changes are not on the fiscal hash chain (POS receipt chain only — see §4), and (c) data import recovery path is available (dump + restore). Documented and acknowledged.
- **Second tenant onward:** account_status defaults to PendingKyc; admin promotes to Active per customer manually. No automatic backfill.
- Payment.origin backfilled to `'web_admin'` for all existing Payment rows.
- No fiscal hash backfill. Chains start forward from cutover.

### 10.3 Feature flags

Per-tenant flag `pos_customer_accounts_enabled`. Default false. Sub-flags:

- `pos_customer_management` (Phase 1).
- `pos_on_account_payment` (Phase 2).
- `pos_charge_to_account` (Phase 3).
- `account_status_workflow` (Phase 4).

### 10.4 Tunisia → France path

When France enters scope for NF525 cert:

1. Run V3 hash payload spec (Opus + Codex + manual verification).
2. Run audit-chain-hardening spec (transactional outbox for cross-channel event chain).
3. Update legal-effective citations to post-2026-09-01 successor article in Code des impositions (replaces Art. 269 CGI).
4. Cert submission with dossier explaining the per-terminal chain + audit chain reconciliation.

### 10.5 Implementation checklist (i18n + types + decimals)

Per AutoERP conventions:

- **i18n namespaces** (start English + French; Arabic incoming):
  - POS app: new `customers` namespace, `payments` namespace.
  - Web admin: extend existing `treasury`, `partners` namespaces.
  - All user-facing strings via `t()`; no hardcoded text.
- **Generated TypeScript types**:
  - Run `php artisan typescript:transform` after every PHP DTO change.
  - Commit `resources/js/types/generated.d.ts`, `apps/web/src/types/generated.ts`, `packages/shared/types/generated.d.ts`.
- **Decimal-string handling**: backend `DECIMAL` columns emit as TypeScript strings. Use `CurrencyScale::bcformat($value, $scale)` for backend formatting (per project memory on monetary precision); on frontend use the `decimal.js` (or equivalent) library, never lexical compare.
- **Permissions**: register all new permission keys via `RolesAndPermissionsSeeder`.

---

## 11. Testing strategy

### 11.1 Backend (PHPUnit)

- **Domain tests** — `EncaissementService`, `OnAccountChargeService`, `ApprovalService`, `CustomerService` unit-tested with real Eloquent models + `RolesAndPermissionsSeeder`. No mocked DB.
- **PaymentAllocationService refactor tests** — command DTO accepted; system-actor path produces CustomerAdvance entries; authenticated-actor path unchanged; offline replay produces correct GL.
- **Chain tests** — existing `pos:verify-chain` passes against seeded Encaissement and on_account-tender receipts. Tamper-detection: mutate a row, verifier must fail.
- **GL extension tests** — `GeneralLedgerService` posts to AR (411) on on_account tender; standard cash receipts unchanged.
- **Approval-token tests** — encrypted PIN cache validates; brute-force lockout triggers; signed permission snapshot version mismatch rejects offline approval; rotation invalidates old hash.
- **Customer-dedup tests** — multi-factor scoring across exact-ID, normalized-phone, name+address fuzzy; identity-map rewrites all local refs atomically.
- **Acompte boundary tests** — `payment_document_type='acompte'` rejected by CHECK (namespace reserved only).

### 11.2 Frontend POS (Vitest)

- Customer search/create/edit components render and submit correctly.
- Offline-mode tests: outbox queues correctly; staleness badge updates; PIN lockout UI.
- Override modal flows: PIN entry vs Push entry; per-override-type channel rendering.
- Charge-to-account flow: rule violations trigger override; confirmation modal blocks until confirmed.
- Push-only-online overrides: offline mode shows "must come online" message, not PIN fallback.

### 11.3 Compliance / verifier

- `php artisan pos:verify-chain --terminal=...` passes against seeded fixtures including all three receipt_types.
- DB-level POS receipt immutability triggers reject UPDATE/DELETE on `pos_receipts` rows after finalization (existing behavior, new variants inherit).

### 11.4 End-to-end (manual + scripted)

- Tunisian para-pharmacy golden path:
  1. Cashier creates customer offline.
  2. Customer makes 100 TND on-account payment offline (POS Receipt `Encaissement`).
  3. Terminal reconnects; sync drains; admin sees payment in unified listing with `Source=POS01`.
  4. Web admin records additional 50 TND payment for same customer (existing flow, `origin='web_admin'`).
  5. Customer returns; cashier sees updated balance after next sync.
- B2B regression: existing web admin Payment flow + allocation + CustomerAdvance handling unchanged.

---

## 12. Open items / future work

1. **V3 hash payload spec** — internationally-compliant payload (France/Italy/Saudi Arabia/etc.). Process: Opus deep-research plan → Codex adversarial review → manual source verification.
2. **NF525 certification submission** — separate effort.
3. **Audit-chain-hardening spec** — transactional outbox for cross-channel event chain. Triggered when B2C web payments common OR France cert.
4. **Deposits feature (acompte / arrhes)** — `FactureAcompte` document type, VAT-on-collection per Art. 269 CGI (or successor after 2026-09-01), GL 4191 + 44571 posting, sales-order linkage. Namespace reserved on discriminator only.
5. **Layaway-style min-deposit floor** — `min_deposit_amount` column reserved as placeholder. Add when shop demand surfaces.
6. **Auto-freeze cron** — background job; manual freeze in MVP.
7. **Marketplace inbound orders** — separate spec. Typed envelope reserves transport namespace only.
8. **Delivery-company integration** — separate spec.
9. **Mobile owner app + RemotePush approval channel** — separate spec. Approval primitive interface already exists.
10. **Body-shop fully-offline workshop** — multi-day offline operation, workshop projections (vehicles, jobs). Separate spec.
11. **AML threshold table for Algeria + EU AMLR (2027)** — values configured per tenant; add to defaults list when those markets approach.
12. **Italy lottery-vs-codice-fiscale mutual exclusion** — when Italy enters scope.
13. **Algeria stamp-duty calculator** — when Algeria enters scope.
14. **AR-aging delinquent-balance follow-up workflow** — for stale-credit-limit overrides that exceeded the limit; not in scope here but tracked.

---

## Appendix A — Authoritative citations

**French fiscal:**
- BOI-TVA-DECLA-30-10-30 — inalterability scope.
- **Art. 269 CGI** — VAT exigibility on acompte; **abrogated 2026-09-01 by Ordonnance 2025-920**.
- **Successor** — Code des impositions sur les biens et services (CIBS). Specific successor article to Art. 269 CGI will be sourced from CIBS during France-cert preparation; cited here as a forward dependency, not a current implementation reference. France day-1 work (car body shops, not NF525-cert) does not depend on this resolution.
- **VAT in the Digital Age (ViDA)** — EU Commission package; e-invoicing/digital reporting milestones [https://taxation-customs.ec.europa.eu/taxation/vat/vat-digital-age_en].
- Art. 289 CGI — facture d'acompte obligation (pending successor).
- L.123-22 Code de commerce — 10-year accounting retention.
- Art. 1590 Code civil — arrhes default presumption.

**Tunisian fiscal:**
- Art. 18 Code de la TVA — facture numbering ininterrompue (applies to factures, not tickets/encaissements).
- TEIF 2026 obligation (INNORPI / TTN).
- Plan Comptable Tunisien — account 411 + 4191 mirror French PCG.

**NF525 (deferred):**
- BOI-TVA-DECLA-30-10-30 + AFNOR NF525 (paywalled; acquire before cert submission).
- Infocert NF525 guide; LNE certification specs.

**Industry / event sourcing (deferred reference):**
- Pat Helland, *Immutability Changes Everything*.
- BSI TR-03153 (German TSE — event-chained fiscal regulation).
- ZATCA Phase 2 E-Invoicing Implementation Resolution (document-chained PIH).

**AML:**
- service-public.fr F10999 — France €1 000 cap.
- EU AMLR (Council adopted 2024-05-30) — €10 000 EU-wide from 2027-07.
- HM Revenue & Customs — HVD registration under MLR 2017.

---

## Appendix B — Decisions log (v1 → v1.1)

| Decision | v1 | v1.1 |
|---|---|---|
| Document type for on-account-charge | New `OnAccountCharge` enum value | POS Receipt with `on_account` tender line; no new DocumentType |
| Document type for Encaissement | New `Encaissement` enum value | POS Receipt with `receipt_type=Encaissement`; no new DocumentType |
| Hash chain | Hybrid (event chain + document chain) | POS local chain only (existing). Event chain deferred to future audit-chain-hardening spec |
| Sequence scoping for Encaissement | Per-(company, year) gapless | Per-(company, location, terminal, year); matches existing ticket pattern. Art. 18 applies to factures only |
| Offline Encaissement numbering | Provisional/definitive split | Final at emission. Per-terminal sequence is final |
| Event chain channel keys | `pos:`, `web:`, `mob:`, `api:` | N/A (event chain deferred) |
| `credit_used` field | Stored | Derived through `PartnerBalanceService` |
| `PaymentAllocationService` reuse | Direct (claimed unchanged) | Refactored with command DTO; system-actor for offline replay; CustomerAdvance unconditional |
| Web admin retrofit | "Unchanged" | Acknowledged schema + API + TS + UI changes (§5.6) |
| Override approval — fallback | Generic "PIN or push" | Per-override-type matrix; `push_required_online_only` blocks AML cap + customer-credit-refund offline (§6.2) |
| Cash-out policy | Only `pos.payment.refund_customer_credit_as_cash` | `CashOutPolicy` covering all cash-out paths (§6.3) |
| PIN protocol | Abstracted as "LocalPinChannel" | Explicit encryption, brute-force, TTL, signed snapshot, rotation, revocation (§6.4) |
| Customer dedup | Phone match | Multi-factor scoring + identity-map (§7.5) |
| ManagerPinController | (not addressed) | Refactored to generic approval endpoint (§6.6) |
| Legal citations | Static Art. 269 CGI | Date-effective with abrogation note + successor in Code des impositions (§Appendix A) |
| Marketplace/delivery/mobile additivity | "Additive" | Downgraded to "typed envelope convention only"; each feature gets own design |
| Genesis seed convention | Different across chains | N/A; only POS terminal chain is in scope; existing `genesis_seed` unchanged |
| Backfill prohibition | Absolute | First-tenant Tunisia: account_status backfill from balances is acceptable (non-fiscal-chain). Second tenant onward: PendingKyc default + manual promote |
| i18n + TS regen | Not mentioned | Implementation checklist added (§10.5) |
| Print sequence visibility | Friendly two-line split | Full stored sequence printed once verbatim + optional short version (§8.8) |

---

**End of spec v1.1.**
