# POS / Fiscal Layer — Codebase Reality Reference

**Created:** 2026-05-14
**Purpose:** Verified, file:line-cited facts about the current AutoERP POS + fiscal + payment + event codebase. Produced by a four-agent audit to break the knowledge-gap cycle that BLOCKed three Codex review rounds. **Every spec from here forward must ground its codebase claims in this document.**
**Method:** Four parallel `Explore` agents read the files Codex cited across rounds 1-3.

---

## 1. POS fiscal pipeline (receipt creation, hashing, finalization, sequencing)

### 1.1 Finalization transaction + locking
- `ReceiptFinalizationService::finalize()` wraps everything in `DB::transaction()` — `ReceiptFinalizationService.php:54`.
- `Terminal::lockForUpdate()->findOrFail(...)` acquires an exclusive row lock on the terminal **before** hash computation — `:56`.
- Lock order: Terminal locked first (`:56`), receipt updated (`:78`), terminal updated (`:82`).
- `ReceiptCreated` domain event dispatched via `DB::afterCommit()` — `:88-100` — i.e. **after** commit, not during.
- `ReceiptSyncService` wraps the whole sync in its own `DB::transaction()` (`:189`) and calls `finalize()` nested inside it (`:623`); comment at `:612-617` notes Laravel reuses the transaction via savepoints.

### 1.2 Sequence numbering
- Format: `{location_code}-{terminal_code}-{year}-{8-digit-zero-padded}` — `ReceiptCreationService.php:796`; training receipts prefixed `TRN-` — `:811`.
- Sequence increment: `terminal.current_sequence = sequence + 1` on draft creation — `ReceiptCreationService.php:623`.
- Monotonicity: unique constraint `pos_receipts_terminal_sequence` on `(terminal_id, receipt_year, chain_sequence)` — migration `2026_01_08_190637:99`.
- CHECK: `chain_sequence IS NULL OR chain_sequence > 0` — migration `2026_05_01_000001:32`.

### 1.3 Hash chain fields
- On the receipt row: `fiscal_hash` (char(64)), `previous_hash` (char(64) nullable), `chain_sequence` (integer nullable) — migration `2026_01_08_190637:44-45`, `2026_05_01_000001:18`.
- Finalization sets `receipt.previous_hash = terminal.last_hash` and `receipt.chain_sequence = terminal.current_sequence` **before** hash computation — `ReceiptFinalizationService.php:65-66`; writes `receipt.fiscal_hash` at `:76`; advances `terminal.last_hash` and `terminal.current_sequence` at `:80-81`.
- On `pos_terminals`: `genesis_seed` char(64) required, `last_hash` char(64) nullable, `current_sequence` integer default 0, `fiscal_schema_version` smallint default 2 — migrations `2026_01_08_190429:41-44`, `2026_05_01_000002:14`.

### 1.4 Hash computation — V2 and V3
- **V2 payload** (pipe-separated, 6 fields): `receipt_number|posted_at|total|currency|vat_breakdown_hash|payment_methods_hash` — `ReceiptHashService.php:62-71`. `posted_at` is `toIso8601String()`. Final hash via `FiscalHashService::calculateHash()` which folds in `previousHash` + `genesisSeed`.
- **V3 payload** (RFC 8785 canonical JSON, top-level keys sorted alphabetically): `audit_hash, currency, exchange_group_id, payment_methods_hash, posted_at, previous_hash, receipt_number, schema_version, total, vat_breakdown_hash, voucher_ledger_hash` — `CanonicalPayloadBuilder.php:60-93`.
- **V3 final hash** = `hash('sha256', $canonical)` over the canonical JSON string — `V3ReceiptHashComputer.php:51`. **`previous_hash` is embedded IN the canonical JSON object as a hex string** (`CanonicalPayloadBuilder.php:84`) — NOT concatenated separately as bytes.
- **`CanonicalJsonEncoder`** supports `null`, `bool`, `int`, `string`, `array` only; **throws `InvalidArgumentException` on any other type** (incl. floats) — `CanonicalJsonEncoder.php:51-70`. Documented PHP-vs-JS divergence on U+2028/U+2029, **not currently normalized** — `:99-104`.
- Monetary values in V3 are **pre-formatted decimal strings** via `CurrencyScale::bcformat()` — `V3ReceiptHashComputer.php:95-96, 113, 142` — not floats, not integer cents.
- Client side mirrors this: `receiptService.ts:210-234` (V3, `sha256Hex(canonical)`), `:418-427` (V2, pipe-joined). Version chosen by reading `terminalState.fiscal_schema_version` — `:402-427`.

> **DESIGN IMPLICATION:** the existing "V3" is a **receipt-specific** canonical-JSON hash where `previous_hash` lives inside the JSON. Any new fiscal-event signature provider is a **different protocol** and MUST be named distinctly (e.g. `FiscalEventV1SignatureProvider`) — confirms Codex round-3 BLOCKER 2.

### 1.5 Terminal + receipt type enums
- `TerminalType` enum: `web` | `physical` (lowercase) — `TerminalType.php:7-11`. **No `is_virtual` column or concept** on Terminal.
- `pos_terminals.location_id` is **NOT NULL** (required FK) — migration `2026_01_08_190429:31-33`. Unique `(tenant_id, company_id, location_id, code)` — `:67`.
- `ReceiptType` enum: `sale` | `return` (lowercase) — `ReceiptType.php:8-10`.
- `pos_receipts.partner_id` exists — migration `2026_03_09_100000`.
- `pos_receipts.receipt_type` — **DISCREPANCY, see §6.1.** Audit agent reading the base migration did not find it; Codex round 2 cited migration `2026_03_09_200000_add_return_fields_to_pos_receipts.php:24` adding it with default `'sale'` + CHECK at `:45`. Treat as: **`receipt_type` exists, values `'sale'`/`'return'`, with a CHECK constraint** (verify exact migration during implementation).
- `pos_receipts` CHECK on totals — **DISCREPANCY, see §6.2.** Base migration `2026_01_08_190637:113` has `total = subtotal + tax_amount`; Codex round 2 cited `2026_03_09_200000:41` with `total = subtotal + tax_amount - discount_amount`. Either way, a payment-only receipt (subtotal=0, tax=0, total>0) **violates the constraint**.
- Immutability trigger `prevent_receipt_modification()` — `BEFORE UPDATE OR DELETE` only — migration `2026_01_08_190637:133-189`, modified `2026_05_01_000003:34-65`. **No TRUNCATE coverage** — confirms Codex round-3 P1.2.

---

## 2. POS payment + GL integration

### 2.1 Receipt payment processing
- `ReceiptPaymentService` loops over every payment line and creates **one Treasury `Payment` row per line** — `ReceiptPaymentService.php:193-266`.
- Each line **requires** `payment_method_id` and `repository_id` (`findOrFail` — `:202-225`).
- Immediately calls `GeneralLedgerService::createPOSPaymentEntry()` and posts it — `:269-282`.
- **No concept of a "settlement" payment line** that isn't a cash/bank inflow. Comment at `:42`: "POS payments are DIRECT TO REVENUE (no AR account)."

### 2.2 GL posting for POS
- `createPOSPaymentEntry()` — `GeneralLedgerService.php:1194-1253`: **Debit** repository GL account, **Credit** `ProductRevenue`. **No AR, no VAT line, no partner_id.** Comment at `:1190-1192` confirms "DIRECT TO REVENUE (no AR account)."
- AR posting (411) exists **only** in the B2B invoice path — `createFromInvoice()` `:54-120`, `createPaymentEntry()` `:202-255` (posts AR at `:236-244`).

> **DESIGN IMPLICATION:** charge-to-account (AR debit from a POS sale) has **no existing GL path**. Routing an `on_account` marker through `ReceiptPaymentService` would create a fake Treasury Payment + direct-to-revenue GL entry. Confirms Codex round-3 BLOCKER 1. **Phase 3 concern — out of scope for Phases 1-2.**

### 2.3 Treasury Payment model + allocation
- `Payment` model `$fillable` — `Payment.php:70-94`: includes `tenant_id, company_id, partner_id, payment_method_id, instrument_id, repository_id, amount, currency, payment_date, status, payment_type, reference, notes, journal_entry_id, ...`. **NO `origin`, NO `fiscal_event_id`.**
- `PaymentAllocationService::applyAllocation()` (public) — `PaymentAllocationService.php:83`.
  - Depends on `Auth::user()` at `:232` and `:263`; CustomerAdvance GL creation is **conditional on `$user instanceof User`** — skipped if no authenticated user — `:233`, `:263`. **Not safe for queue jobs / offline replay** without a refactor.
  - Depends on `CompanyContext` (`requireCompany()`, `requireCompanyId()`) — `:88-89`.
  - Allocation targets: posted **Invoices** + confirmed **SalesOrders** — `:345-354`.
- **Treasury `Payment` writers — COMPLETE inventory** (corrected 2026-05-14 after the Codex Phase-1-spec review found the earlier list incomplete — the original list named only three writers and that gap kept propagating into downstream specs):
  - `ReceiptPaymentService` — one `Payment` per receipt payment line (`ReceiptPaymentService.php:193-266`).
  - `PaymentController::store()` (`:88-435`, creates at `:198-210`) and `PaymentController::storeMultiple()` (`:440-888`, creates at `:571-585`).
  - `MultiPaymentService::createSplitPayment()` (`MultiPaymentService.php:61-76`), `::recordDeposit()` (`:133-148`), `::recordPaymentOnAccount()` (`:273-285`).
  - `PaymentRefundService::refundPayment()` (`PaymentRefundService.php:68-84`), `::partialRefund()` (`:153-169`), and its receipt-proration refund rows (`:436-445`).
  - `VendorRefundService::refundPrepayment()` (`VendorRefundService.php:99-112`).
  - **Excluded:** `App\Modules\Billing\Domain\Payment` is a separate model, NOT the Treasury `Payment`.
- `PaymentType` enum includes `POS = 'pos'` — `PaymentType.php:25`. Also `DocumentPayment, Advance, Refund, CreditApplication, SupplierPayment`.
- `PaymentInstrumentKind` enum: `store_voucher, restaurant_voucher, gift_card, none` — `PaymentInstrumentKind.php:37-82`. **No `on_account`.**

> **DESIGN IMPLICATION (Phase 1):** add nullable `Payment.origin` + `Payment.fiscal_event_id` + `PaymentOrigin` enum; update **every** Treasury `Payment` writer in the complete inventory above — not just the three originally listed. Confirms Codex round-3 P1.7 and the Phase-1-spec review BLOCKER 1.
> **DESIGN IMPLICATION (Phase 2):** `PaymentAllocationService` needs a command-DTO refactor with an explicit actor before it can be invoked from offline-sync replay.

---

## 3. Tauri / POS client offline layer

### 3.1 Local SQLite schema — 24 tables
`products, payment_methods, payment_repositories, operator_pins, offline_receipts, terminal_state, sync_log, sync_metadata, z_reports, z_report_counts, company_fraud_settings_cache, product_images, floors, tables, menu_categories, menu_category_items, held_transactions, queued_pin_updates, vouchers, voucher_ledger, receipt_qr_index, refund_drafts, pos_migration_state, offline_cash_drawer_ops` — `migrations.ts:8-899`.

Load-bearing absences and presences:
- **No local Treasury `Payment` table.** The offline app mirrors `payment_methods` + `payment_repositories` and stores `offline_receipts`; it **cannot create a Treasury Payment locally** — `migrations.ts:36-67, 91-122`.
- **No customer/partner mirror table.** `partner_id` appears only in `receipt_qr_index` (`:596-609, 625`) for receipt lookup — not a customer mirror.
- `offline_receipts` carries `fiscal_hash`, `previous_hash`, `hash_sequence`, `fiscal_schema_version`, `is_training`, `payments_json` — but **NO `receipt_type`, NO `partner_id` denormalized columns** — `migrations.ts:91-122` + later versions.
- `terminal_state` carries `genesis_seed, last_hash, hash_sequence, fiscal_schema_version, z_*`, plus `manager_pin_throttle_until`, `manager_pin_failed_attempts` — `migrations.ts:127-135` + v9, v22, v28.
- `operator_pins` stores `pin_hash`, `roles`, `permissions` locally — `migrations.ts:74-84`.
- `queued_pin_updates` exists — client-authored PIN hash sync path — `migrations.ts:350-361`.
- `offline_cash_drawer_ops` exists (`type='deposit'|'payout'`) — `migrations.ts:262-280`.
- `vouchers` + `voucher_ledger` exist.

> **DESIGN IMPLICATION (Phase 2):** offline `ACCOUNT_PAYMENT` **cannot** write a Treasury Payment locally. It must be a sealed local fiscal event + local cash-drawer state; the server creates the Treasury Payment as an idempotent projection on ingestion. Confirms Codex round-3 BLOCKER 3. A local **customer mirror table** must be added for Phase 2 search.

### 3.2 Sync
- `receiptToPayload()` — `syncService.ts:1767-1877`; wire shape `SyncReceiptPayload` — `:110-153`; server DTO `SyncReceiptPayload.php:65-91`. Neither carries `receipt_type` or `partner_id`/customer identity.
- POS sync routes are **per-resource** (`/pos/receipts/sync`, `/pos/sync/pull`, `/pos/sync/menu`, `/pos/voucher-ledger/sync`, etc.) — `routes.php:30-136`. **No generic typed-envelope outbox endpoint exists.**

> **DESIGN IMPLICATION (Phase 1):** a new outbox endpoint + ingestor is genuinely new code, not an adaptation of an existing route. The spec must name it concretely and state its relationship to `/pos/receipts/sync` + `ReceiptSyncService`. Confirms Codex round-3 P2.3.

### 3.3 Crypto stack
- AES-256-**GCM** (`aes-gcm` crate v0.10), 12-byte random nonce prepended to ciphertext, base64 — `crypto.rs:1-77`.
- Master key stored as a **plaintext file** `$APPDATA/com.syneriva.izipos/.izipos_key`, Unix perms `0o600` — `crypto.rs:13-52`.
- **No keyring / Stronghold plugin** in `Cargo.toml` — `Cargo.toml:15-34`. No vendored Ed25519/libsodium library.
- **No trusted-time source** — only the system clock (`new Date().toISOString()`) — `receiptService.ts:399`.

> **DESIGN IMPLICATION:** any PIN-crypto / signed-snapshot design must build on the existing AES-GCM file-key (no keyring), and any TTL must NOT rely on the local clock. Ed25519 would need a new crate. Confirms Codex round-3 P2.4. **Mostly a Phase 4 concern (approval primitive) — Phases 1-2 only need the existing AES-GCM for any at-rest needs.**

---

## 4. Event infrastructure

### 4.1 Domain events + Spatie
- `DomainEvent` base class extends Spatie `ShouldBeStored` — `DomainEvent.php:16`. Defines `occurredAt()`, `aggregateRootUuid()`, `setMetaData()`, `metaData()`. **No `getHashableData()` on the base class.**
- `stored_events` table (Spatie) — columns `id, aggregate_uuid, aggregate_version, event_version, event_class, event_properties (jsonb), meta_data (jsonb), created_at` — migration `2025_11_30_102448:11-24`. Operational.
- `getHashableData()` is implemented in **9 event classes** (`InvoicePosted, DeliveryNoteConfirmed, DocumentConverted, PaymentRecorded, InvoiceClosedWithTolerance`, + 4 Loyalty events) and has **ZERO callers** — dead code.

### 4.2 Audit events
- `audit_events` table — columns `id (uuid), tenant_id, company_id, user_id, event_type, aggregate_type, aggregate_id, payload (jsonb), metadata (jsonb), event_hash (varchar 64), occurred_at, timestamps` — migration `2025_11_30_140000:13-30`.
- **`audit_events` is NOT a chain** — it has a per-event `event_hash` but **no `previous_hash`, no `chain_sequence`.**
- `DomainEventSubscriber` subscribes to **25 event types** — `DomainEventSubscriber.php:841-893`. On audit-persistence failure it **swallows the error** (logs and continues, does not throw) — `:825-833`.

> **DESIGN IMPLICATION (Phase 1):** `fiscal_events` is a genuinely new table. `audit_events` cannot be upgraded into the fiscal chain in place — it's a separate, non-chained store. Spatie `stored_events` stays as the domain-event store. The Phase 1 spec must declare `fiscal_events` the source of truth for in-scope fiscal facts and define a synchronous, no-duplicate bridge contract — NOT the after-commit, error-swallowing `DomainEventSubscriber` path. Confirms Codex round-3 P1.6 + round-1 B1.

---

## 5. Partner model + validation

### 5.1 Partner
- `Partner` `$fillable` — `Partner.php:86-124` — includes `receivable_balance, credit_balance, payable_balance, credit_limit, payment_terms_days, balance_updated_at, is_active, customer_category`.
- **No `account_status` column/enum** — only `is_active` boolean.
- **No `min_deposit` field.**
- `CustomerCategory` enum: `individual` | `business` — `CustomerCategory.php:7-19`. `Partner::isB2B()` checks `customer_category === Business` — `Partner.php:188`.

### 5.2 Balance computation
- `PartnerBalanceService` computes balance by **GL aggregation** over `journal_lines` (`SUM(debit) - SUM(credit)` filtered by `partner_id` + account purpose) — `PartnerBalanceService.php:35-62`.
- Public surface includes `getCustomerReceivableBalance()` (`:75`), `getCustomerAdvanceBalance()` (`:85`), `refreshPartnerBalance()` (`:288`, updates Partner cache columns), `getCachedOrCalculateBalance()` (`:371`).

> **DESIGN IMPLICATION (Phase 2):** `account_status` and `min_deposit*` are genuinely new columns. `credit_used` should NOT be stored — derive via `PartnerBalanceService`. The B2B/B2C distinction already exists as `CustomerCategory` + `isB2B()` — use it; do not invent a parallel concept.

### 5.3 Validation
- `TaxIdValidationService` validates **business / tax identifiers only**: France SIRET, Tunisia Matricule Fiscale, Italy Codice Fiscale / Partita IVA, UK CRN — `TaxIdValidationService.php:17-152`.
- It does **NOT** validate B2C personal national IDs (French CNI, Tunisian CIN). **No separate national-identity validation service exists** anywhere in the codebase.

> **DESIGN IMPLICATION (Phase 2):** "port `TaxIdValidationService` to POS for AML national-ID validation" is wrong — it validates the wrong thing. Either a new `NationalIdentityValidationService` is needed, or B2C AML national-ID validation is deferred / handled online. Confirms Codex round-3 P1.8.

---

## 6. Discrepancies to resolve during implementation

These are minor and don't change design conclusions, but should be confirmed when the migrations are touched:

### 6.1 `pos_receipts.receipt_type`
The base-migration audit agent did not find `receipt_type` in `2026_01_08_190637`; Codex round 2 cited `2026_03_09_200000_add_return_fields_to_pos_receipts.php:24` as adding it (default `'sale'`, CHECK at `:45`). **Working assumption: it exists via the later migration, values `'sale'`/`'return'`, CHECK-constrained.** Confirm exact migration + CHECK when implementing.

### 6.2 `pos_receipts` totals CHECK constraint
Base migration `2026_01_08_190637:113` shows `total = subtotal + tax_amount`. Codex round 2 cited `2026_03_09_200000:41` with `total = subtotal + tax_amount - discount_amount`. Possibly the base constraint was replaced by the later one. **Either way the conclusion holds: a payment-only receipt violates it.** Confirm the current effective constraint when implementing.

---

## 7. Net design conclusions (carried into the phase specs)

1. **`fiscal_events` is a new table.** Not an upgrade of `audit_events` (no chain) or `stored_events` (Spatie domain store). Source of truth for in-scope fiscal facts; synchronous no-duplicate bridge contract; not the after-commit error-swallowing path.
2. **The new signature provider is a new protocol.** Name it `FiscalEventV1SignatureProvider` (or similar). The existing "V3" is a receipt-specific canonical-JSON hash with `previous_hash` embedded in the JSON — distinct and unchanged.
3. **`CanonicalJsonEncoder` throws on floats** and has a documented PHP/JS Unicode divergence. The fiscal-event payload grammar must be: no floats, integer cents, UTC string timestamps, normalized free text, sorted arrays — and needs cross-language golden vectors.
4. **Charge-to-account / on-account has no GL or payment path today** — `ReceiptPaymentService` + `createPOSPaymentEntry` are cash/revenue-only, no AR. This is a **Phase 3** concern; Phases 1-2 do not touch it.
5. **Offline cannot write Treasury Payments.** Offline `ACCOUNT_PAYMENT` is a sealed local fiscal event + local cash-drawer state; server projects the Treasury Payment on ingestion.
6. **No customer mirror exists offline.** Phase 2 adds one.
7. **No generic outbox endpoint exists.** Phase 1 adds a named `OutboxIngestor` + route, distinct from `/pos/receipts/sync`.
8. **Immutability trigger misses TRUNCATE.** Phase 1 adds `BEFORE TRUNCATE` coverage + role revocation.
9. **`Payment` lacks `origin` / `fiscal_event_id`.** Phase 1 adds them + updates **every** Treasury `Payment` writer per the complete §2.3 inventory: `ReceiptPaymentService`, `PaymentController::store`/`storeMultiple`, `MultiPaymentService` (×3), `PaymentRefundService` (×3), `VendorRefundService`.
10. **`PaymentAllocationService` depends on `Auth::user()` + `CompanyContext`.** Needs a command-DTO refactor with an explicit actor before offline-sync replay can use it (Phase 2).
11. **`account_status` / `min_deposit*` are new Partner columns.** `credit_used` is derived, not stored. B2B/B2C is `CustomerCategory` + `isB2B()` — reuse, don't reinvent.
12. **`TaxIdValidationService` validates business IDs, not B2C national IDs.** A new validator is needed, or B2C AML is deferred.
13. **No keyring, no trusted clock** on the Tauri stack. Approval-primitive crypto (Phase 4) builds on AES-GCM file key; TTLs cannot trust the local clock.

---

**End of codebase reality reference.**
