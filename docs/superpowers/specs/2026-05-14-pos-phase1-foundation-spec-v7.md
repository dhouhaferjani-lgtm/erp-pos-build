# POS Phase 1 — Foundation: Fiscal Event Engine + Receipt-Chain Clean Rebuild (v7)

**Date:** 2026-05-14
**Phase:** 1 of 5 (roadmap v2)
**Status:** Drafted. v1 → Codex BLOCK (3B/3P1/3P2); v2 → Codex BLOCK (2B/1P1/1P2); v3 → Codex BLOCK (1B/3P1/2P2); v4 → Opus APPROVE-WITH-MINOR-EDITS (1P1/2P2/2P3); v5 → Codex BLOCK (1B/1P3); v6 → Codex BLOCK (1B); **v7 resolves the v6 finding and adds a structural fix.** Awaiting Codex re-review → writing-plans.
**Supersedes:** v6 (`2026-05-14-pos-phase1-foundation-spec-v6.md`), v5, v4, v3, v2, v1.

**Grounding (every codebase claim traces to one of these):**
- **Source-of-truth v3** (LOCKED; amended 2026-05-14 — §13 guardrail 6 + D16, the bounded-modules guardrail, refined the same day with the inbound-reference-data / outbound-operational-dependency distinction) — `2026-05-14-offline-first-fiscal-source-of-truth-v3.md`. Cited `[SoT §N]`.
- **Codebase reality audit** — `2026-05-14-pos-fiscal-codebase-reality.md`. Cited `[reality §N]`.
- **Receipt-chain clean-rebuild scoping map** — 2026-05-14 scoping audit.
- **Roadmap v2** (corrected) — `2026-05-14-pos-customer-accounts-roadmap-v2.md`.
- **Owner strategy docs** — `fiscal_chain_architecture_strategy.md`, `pos_printable_documents_architecture.md`.
- **Codex v3 review** — `reviews/2026-05-14-pos-phase1-foundation-spec-v3-codex-review.md`. Cited `[Codex v3]`.
- **Opus v4 review** — `reviews/2026-05-14-pos-phase1-foundation-spec-v4-opus-review.md`. Cited `[Opus v4]`.
- **Codex v5 review** — `reviews/2026-05-14-pos-phase1-foundation-spec-v5-codex-review.md`. Cited `[Codex v5]`.
- **Codex v6 review** — `reviews/2026-05-14-pos-phase1-foundation-spec-v6-codex-review.md`. Cited `[Codex v6]`.

**v6 → v7 changelog (v6 Codex finding resolved + the structural fix it pointed at):**
- **BLOCKER (POS order-close still server-authors `SALE_RECEIPT`)** — v6 dispositioned `ReceiptController::store` (web + Tauri-online) but missed `POST /pos/orders/{id}/close` → `OrderController::close` → `OrderManagementService::closeOrder` → `OrderToReceiptService::convertToReceipt` → `ReceiptCreationService::createReceipt()` — another server-authoring `SALE_RECEIPT` path `[Codex v6]`. Verified: order-close is an **`apps/web`-only** flow (`apps/web/.../orderApi.ts:201`, `useOrders.ts`, `CloseOrderButton.tsx`, `OrderPanel.tsx`) — no Tauri caller — so it is the **same class as the web-POS new-sale path** and is dispositioned with it (§14.2; web-POS order management → §18 deferred parity).
- **STRUCTURAL FIX (the v5+v6 BLOCKERs were the same class — "a missed server-authoring caller").** v7 stops dispositioning callers ad hoc and dispositions the **two chokepoints**: `ReceiptCreationService::createReceipt()` (server-side `SALE_RECEIPT` draft creation) and `ReceiptFinalizationService::finalize()` (server-side seal + chain advance). New **§14.3** states the invariant, enumerates **every** production caller of both (grep-verified 2026-05-14), gives each a disposition, and makes the writing-plan completeness check a **mechanical grep of the two chokepoints** rather than an enumeration that can miss one. §5.0 and §17.5 reference the chokepoints.

**v5 → v6 changelog (both v5 Codex findings resolved):**
- **BLOCKER (Tauri online checkout still server-authors `SALE_RECEIPT`)** — v5 dispositioned only the browser web-POS new-sale path; the Tauri POS `executeCheckout()` has an `onlineCheckout()` branch that, whenever connectivity is online, calls `createReceipt()` / `processReceiptPayments()` → `POST /pos/receipts` + `/pos/receipts/{id}/payments` — server-authoring, bypassing `receiptService.ts` `[Codex v5 — `apps/pos/src/lib/offline/offlineCheckoutService.ts:83-115,178-199`, `apps/pos/src/api/receiptApi.ts:14-27`]`. v6 makes the device-authority rule **connectivity-independent and terminal-type-independent** (§5.0): `SALE_RECEIPT` is authored only via `FiscalEventEngine.append()`, on every terminal type, online or offline — connectivity affects only sync-flush *timing*, never authorship. §14 adds disposition rows for `offlineCheckoutService.ts` (REWORK — `executeCheckout` always authors locally; the `onlineCheckout` server-authoring branch is DISCARDED) and `apps/pos/src/api/receiptApi.ts` (the new-sale server-authoring callers discarded; read methods stay). §14.2 reframes the backend-route disposition to cover **all** new-sale callers — web *and* Tauri-online. §17.5 adds the proving tests. Behavior change flagged: online Tauri checkouts move from server-authored-immediate to device-authored-then-sync — which *is* device authority `[SoT §1, D1]`.
- **P3 (`/pos/receipts/sync` feature-suite list still not exhaustive)** — §14.1 adds `OfflineV3CutoverSyncTest.php` and classifies `PosStabilizationTenantIsolationTest.php` as a broader stabilization suite with receipt-sync assertions to migrate `[Codex v5]`.

**v4 → v5 changelog (all 5 Opus edits applied):**
- **P1 (module-name casing mismatch)** — `requiresModule()` / `ModuleActivationResolver.isActive()` now use the **canonical PascalCase module token `'Treasury'`** matching `Vertical::defaultModules()`, which `CompanyConfig::hasModule()` strict-compares; §7.3 pins the contract and §17.3 test-locks it (§7.3, §7.4, §17.3).
- **P2-1 (web-POS disposition omitted `void` / `processReturn`)** — §14.2 now names `void` and `processReturn` (and their `apps/pos` callers) as **knowingly retained server-side for Phase 1**, cross-referenced to the reserved `SALE_VOID` / `REFUND_RECEIPT` / `PARTIAL_REFUND` event types; the "does not coexist" claim is scoped to *new-sale* authoring (§14.2).
- **P2-2 (parse-failure resume transaction boundary undefined)** — §7.5 now specifies the `payload` write + `payload_parse_status` flip + `fiscal_event_projections` row inserts happen in **one transaction**, enqueue after commit; `fiscal:enqueue-resolved-event-projections` is named the recovery path for the "flipped but projections missing" state (§7.5, §17.3).
- **P3-1 (`ReceiptPaymentService` mis-attributed wholesale to the Treasury bridge)** — §7.4 / §14 now state the relocation **splits** `ReceiptPaymentService`: the `ReceiptPayment::create` logic (`:297`) → `PosCoreReceiptProjection`; only the Treasury `Payment` (`:252`) + GL (`:269`) logic → `TreasuryReceiptBridge`. `PosCoreReceiptProjection` is the single owner of `ReceiptPayment` creation regardless of input path.
- **P3-2 (`/pos/receipts/sync` feature-suite scope was a glob)** — §14.1 now names the concrete receipt-sync feature suites.

**v3 → v4 changelog:**
- **BLOCKER (bounded-modules seam not buildable on the current payment-method boundary)** — v3 over-claimed that `PosCoreReceiptProjection` has "zero Treasury dependency." Corrected per the owner's asymmetric-seam model `[SoT §13.6 as refined]`: the dependency direction is what matters. **Inbound** — payment methods / tenders / repositories are *setup reference data*, defined in the web and synced to the POS local mirror; `ReceiptPayment` referencing that mirrored reference data is a **permitted inbound dependency**, not a coupling to the Treasury operational module. **Outbound** — the fiscal engine, authoring/sealing/chain, and the ingestion path have **zero** dependency on the Treasury *operational* module (Treasury `Payment` rows, GL, allocation). v4 makes that distinction explicit, stops claiming the FK is removed, and adds a per-`(tenant,company)` **`ModuleActivationResolver`** so the Treasury bridge is genuinely gated and the seam is testable (§5.0, §7.3, §7.4, §13). `payment_methods` is **not** relocated — see §18.
- **P1 (web POS disposition names no concrete write paths)** — new **§14.2** web-POS disposition checklist names the live server-authoring surface `[Codex v3]`.
- **P1 (`sequence_conflict` quarantine not verbatim enough)** — `fiscal_event_quarantine` expanded with a raw typed-envelope column + the query-critical metadata columns, and §15/§8 state which fields the verifier and export use (§8).
- **P1 (parse-failure projection resume has no mechanism)** — §7.5 defines the resolver contract: resolution that writes `payload` + flips `payload_parse_status → parsed` creates the `pending` projection rows for active projectors and enqueues them, via a named `fiscal:enqueue-resolved-event-projections` command.
- **P2 (`/pos/receipts/sync` retirement checklist omits classes)** — §14.1 expanded with the concrete old-sync DTO/request/result/service classes and the client integration tests `[Codex v3]`.
- **P2 (projection retry/dead-letter state underspecified)** — §7.5 names the Laravel queue (Horizon) as the owner of transient backoff/locking, adds a distinct `dead_lettered` status and the operational timestamp columns.

---

## 1. Overview

### 1.1 What Phase 1 is

The foundation. Phase 1 establishes the **one fiscal pattern** `[SoT §1]` — device authors and seals locally, server verifies-verbatim and mirrors — and **rebuilds the existing receipt chain clean on that pattern, as a consumer of the engine**. No customer-facing feature. When Phase 1 is done: the fiscal event engine exists; `SALE_RECEIPT` is a first-class fiscal event flowing through it; there is **one chain and one ingestion path**; the server-side business projection is a **POS-core projection plus pluggable module bridges**, gated by a per-`(tenant,company)` activation resolver `[SoT §13.6, D16]`; the schema carries the non-retrofittable hooks later phases depend on.

### 1.2 Scope (in)

1. The **preflight verification gate** — server-side + device-side + web-POS disposition (§2).
2. The **`fiscal_events` table** — device SQLite + server PostgreSQL mirror, with the specified immutability triggers (§3).
3. The **canonical serialization contract** (§4).
4. The **central integration model** — receipt path as engine consumer; the **projector registry / pluggable-bridge seam** and the **`ModuleActivationResolver`** `[SoT §13.6]` (§5.0); `HashChainIntegrityProvider` + `SignatureProviderInterface` (§5).
5. The **device-side fiscal event engine** — `append()`, the single chain head (§6).
6. The **server-side verify-only mirror** — the single typed fiscal-event ingestion endpoint, `OutboxIngestor`, the strict parser (§7.1–§7.2, §7.6).
7. The **POS-core receipt projection** + the **pluggable Treasury receipt bridge** + the **projection failure/retry/resume contract** (§7.3–§7.5).
8. The **per-anomaly-class integrity-exception path** + quarantine, including `sequence_conflict` and the `fiscal_event_quarantine` table (§8).
9. **Chain-recovery events** (§9).
10. The **clock / time model** (§10).
11. **Company-level integrity record types** — `TERMINAL_REGISTRY_SNAPSHOT` (implemented), `COMPANY_DAY_CLOSURE_MANIFEST` (reserved) (§11).
12. **Off-device durability controls** `[SoT §8]` (§12).
13. **`Payment.origin` / `Payment.fiscal_event_id`** — the Treasury-module integration migration + `PaymentOrigin` enum + the **complete** Treasury `Payment` writer-update inventory (§13).
14. The **receipt-chain clean rebuild** — reuse/rework/discard/retire, with the concrete `/pos/receipts/sync` cleanup checklist (§14.1) and the web-POS disposition checklist (§14.2).
15. The **`fiscal:verify-event-chain`** and **`fiscal:enqueue-resolved-event-projections`** commands (§15).

### 1.3 Scope (out — Phase 2+, per roadmap v2)

Anything customer-facing (customer mirror, search/create/attach, `ACCOUNT_PAYMENT`) — Phase 2. Charge-to-account / AR GL path / B2B Facture routing — Phase 3. Account-status, override flows, approval primitive — Phase 4. Deposits, identity reconciliation, AML, store credit — Phase 5. The Z-report chain clean rebuild — its own task (coordination caveat in roadmap v2). The actual `TseSignatureProvider` — interface + nullable columns only here. **Relocating `payment_methods` / tender / repository ownership** out of the Treasury module — explicitly *not* Phase 1; this reference data is deliberately kept where it is and mirrored (§5.0, §18).

**The web POS (`TerminalType::Web`) is out of scope as a device-authority target.** `TerminalType` carries `Web` and `Physical` values `[reality §1.5]`, and a browser-based web POS is structurally not a device-authoring fiscal source of truth — it cannot seal locally. Bringing the web POS onto the device-authority pattern is **parity work, deferred** (tracked, §18). But Phase 1 cannot silently ignore it: the web POS has a **live server-authoring receipt-creation path** `[Codex v3 — `POSTransactions.tsx`, `ReceiptController::store/storePayments`, `routes.php:45,98,102`]`, and leaving that alive next to the rebuilt device-authority chain would violate `[SoT D8]` ("one fiscal pattern, no two-model coexistence"). Phase 1 therefore **dispositions the web POS** — the preflight gate (§2) decides; the §14.2 checklist names the concrete server-authoring targets to hide/disable. No web-POS device-authority work happens in Phase 1; only the disposition.

**There is no new "web transaction view" to build.** POS transactions are already viewable through the existing web shop-management (POS) section — a read projection over the synced server mirror `[SoT §13.6]`. That is sufficient for the simplest (POS-only) use case and needs no Treasury module. Nothing in Phase 1 builds or changes it beyond it reading the rebuilt `pos_receipts` projection rows. Read-only receipt search / PDF / download routes are preserved through the rebuild (§14.2).

---

## 2. The preflight verification gate

`[SoT §1, §15.1]` — a **hard precondition** before any destructive rebuild step. The clean-rebuild *decision* is locked (no migration); the *precondition* (no live fiscal data) is operational and must be verified. `offline_receipts` is a Tauri SQLite table, not a server table `[reality §3]`, so the gate has **three surfaces**:

**Server-side** — query every staging and production tenant PostgreSQL database for: `pos_receipts`, `pos_z_reports`, `pos_terminals` chain state (`last_hash` / `current_sequence` non-default), receipt-print records.

**Device-side** — inventory every installed/deployed Tauri terminal's SQLite store for: `offline_receipts`, `terminal_state`, `z_reports`, and any pending-sync rows. **If no deployed terminals exist, record that operational fact explicitly.**

**Web-POS disposition** — determine whether any `TerminalType::Web` terminal has a live receipt-creation path producing `pos_receipts` rows `[Codex v3 confirms the path is live]`. If yes: the web POS receipt-creation path is **hidden/disabled** before the rebuild per the §14.2 checklist (it cannot coexist with the device-authority chain — `[SoT D8]`), and web-POS device-authority parity is logged as deferred work (§18). If the web POS has no live receipt-creation usage, record that fact and the rebuild proceeds unblocked.

**Sign-off** — a written owner sign-off covering **all three surfaces**: the environments are empty (or anything found is disposable test data), and the web-POS disposition is decided. If any non-disposable fiscal data is found: stop; define and execute an archival/export path first; re-scope. The gate is the **first task** of the implementation plan, blocking all schema-destructive work.

---

## 3. The `fiscal_events` table

The canonical append-only fiscal ledger. **Device SQLite is authoritative; server PostgreSQL is a verbatim verify-only mirror** `[SoT §1, §11]`. Unchanged from v3.

### 3.1 Device SQLite — `fiscal_events`

```
fiscal_events {
  id                         TEXT     PK            -- UUID v4, device-generated
  tenant_id                  TEXT     NOT NULL
  company_id                 TEXT     NOT NULL
  terminal_id                TEXT     NOT NULL
  operator_id                TEXT     NOT NULL
  event_type                 TEXT     NOT NULL       -- FiscalEventType (Appendix A)
  event_version              INTEGER  NOT NULL DEFAULT 1
  signature_version          TEXT     NOT NULL       -- 'hash-chain-integrity-v1' in Phase 1
  sequence_number            INTEGER  NOT NULL       -- per (tenant_id, terminal_id); monotonic; continuous across years
  event_time_device          TEXT     NOT NULL       -- device-claimed UTC ISO-8601 (untrusted; §10)
  business_date              TEXT     NOT NULL       -- YYYY-MM-DD; assigned per §10 closure rule
  last_server_time_seen      TEXT
  reference_event_id         TEXT                    -- compensating / CHAIN_* recovery events
  reference_document_id      TEXT                    -- cross-link (e.g. the offline_receipts projection row)
  source_event_class         TEXT                    -- idempotency
  source_event_id            TEXT                    -- idempotency
  partner_id                 TEXT                    -- Phase 2+; NULL in Phase 1
  partner_identity_snapshot  TEXT                    -- Phase 2+; NULL in Phase 1
  canonical_bytes            TEXT     NOT NULL        -- the EXACT canonical byte string the device hashed (§4)
  previous_hash              TEXT     NOT NULL        -- 64-char lowercase hex; genesis seed for the first event
  current_hash               TEXT     NOT NULL        -- 64-char lowercase hex = SHA-256(canonical_bytes)
  -- signature object: nullable, design-for-TSE, never populated in Phase 1 [SoT §4.2]
  signature_status           TEXT     NOT NULL DEFAULT 'not_required'  -- not_required|pending|signed|failed
  signature_algorithm        TEXT
  signature_value            TEXT
  signature_counter          INTEGER
  signature_provider         TEXT
  signing_device_id          TEXT
  certificate_id             TEXT
  signed_payload_ref         TEXT
  time_source_value          TEXT
  time_format                TEXT
  provider_transaction_id    TEXT
  -- sync lifecycle (offline-row pattern, [reality §3])
  sync_status                TEXT     NOT NULL DEFAULT 'pending'   -- pending|syncing|synced|failed
  sync_error                 TEXT
  created_at                 TEXT     NOT NULL
  synced_at                  TEXT
}
```

### 3.2 Server PostgreSQL — `fiscal_events`

```
fiscal_events {
  id                         UUID         PK
  tenant_id                  UUID         NOT NULL
  company_id                 UUID         NOT NULL
  terminal_id                UUID         NOT NULL
  operator_id                UUID         NOT NULL
  event_type                 VARCHAR(64)  NOT NULL
  event_version              SMALLINT     NOT NULL DEFAULT 1
  signature_version          VARCHAR(64)  NOT NULL
  sequence_number            BIGINT       NOT NULL
  event_time_device          TIMESTAMPTZ  NOT NULL
  business_date              DATE         NOT NULL
  last_server_time_seen      TIMESTAMPTZ
  server_received_at         TIMESTAMPTZ  NOT NULL        -- set by the OutboxIngestor (§7, §10)
  reference_event_id         UUID
  reference_document_id      UUID
  source_event_class         VARCHAR(255)
  source_event_id            UUID
  partner_id                 UUID
  partner_identity_snapshot  JSONB
  canonical_bytes            BYTEA        NOT NULL        -- device's exact canonical bytes, verbatim
  previous_hash              CHAR(64)     NOT NULL
  current_hash               CHAR(64)     NOT NULL
  -- signature object (nullable; never populated in Phase 1)
  signature_status           VARCHAR(16)  NOT NULL DEFAULT 'not_required'
  signature_algorithm        VARCHAR(64)
  signature_value            TEXT
  signature_counter          BIGINT
  signature_provider         VARCHAR(64)
  signing_device_id          UUID
  certificate_id             VARCHAR(128)
  signed_payload_ref         TEXT
  time_source_value          VARCHAR(64)
  time_format                VARCHAR(32)
  provider_transaction_id    VARCHAR(128)
  -- integrity / quarantine (§8)
  integrity_status           VARCHAR(24)  NOT NULL DEFAULT 'verified'   -- verified|quarantined
  integrity_exception_class  VARCHAR(32)
  integrity_exception_reason TEXT
  integrity_resolved_at      TIMESTAMPTZ
  integrity_resolved_by      UUID
  -- derived structured payload (§7.6)
  payload                    JSONB
  payload_parse_status       VARCHAR(16)  NOT NULL DEFAULT 'pending'    -- pending|parsed|failed
  created_at                 TIMESTAMPTZ  NOT NULL DEFAULT NOW()
}
```

**Note:** business-projection state is **not** a column on `fiscal_events` — the table is immutable chain truth, and projection is a separate, retryable, per-module concern. Projection state lives in the mutable `fiscal_event_projections` table (§7.5).

**Indexes & constraints:**
```
UNIQUE (tenant_id, terminal_id, sequence_number)                       -- chain integrity + idempotency key
UNIQUE (source_event_class, source_event_id) WHERE source_event_id IS NOT NULL
INDEX  (reference_event_id)      WHERE reference_event_id IS NOT NULL
INDEX  (reference_document_id)   WHERE reference_document_id IS NOT NULL
INDEX  (integrity_status)        WHERE integrity_status <> 'verified'
CHECK  (sequence_number > 0)
CHECK  (current_hash  ~ '^[0-9a-f]{64}$')
CHECK  (previous_hash ~ '^[0-9a-f]{64}$')
CHECK  ((source_event_class IS NULL AND source_event_id IS NULL)
     OR (source_event_class IS NOT NULL AND source_event_id IS NOT NULL))
CHECK  (event_type IN ( ...Appendix A reserved values... ))
```

### 3.3 Immutability triggers

Unchanged from v3. The precise mechanism, modelled on the existing `pos_receipts` immutability trigger `[reality §1.5]`:

**Server PostgreSQL:**
- An **unqualified `BEFORE UPDATE` trigger function** that compares `OLD`/`NEW` and `RAISE EXCEPTION` **unless only the allowed columns changed**. Allowed columns: `payload`, `payload_parse_status`, `integrity_status`, `integrity_exception_class`, `integrity_exception_reason`, `integrity_resolved_at`, `integrity_resolved_by`. Every other column unchanged-or-raise.
- **State-transition checks within the allowed set:** `payload_parse_status` is write-once `pending → parsed` or `pending → failed`; `payload` is write-once and only when `payload_parse_status` goes `→ parsed`. `integrity_status` transitions are `verified → quarantined` (ingestor) and `quarantined → verified` (resolver, which must also set `integrity_resolved_at` / `integrity_resolved_by`); no other transition.
- A separate **`BEFORE DELETE` trigger** that always `RAISE EXCEPTION`.
- A separate **`BEFORE TRUNCATE … FOR EACH STATEMENT` trigger** that always `RAISE EXCEPTION`.
- `REVOKE TRUNCATE ON fiscal_events FROM <application_role>`.
- Break-glass: DBA-only maintenance requires a signed full export first; documented in an ops runbook.

**Device SQLite:**
- `BEFORE UPDATE` trigger `RAISE(ABORT)` unless only the sync-lifecycle columns changed (`sync_status`, `sync_error`, `synced_at`) — same offline-row discipline `offline_receipts` already uses `[reality §3]`.
- `BEFORE DELETE` trigger `RAISE(ABORT)`. (SQLite has no `TRUNCATE`.)

**Notes:** `previous_hash` is never NULL (first event uses the terminal fiscal-event genesis seed, §6.2). Hashes are lowercase hex `CHAR(64)` everywhere `[reality §1.3]`; only `canonical_bytes` is binary. `sequence_number` is continuous across fiscal years `[SoT §3]`. The `fiscal_event_projections` and `fiscal_event_quarantine` tables (§7.5, §8) are **not** part of the chain and carry no immutability triggers.

---

## 4. Canonical serialization contract

`[SoT §1.2, §5]`. Unchanged from v3 — the device serializes **once**; the server **never re-serializes**.

- **Canonical object** — sorted-key JSON (RFC 8785 / JCS) over `business_date, company_id, event_time_device, event_type, event_version, operator_id, payload, previous_hash, reference_document_id, reference_event_id, sequence_number, signature_version, tenant_id, terminal_id`. `previous_hash` is embedded as a hex string, matching the existing receipt-V3 pattern `[reality §1.4]`.
- **Value grammar** — integers only, no floats; money as `CurrencyScale::bcformat()` decimal strings; UTC ISO-8601 second-precision timestamps; UTF-8 NFC strings with U+2028/U+2029 stripped at the producer; sorted arrays; sorted object keys.
- **`canonical_bytes`** — the UTF-8 encoding, stored verbatim (device `TEXT`, server `BYTEA`), **never round-tripped through a JSON/JSONB column** `[SoT §1.2]`.
- **`current_hash` = `lowercase_hex(SHA-256(canonical_bytes))`.** The server verifies by re-hashing the device's exact bytes — never re-serializes (D2).
- **`FiscalEventCanonicalEncoder`** (device, TypeScript) — applies the string normalization, reuses the structural logic of the existing `CanonicalJsonEncoder` pattern `[reality §1.4]`; not mirrored in PHP.
- **Cross-language golden vectors** — committed fixture set `{ payload_dto_input, expected_canonical_string, expected_sha256_hex }`. TS test reproduces the canonical string + hash; PHP test asserts only `sha256(expected_canonical_string) == expected_sha256_hex` (PHP never serializes). Matrix must include: TND 3-decimal, 2-decimal, 0-decimal currencies, a negative amount, empty arrays / null optionals, multibyte/NFC, U+2028/U+2029 normalization, non-ASCII key ordering.

---

## 5. The central integration model + the providers

### 5.0 The central integration decision + the bounded-modules seam

`[SoT §1, D8 — "one fiscal pattern"; SoT §13.6, D16 — "bounded modules compose"]` together force this.

**The receipt path is a *consumer* of the fiscal event engine — not a parallel sealer, not a separate chain, not a separate ingestion path.**

- **`SALE_RECEIPT` is a fiscal event — authored only via `FiscalEventEngine.append()`, on every terminal type, regardless of connectivity.** There is **one chain** — `fiscal_events`. **Device authority is connectivity-independent (`[SoT §1, D1]`):** the device authors and seals locally **always** — there is no "online checkout" branch that server-authors. Connectivity affects only **when the sync flush runs** (immediately when online; deferred when offline), never **who authors**. Any code path that, when online, posts a new sale to a server-authoring endpoint instead of calling `FiscalEventEngine.append()` is a two-model-coexistence violation (`[SoT D8]`) and is discarded in the rebuild. **Server-side `SALE_RECEIPT` authoring has exactly two chokepoints — `ReceiptCreationService::createReceipt()` (draft creation) and `ReceiptFinalizationService::finalize()` (seal + chain advance); §14.3 enumerates and dispositions every caller of both, and is the completeness authority for this rule.**
- **`receiptService.ts` becomes a business-document assembler.** It builds the receipt business document (lines, totals, vouchers, payment lines), then calls `FiscalEventEngine.append({ type: SALE_RECEIPT, reference_document_id: <offline_receipts row id>, payload })` **inside one SQLite transaction** — the same transaction that writes the `offline_receipts` projection row and updates voucher balances. It no longer computes its own independent hash or advances its own chain head.
- **`executeCheckout()` always routes through local authoring.** The Tauri checkout entry point (`apps/pos/src/lib/offline/offlineCheckoutService.ts`) currently branches: `onlineCheckout()` server-authors when connectivity is online, `offlineCheckout()` device-authors otherwise. Post-rebuild there is **one path** — `executeCheckout()` always authors locally via the `receiptService.ts` assembler → `FiscalEventEngine.append()`; the `onlineCheckout()` server-authoring branch is removed. When online, an immediate sync flush follows the local seal (§14).
- **`pos_receipts` / `offline_receipts` become projection rows.** Their `fiscal_hash` / `previous_hash` / `hash_sequence` columns **mirror** the authoritative `fiscal_events` row's values (kept for backward-compatible reads); they are no longer an independent chain.
- **One ingestion path.** The device posts **all** fiscal events — `SALE_RECEIPT` included — through the single fiscal-event endpoint to `OutboxIngestor` (§7). `/pos/receipts/sync` is retired (clean rebuild, no live data — no compatibility adapter; cleanup checklist in §14.1).

**The bounded-modules seam — asymmetric dependency; direction is what matters** `[SoT §13.6 as refined, D16]`:

The v3 review correctly caught that v3 over-claimed "zero Treasury dependency" for the POS-core projection while `ReceiptPayment` imports Treasury's `PaymentMethod`, relates to it, and `pos_receipt_payments.payment_method_id` FKs to `payment_methods` `[Codex v3]`. The corrected model — per `[SoT §13.6 as refined]` — is asymmetric:

- **Inbound — setup / reference data is mirrored and may be referenced (permitted).** Payment methods, tenders, payment repositories, and products are defined once in the web during setup and synced to the POS local mirror `[reality §3 — the POS mirrors `payment_methods` + `payment_repositories`]`; the POS already operates offline-first against that mirror. `ReceiptPayment` (the POS's own payment record) referencing the mirrored `payment_method_id` is a **permitted inbound setup dependency** — it is *not* a coupling to the Treasury operational module, and it does not block a POS-only deployment (the reference data is in the mirror regardless of which operational modules are active).
- **Outbound — the fiscal engine depends on no other module's operations (forbidden to couple).** The `FiscalEventEngine`, the authoring/sealing/chain, and the server-side `OutboxIngestor` + ingestion path have **zero** dependency on the Treasury *operational* module — Treasury `Payment` rows, GL postings, `PaymentAllocationService` / FIFO allocation. The engine **publishes** fiscal events; modules **consume** them via a pluggable boundary.

**The mechanism — the projector registry + the activation resolver:**

- After `OutboxIngestor` verifies and stores a `fiscal_events` row, it dispatches the event to every **active projector** registered for that event type, via a `FiscalEventProjectionRegistry` (§7.3).
- The **POS-core projection always runs** (it is part of the POS module). **Module bridges run only when their module is active**, decided by a new **`ModuleActivationResolver`** (§7.3) — a server-side abstraction that answers "is module M active for `(tenant, company)`?". The engine, `OutboxIngestor`, and registry depend only on the `FiscalEventProjector` interface + the `ModuleActivationResolver` interface — **never** on Treasury, accounting, or sales code directly.
- In Phase 1 there is exactly one bridge — the **Treasury receipt bridge** (§7.4) — bound when the Treasury module is active. The reference deployment composes Treasury in, so the bridge runs there; a deployment with the Treasury module inactive runs only the POS-core projection and is still functional (it produces the POS's own records + local statistics; it just produces no Treasury `Payment`/GL effects).

"REUSE" of receipt code therefore means reuse of its **business-assembly and business-projection logic**, relocated into the POS-core projection (POS effects) and the Treasury bridge (Treasury effects) — not reuse of its independent sealing/chaining/sync — see §14.

### 5.1 `HashChainIntegrityProvider`

The first and only provider in Phase 1. Honestly named — **sequence integrity, not authorship** `[SoT §4.1]`. `signature_version = 'hash-chain-integrity-v1'`.

```
interface FiscalIntegrityProvider {
    version(): string
    canonicalBytes(event): bytes
    computeHash(canonicalBytes): hex64           // SHA-256, lowercase hex
    verify(event): bool                          // recompute over stored canonical_bytes; compare current_hash
}
```

### 5.2 `SignatureProviderInterface` — designed-for, not built

`[SoT §4.2]`. Phase 1 ships the **interface** and the **nullable signature columns** (§3) — no concrete provider. Async-capable (`sign()` may return `pending`); capability flags (`requires_connectivity`, `signs_synchronously`, `assigns_transaction_id`). Every Phase 1 event is `signature_status = 'not_required'`. "Signed" is reserved for jurisdictions with an active signature provider; pre-signature events are never retroactively upgraded.

---

## 6. The device-side fiscal event engine

The authority `[SoT §1.1]`. Lives in the Tauri app, against device SQLite. Reuses the proven receipt-sealing *patterns* `[reality §3]` — but as **one** engine now (§5.0).

### 6.1 `FiscalEventEngine.append()`

```
FiscalEventEngine.append(request: FiscalEventEmissionRequest): FiscalEvent
  // runs inside the CALLER's SQLite transaction (the caller — e.g. the
  // receipt assembler — wraps append() together with its projection writes):
  1. resolve the payload DTO + event_version via FiscalEventPayloadRegistry;
     throw FiscalEventTypeNotImplemented if the type has no Phase-1 handler
  2. device-side idempotency: if (source_event_class, source_event_id) is set
     and a local row exists, return it
  3. read the single fiscal-event chain head (§6.2): previous_hash, sequence_number
  4. build canonical_bytes (§4); current_hash = HashChainIntegrityProvider.computeHash(...)
  5. INSERT the fiscal_events row (sync_status='pending', signature_status='not_required')
  6. advance the chain head: fiscal_event_last_hash := current_hash,
     fiscal_event_sequence := sequence_number
  // the caller commits the transaction; append() does not commit
  7. (post-commit, scheduled by the caller) debounced sync flush
```

`append()` is the **only** way a fiscal event is authored — including `SALE_RECEIPT` (§5.0). It runs **inside the caller's transaction** so the receipt assembler can wrap `append()` + the `offline_receipts` projection write + voucher updates atomically.

### 6.2 The single device chain head

`terminal_state` `[reality §3]` gains **one** set of fiscal-event chain-head columns: `fiscal_event_genesis_seed`, `fiscal_event_last_hash`, `fiscal_event_sequence`. The genesis seed is issued once by the server at terminal provisioning. There is no separate receipt chain head — the legacy `last_hash` / `hash_sequence` columns are retained only as a backward-compatible mirror of the fiscal-event chain values (§5.0), not advanced independently.

---

## 7. The server-side verify-only mirror + the projection seam

`[SoT §1.1, §11, §13.6]`. **One** ingestion path (§5.0). Ingestion into the ledger is synchronous and atomic; **business projection out of the ledger is a separate, per-module, retryable concern** — this distinction is the heart of the v2 BLOCKER 2 fix and is consistent with the reality audit's "synchronous no-duplicate bridge contract" `[reality §7.1]` (which governs facts entering `fiscal_events`, not effects projected out of it).

### 7.1 The single fiscal-event ingestion endpoint

A new endpoint `POST /api/v1/pos/sync/fiscal-events` (under the existing `api/v1` POS prefix `[reality §3]`) and a new `OutboxIngestor`. The device posts **all** fiscal events here — `SALE_RECEIPT` included. `/pos/receipts/sync` is retired (§14.1). The transport carries a typed envelope `{ envelope_id, type: 'FISCAL_EVENT', payload_version, payload, idempotency_key }`; the idempotency key is `(terminal_id, sequence_number)`.

### 7.2 `OutboxIngestor.ingest()` — validate-then-insert, explicit conflict handling

```
OutboxIngestor.ingest(envelope):                          // standalone operation, not nested in a business transaction

  // ---- Step 1: validate against the envelope, BEFORE any insert ----
  // these checks decide integrity_status / payload / exception class for the insert
  hash_ok      = (SHA-256(envelope.canonical_bytes) == envelope.current_hash)        // else canonical_hash_mismatch
  linkage_ok   = (envelope.previous_hash == prior event's current_hash for terminal) // else sequence_gap
  clock_ok     = clock check (§10)                                                  // else time_anomaly
  parse_result = strict-parse envelope.canonical_bytes → payload (§7.6)              // else canonical_parse_failure
  derive integrity_status ('verified' | 'quarantined' + class + mandatory reason)
         and payload / payload_parse_status from the above

  // ---- Step 2: attempt the atomic insert (in transaction T1) ----
  INSERT INTO fiscal_events (... , server_received_at = now(), integrity_status, payload, ...)
  VALUES (...)
  ON CONFLICT (tenant_id, terminal_id, sequence_number) DO NOTHING
  RETURNING id;

  // ---- Step 3: inserted ----
  if a row was returned:
      within the SAME T1: insert one fiscal_event_projections row (status='pending')
          per active projector for this event_type (unless suppressed — §7.5)
      commit T1
      after commit: enqueue one projection job per pending row (§7.5)
      return the stored fiscal_events row

  // ---- Step 4: conflict — NOT automatically success ----
  if NO row returned:
      existing = SELECT * FROM fiscal_events
                 WHERE (tenant_id, terminal_id, sequence_number) = envelope's key
      if existing.id == envelope.id
         AND existing.current_hash == envelope.current_hash
         AND existing.canonical_bytes == envelope.canonical_bytes
         AND existing.source_event_class IS NOT DISTINCT FROM envelope.source_event_class
         AND existing.source_event_id   IS NOT DISTINCT FROM envelope.source_event_id:
          // a genuine idempotent re-delivery of the SAME event
          return existing                                  // duplicate success; do NOT re-dispatch projection
      else:
          // a DIFFERENT event claims an already-occupied sequence slot — a chain incident.
          // it physically cannot enter fiscal_events (the UNIQUE key blocks it).
          INSERT the conflicting envelope into fiscal_event_quarantine (§8) — the typed
                 envelope verbatim PLUS the query-critical metadata columns —
                 with integrity_exception_class = 'sequence_conflict' + mandatory reason
          raise an admin alert
          this terminal's chain enters recorded incident state; resolution is a
                 CHAIN_BREAK_DETECTED / CHAIN_RESTART recovery path (§9)
          return a sequence_conflict result — NOT idempotent success
```

`INSERT … ON CONFLICT DO NOTHING RETURNING id` remains the atomic primitive: two concurrent deliveries of the same event cannot both insert; the loser falls through to Step 4 and, finding an *identical* existing row, returns idempotent success. A *non-identical* conflict is never silently acknowledged — it is quarantined and raised as a chain incident (§8). The row is **always** persisted somewhere — `fiscal_events` (verified or in-table-quarantined) or `fiscal_event_quarantine` (sequence_conflict) — and the device is never blocked.

### 7.3 The projection seam — `FiscalEventProjectionRegistry` + `ModuleActivationResolver`

After Step 3 stores a `fiscal_events` row, `OutboxIngestor` dispatches the event to its **active projectors** `[SoT §13.6, D16]`.

```
interface FiscalEventProjector {
    name(): string                          // e.g. 'pos_core_receipt', 'treasury_receipt_bridge'
    handlesEventType(type): bool             // which FiscalEventType values this projector consumes
    requiresModule(): ?string                // null = always active (POS-core);
                                             //   the CANONICAL module token otherwise — e.g. 'Treasury'
    apply(fiscalEvent): void                 // idempotent, keyed on (fiscal_event_id, projector name)
}

interface ModuleActivationResolver {
    isActive(module: string, tenantId, companyId): bool
}

FiscalEventProjectionRegistry.activeProjectorsFor(fiscalEvent):
  for each registered projector P where P.handlesEventType(fiscalEvent.event_type):
     if P.requiresModule() is null                                              → include P
     else if moduleActivationResolver.isActive(P.requiresModule(),
                                                fiscalEvent.tenant_id,
                                                fiscalEvent.company_id)          → include P
```

- **POS-core projection** (`requiresModule() = null`) — always registered, always runs. The POS module owns it.
- **Treasury receipt bridge** (`requiresModule() = 'Treasury'`) — registered always, but `activeProjectorsFor()` includes it only when `ModuleActivationResolver.isActive('Treasury', …)` returns true.
- **Canonical module token — load-bearing contract (resolves Opus v4 P1).** `requiresModule()` returns the **exact module identifier used by `Vertical::defaultModules()`**, which is **PascalCase** — `'Treasury'`, `'Accounting'`, `'Sales'`, `'Inventory'` `[Opus v4 — `Vertical.php:124-136`]`. `CompanyConfig::hasModule()` does a **strict** `in_array(..., true)` comparison `[Opus v4 — `CompanyConfig.php:50-53`]`, so a lowercase `'treasury'` token would always resolve `false` and the Treasury bridge would never run — *including in the reference deployment*. The token is therefore pinned to `'Treasury'` here, and §17.3 test-locks it (`isActive('Treasury', …)` returns `true` for a standard seeded tenant). The `ModuleActivationResolver` Phase 1 implementation passes the token through to `hasModule()` without transformation; if a future implementation needs case normalization it does so internally, but the *contract* token in `requiresModule()` stays the canonical PascalCase identifier.
- **`ModuleActivationResolver`** — a Phase 1 server-side abstraction. Its **interface is per-`(tenant, company)`** so the seam is correctly shaped and testable. Its **Phase 1 implementation** delegates to the existing module-activation surface — `CompanyConfigService` / `CompanyConfig::hasModule()` over `allEnabledModules`, the same surface `RequireModule` reads `[Codex v3 — `RequireModule.php:38-63`, `CompanyConfig.php:47-53`, `CompanyConfigService.php:29-38`]`. **Caveat, carried as an open item (§18):** that surface is currently tenant-level-cached, not genuinely per-company, and every current vertical default includes `Treasury` and `Accounting` — Treasury is not even a `compatibleExtras()` toggle, so no current vertical can run with it inactive `[Opus v4 — `Vertical.php:104-114,124-136`]`. Phase 1 therefore makes the *seam* real and testable (a test constructs the resolver to report Treasury inactive and asserts POS-core still succeeds with the bridge excluded — i.e. the seam is exercised by a test double, not yet by a production deployment); making a *production* Treasury-inactive deployment real is a later config-model change, not Phase 1 (§18).
- The engine and `OutboxIngestor` depend only on the `FiscalEventProjector` and `ModuleActivationResolver` interfaces — **never** on Treasury, accounting, or sales code directly `[SoT §13.6]`.

### 7.4 The Phase 1 projectors

**`PosCoreReceiptProjection`** — always runs; **POS module**. For a `SALE_RECEIPT` fiscal event it creates the POS-core business effects:
- the `pos_receipts` projection row + lines + VAT breakdown,
- `ReceiptPayment` rows — the **POS's own payment record**,
- voucher redemption (`ReceiptSyncService.php:683-710`),
- stock movement (`ReceiptSyncService.php:713-735`).
These are the business-effect logic of the old `ReceiptSyncService` (its batch transport/orchestration is *not* reused — only its business effects `[Codex v3 confirms `ReceiptSyncService` owns these effects]`). **`ReceiptPayment` row creation is POS-core regardless of input path (resolves Opus v4 P3-1):** two live code paths create `ReceiptPayment` rows today — `ReceiptSyncService.php:598-609` (the offline-sync path) **and** `ReceiptPaymentService.php:297` (the web/`storePayments` path) `[Opus v4]`. The relocation **splits `ReceiptPaymentService`** — its `ReceiptPayment::create` logic (`:297`) moves into `PosCoreReceiptProjection`, which is the **single owner** of `ReceiptPayment` row creation; only the Treasury `Payment` + GL logic goes to the bridge (below). `PosCoreReceiptProjection` is **idempotent**, keyed on `(fiscal_event.id, 'pos_core_receipt')`. Its dependencies on other modules are **inbound reference data only** `[SoT §13.6 as refined]`: `ReceiptPayment` references the mirrored `payment_method_id` / `repository_id` setup data (`ReceiptPayment` imports Treasury's `PaymentMethod` and `pos_receipt_payments.payment_method_id` FKs to `payment_methods` `[Codex v3 — `ReceiptPayment.php:8,148-154`, migration `:29-32`]` — a permitted inbound setup dependency, *not* a coupling to the Treasury operational module). It has **zero** dependency on the Treasury *operational* module (Treasury `Payment` rows, GL, allocation) — a deployment with Treasury inactive runs this projector to completion, `ReceiptPayment` rows included.

**`TreasuryReceiptBridge`** — runs only when the Treasury module is active (per the `ModuleActivationResolver`); **Treasury module**. For a `SALE_RECEIPT` fiscal event it creates **only** the Treasury-operational effects — the relocation takes the Treasury `Payment` + GL portion of `ReceiptPaymentService` (`ReceiptPaymentService.php:252`, `:269`), **not** its `ReceiptPayment::create` portion (`:297`, which is POS-core, above) `[Opus v4 P3-1; Codex v3 — `ReceiptPaymentService.php:35-43,252-276`]`:
- one Treasury `Payment` row per receipt payment line, stamped `origin = pos` **and** `fiscal_event_id = <the SALE_RECEIPT event id>` (§13),
- the direct-to-revenue GL posting via `GeneralLedgerService::createPOSPaymentEntry()`.
It is **idempotent**, keyed on `(fiscal_event.id, 'treasury_receipt_bridge')`. It accepts `fiscal_event_id` and `origin` as inputs — the bridge is where those fields are populated.

> **Resolves v2 BLOCKER 1 and v3 BLOCKER.** The POS-core effects and the Treasury-operational effects are correctly attributed and split across the two projectors — and `ReceiptPaymentService` is **split, not relocated wholesale**: its `ReceiptPayment::create` is POS-core, its Treasury `Payment` + GL is the bridge. The POS-core projector's only cross-module dependency is *inbound mirrored reference data*, which `[SoT §13.6 as refined]` explicitly permits; the *outbound* dependency on the Treasury operational module is gated entirely behind the bridge + resolver.

### 7.5 Projection failure / retry / resume contract

- **`fiscal_events` persists in its own transaction** (the §7.2 Step 2 insert, T1). Once committed, the fiscal event is durable, authoritative chain truth — independent of any projection outcome.
- A **`fiscal_event_projections`** table tracks each projector's run:

```
fiscal_event_projections {
  id                   UUID         PK
  fiscal_event_id      UUID         NOT NULL  REFERENCES fiscal_events(id)
  projector_name       VARCHAR(64)  NOT NULL            -- 'pos_core_receipt' | 'treasury_receipt_bridge'
  projection_status    VARCHAR(20)  NOT NULL DEFAULT 'pending'   -- pending|running|applied|dead_lettered
  attempts             INTEGER      NOT NULL DEFAULT 0
  last_error           TEXT
  last_attempted_at    TIMESTAMPTZ
  applied_at           TIMESTAMPTZ
  dead_lettered_at     TIMESTAMPTZ
  created_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW()
  updated_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW()
  UNIQUE (fiscal_event_id, projector_name)
}
```

  This table is **mutable** (it is not chain truth) and carries no immutability trigger.
- **Queue ownership of transient state.** Retry, exponential backoff, worker locking, and concurrency control are owned by the **Laravel queue (Horizon)** `[CLAUDE.md tech stack — Redis 7+, Laravel Horizon]` — the projection job is a standard queued job with `tries` / `backoff`. The `fiscal_event_projections` table holds only the **durable, operator-visible** state: `pending` (created, not yet picked up), `running` (a worker claimed it; set at job start), `applied` (succeeded), `dead_lettered` (the queue exhausted retries — set by the job's `failed()` handler). `attempts` / `last_attempted_at` / `last_error` mirror the queue's view for the operator; `dead_lettered_at` / `applied_at` are the terminal-state timestamps.
- **Dispatch:** in the §7.2 Step 2 transaction (T1), `OutboxIngestor` inserts one `pending` `fiscal_event_projections` row per active projector for the event type. After T1 commits, it enqueues one projection job per row.
- **Apply:** each projection job runs `projector.apply(fiscalEvent)` in **its own transaction**, idempotently (keyed on `(fiscal_event_id, projector_name)`). On start → `running`. On success → `applied`, `applied_at` set. On failure → the queue retries with backoff (`last_error` / `attempts` / `last_attempted_at` updated each attempt). When the queue exhausts retries → the job's `failed()` handler sets `dead_lettered` + `dead_lettered_at`, raises an **operator alert**, and the row is surfaced in an operator-visible dead-letter view for **manual replay** (re-runs the same idempotent `apply()`).
- **Suppression for `canonical_parse_failure`:** if the event was quarantined with `canonical_parse_failure`, **no `fiscal_event_projections` rows are created** and no projection is dispatched — there is no trusted `payload` to project.
- **Resume after parse-failure resolution (resolves v3 P1; transaction boundary specified — resolves Opus v4 P2-2):** when an authorized operator/developer resolution resolves a `canonical_parse_failure` event, the **`payload` write + the `payload_parse_status → parsed` flip + the `pending` `fiscal_event_projections` row inserts** (one per currently-active projector) happen in **one transaction** — mirroring the §7.2 Step 2/3 pattern; the queue enqueue of the projection jobs happens **after that transaction commits**. This matters because §3.3 makes `payload` and `payload_parse_status` **write-once**: a non-atomic sequence that committed the flip but crashed before creating the projection rows would leave a `parsed` event that the normal resolution action can never retry (the write-once trigger raises on a second `payload` write). With the inserts inside the same transaction, the only post-commit failure window is the enqueue, which is idempotently recoverable. **`fiscal:enqueue-resolved-event-projections`** (§15.2) is the **named recovery path** for the "`payload_parse_status` is `parsed` but `fiscal_event_projections` rows are missing or some are still `pending` and unenqueued" state — it is invoked by the resolution action after commit, and is also runnable standalone by an operator. A test asserts both the resolve→project path and the crash-between-commit-and-enqueue recovery (§17.3).
- **Suppression vs proceed for other classes:** `canonical_hash_mismatch` and `time_anomaly` events are accepted-and-flagged and **projection proceeds** (the transaction physically happened and counts, per `[SoT §7.1]`); the resulting `pos_receipts` / `Payment` / GL rows inherit the flag via the event's `integrity_status`, and operator resolution can reverse them with compensating events if warranted. `sequence_conflict` events never reach `fiscal_events` at all (§7.2 Step 4, §8) — they have no `fiscal_event_projections` rows.
- **Invariant:** a projection failure **never** mutates or deletes the `fiscal_events` row. Fiscal truth is preserved regardless of business-projection outcome `[SoT D1, D13]`. Conversely, a projection is never run inside the fiscal-event insert transaction — "the row is always persisted" (§7.2) is never in tension with a projection failure.

### 7.6 The strict parser

The structured `payload` JSONB is derived server-side from the verified `canonical_bytes` by a strict parser — rejects duplicate keys, out-of-grammar numbers, invalid Unicode, event-type schema violations `[SoT §5]`. On failure: `payload = NULL`, `payload_parse_status = 'failed'`, `integrity_status = 'quarantined'`, `integrity_exception_class = 'canonical_parse_failure'`. Canonical bytes remain authoritative.

---

## 8. Per-anomaly-class integrity exceptions + quarantine

`[SoT §7]`. **Accept-and-flag per anomaly class — never block the device.**

| Class | Trigger | Persisted to | Handling |
|---|---|---|---|
| `canonical_hash_mismatch` | `SHA-256(canonical_bytes) ≠ current_hash` | `fiscal_events`, `integrity_status='quarantined'` | accept, quarantine, annotate; counts, flagged; projection proceeds (§7.5) |
| `canonical_parse_failure` | bytes hash OK but fail the strict grammar | `fiscal_events`, `integrity_status='quarantined'` | accept, quarantine, annotate; **`payload` NULL, projection suppressed** until resolution → resume via `fiscal:enqueue-resolved-event-projections` (§7.5); raw bytes conserved |
| `time_anomaly` | clock rollback / excessive drift (§10) | `fiscal_events`, `integrity_status='quarantined'` | accept, quarantine, annotate; projection proceeds (§7.5) |
| `sequence_gap` | gap/break in the per-terminal sequence detected at linkage check | `fiscal_events`, `integrity_status='quarantined'` | accept; chain in recorded incident state; the gap is an **explicit exception total**, not silently in clean totals |
| `sequence_conflict` | a *different* event claims an already-occupied `(tenant,terminal,sequence)` slot (§7.2 Step 4) | **`fiscal_event_quarantine`** — it cannot enter `fiscal_events` (UNIQUE key) | persist the typed envelope verbatim + metadata; admin alert; chain incident → `CHAIN_BREAK_DETECTED` / `CHAIN_RESTART` (§9); **never idempotent success** |

`sequence_conflict` is the "break in the per-terminal sequence" case of `[SoT §7.1]`'s `sequence_gap` family, distinguished here because its storage path differs: the conflicting envelope physically cannot occupy the taken sequence slot in `fiscal_events`, so it goes to a dedicated **`fiscal_event_quarantine`** table. In-table `integrity_status='quarantined'` covers admitted-but-flagged events; `fiscal_event_quarantine` covers non-admissible envelopes.

```
fiscal_event_quarantine {
  id                          UUID         PK
  -- query-critical metadata, mirrored from the envelope (resolves v3 P1) --
  tenant_id                   UUID         NOT NULL
  company_id                  UUID         NOT NULL
  terminal_id                 UUID         NOT NULL
  operator_id                 UUID         NOT NULL
  envelope_event_id           UUID         NOT NULL       -- the device-claimed fiscal_events.id, for forensics
  event_type                  VARCHAR(64)  NOT NULL
  event_version               SMALLINT     NOT NULL
  signature_version           VARCHAR(64)  NOT NULL
  claimed_sequence_number     BIGINT       NOT NULL
  event_time_device           TIMESTAMPTZ  NOT NULL
  business_date               DATE         NOT NULL
  last_server_time_seen       TIMESTAMPTZ
  reference_event_id          UUID
  reference_document_id       UUID
  source_event_class          VARCHAR(255)
  source_event_id             UUID
  previous_hash               CHAR(64)     NOT NULL
  current_hash                CHAR(64)     NOT NULL
  -- the conserved envelope --
  canonical_bytes             BYTEA        NOT NULL        -- the conflicting envelope's canonical bytes, verbatim
  raw_envelope                JSONB        NOT NULL        -- the full typed transport envelope as received
  payload_parse_status        VARCHAR(16)                  -- if a strict parse was attempted before the conflict was detected
  -- incident metadata --
  integrity_exception_class   VARCHAR(32)  NOT NULL        -- 'sequence_conflict' in Phase 1
  integrity_exception_reason  TEXT         NOT NULL
  conflicting_event_id        UUID         NOT NULL        -- the fiscal_events row already holding the slot
  server_received_at          TIMESTAMPTZ  NOT NULL
  resolved_at                 TIMESTAMPTZ
  resolved_by                 UUID
  created_at                  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
}
```

The quarantine row is **self-contained**: `canonical_bytes` + `raw_envelope` conserve the conflicting event verbatim, and the mirrored metadata columns make the incident queryable, exportable, and resolvable with the same fidelity as an admitted `fiscal_events` row. **Field usage:** the `fiscal:verify-event-chain` command (§15) reports `fiscal_event_quarantine` rows for the terminal using `claimed_sequence_number`, `envelope_event_id`, `current_hash`, `conflicting_event_id`; JET / fiscal export reads the same columns plus `event_type`, `business_date`, `canonical_bytes` to render the incident in the quarantine reconciliation section.

`signature_invalid` does not arise in Phase 1 (no signature provider active). A quarantined event — in either table — is **always persisted** with a **mandatory structured reason**, an admin alert, and an operator-resolution path. **Exports include a quarantine section with reconciliation totals — never silent exclusion** `[SoT §7.2]`; the export reconciliation must span both quarantine surfaces.

---

## 9. Chain-recovery events

`[SoT §7.3]`. On a local chain break, the terminal continues operating in a recorded `degraded` mode. Recovery is **two chained incident events** (not "signed" — no signature provider in Phase 1): `CHAIN_BREAK_DETECTED` (reason, last-good sequence + hash, offending record reference) and `CHAIN_RESTART` (new genesis reference, last-good anchor, operator authorization evidence, provenance link to the prior chain). Both are ordinary `fiscal_events` rows. The broken segment is never deleted — quarantined (§8), synced, flagged in exports. A server-detected `sequence_conflict` (§7.2 Step 4) is one trigger for this recovery path.

---

## 10. Clock / time model

`[SoT §6]`. The device clock is untrusted `[reality §3]`. Unchanged from v3: `event_time_device` (untrusted), `sequence_number` (the authoritative ordering), `last_server_time_seen`, `server_received_at`. Clock-rollback and drift detection → `time_anomaly` (§8), accepted not blocked. Normative closure-period rule: `business_date` is assigned by the **terminal-configured fiscal timezone and session boundary** — not `server_received_at`, not raw device time; a clock anomaly never moves an event between closure periods without an explicit correction event.

---

## 11. Company-level integrity record types

`[SoT §9]`.

### 11.0 Server-authoring carve-out for company-integrity events (LOCKED)

The §1 device-authority rule (the device is the fiscal source of truth; the server is verify-only `[SoT §3, §4]`) applies to **per-device transaction events** — first-class `SALE_RECEIPT` and its chain-recovery cousins `CHAIN_BREAK_DETECTED` / `CHAIN_RESTART`. The company-integrity event types listed in this section (`TERMINAL_REGISTRY_SNAPSHOT` implemented; `COMPANY_DAY_CLOSURE_MANIFEST` reserved) are **company-level facts**, not per-device transactions, and therefore have a bounded server-authoring carve-out:

- They have no per-terminal chain linkage at the *business-fact* layer (they're authored against the company-integrity chain, not any single terminal's session chain); the per-terminal chain placement is forensic linkage only.
- They are operator/server-side facts — the authoritative roster of terminals at provisioning time (or on demand from an operator path), the day-closure manifest at end-of-day — that have **no device-side trigger event** the device could author from.
- A device-authoring path would require each device to know the authoritative set of OTHER devices (a contradiction — each device only knows its own state); the company-integrity facts are by construction observed at a layer above any single terminal.

Therefore, the server **IS** authorized to author these specific event types directly, BUT must satisfy the following invariants (any future event type added to §11 must declare whether it falls under this carve-out OR follows the §1 default):

1. **`signature_status = NotRequired`** — no device signature is available for a server-authored fact (the device never produced it).
2. **`integrity_status = Verified` + `payload_parse_status = Parsed`** — the server is trusted to assert integrity for events it authored, having owned the canonical-bytes serialization itself.
3. **`FiscalPayloadConstraintValidator` runs BEFORE the persist** — the same per-event payload-shape gate the parse path runs (`StrictCanonicalParser` for ingested device events, `ParseFailureResolutionService` for corrected payloads). The server MUST invoke `validatePayloadKeySet()` + `validatePerEventConstraints()` and refuse to write `payload_parse_status = Parsed` without it. Failure raises `InvalidServerAuthoredPayloadException` and rolls the persist back.
4. **Chain anchor is deterministic** — first event on the authoring terminal chain uses `previous_hash = pos_terminals.genesis_seed` (mirrors device-side anchoring per §3); subsequent events chain off the prior `current_hash` for the same terminal. The chain placement step takes a row-level lock on `pos_terminals` for the authoring terminal so concurrent emissions serialize cleanly (no UNIQUE-violation race on `(tenant_id, terminal_id, sequence_number)`).
5. **Device-side carve-out enforcement (cross-language drift gate)** — the device-side `FiscalEventEngine.append()` MUST reject these event types so no code path can ever author them on the device (closing the §1 ambiguity at the device boundary). The TS registry treats them as `serverOnly`. This is enforced by the same cross-language drift test surface that locks the implemented set (Task 14 standing pattern).

This carve-out is **bounded to the event types explicitly listed in §11** (and any future §11 entry that explicitly declares it). It does NOT loosen the §1 device-authority rule for any other event type, and it does NOT permit server re-serialization of any device-authored event (§4 remains absolute for SALE_RECEIPT + chain-recovery cousins).

### 11.1 Implemented + reserved event types

- **`TERMINAL_REGISTRY_SNAPSHOT` — implemented in Phase 1 (server-authored per §11.0).** It has no closure dependency: a registry snapshot (the authoritative list of terminals expected for a company at a point in time; carries a hash; links to the prior snapshot) can be emitted at terminal provisioning and on demand. Phase 1 delivers the event type, the payload DTO, the **server-authored emission service** (`TerminalRegistrySnapshotService`, satisfying the §11.0 invariants), and an initial-snapshot emission path. The device `append()` rejects this event type (§11.0 #5).
- **`COMPANY_DAY_CLOSURE_MANIFEST` — reserved in Phase 1 (server-authored carve-out when implemented).** It depends on day-closures (a later phase). Phase 1 delivers the event type registration + the payload DTO schema only; `append()` throws `FiscalEventTypeNotImplemented` for it until the closure-rollout phase. When implemented, it falls under §11.0; the device `append()` already rejects it preemptively in Phase 1 so the boundary is locked.

Appendix A reflects this split.

---

## 12. Off-device durability controls

`[SoT §8]`. Unchanged from v3. Device authority is not survivable without off-device conservation; an on-device backup encrypted with an on-device key is not a conservation control `[reality §3 — plaintext `.izipos_key`]`. Phase 1 delivers: at least one off-device durability path (encrypted removable archive / LAN peer / NAS / cloud-sync) with key custody **outside** the terminal disk; the on-device AES-GCM copy as crash-recovery only; an operator-visible unsynced-risk indicator; a forced archive/export threshold; a maximum-unsynced escalation; a device-loss incident register. **A Phase 1 gate before any Phase 2 customer-facing deployment.**

---

## 13. `Payment.origin` / `Payment.fiscal_event_id` — Treasury-module integration

`[reality §2.3 — the complete Treasury `Payment` writer inventory]`. **This section is Treasury *operational*-module integration work, not POS-core** `[SoT §13.6, D16]`: the `payments` table belongs to the Treasury module, every writer below is Treasury-module code, and the new `fiscal_event_id` FK runs `payments → fiscal_events` — the correct dependency direction (a module depends on the engine; the engine never depends on the module). The POS base does **not** depend on Treasury `Payment` rows existing. It is delivered in Phase 1 *for the reference deployment* (which composes Treasury in) and is consumed by the `TreasuryReceiptBridge` (§7.4); a deployment with Treasury inactive neither has nor needs it.

> **Note on `payment_methods` vs `payments`.** This section concerns the Treasury *operational* table `payments` (the `Payment` rows + their writers). It does **not** touch `payment_methods` / tenders / repositories — those are *setup reference data* the POS mirrors and references (§5.0), deliberately left in place (§18). The two are different boundaries: `payments` is the operational dependency the bridge gates; `payment_methods` is the inbound reference data the POS is permitted to reference.

- **Migration:** add `payments.origin VARCHAR(32) NULL` and `payments.fiscal_event_id UUID NULL` (FK `fiscal_events(id)`).
- **`PaymentOrigin` enum:** `pos | web_admin | mobile | api | unknown_legacy`.
- **`Payment` model:** add both to `$fillable`; cast `origin` to `PaymentOrigin`.
- **Writer updates — the COMPLETE Treasury `Payment` writer inventory, all in the same change** `[reality §2.3; confirmed exhaustive by the v2 and v3 Codex reviews' live greps]`:

| Writer | `origin` |
|---|---|
| `ReceiptPaymentService` (POS receipt payment lines) — invoked via the `TreasuryReceiptBridge` (§7.4) | `pos` — **and `fiscal_event_id` set to the `SALE_RECEIPT` event id** |
| `PaymentController::store()` | `web_admin` |
| `PaymentController::storeMultiple()` | `web_admin` |
| `MultiPaymentService::createSplitPayment()` | `web_admin` |
| `MultiPaymentService::recordDeposit()` | `web_admin` |
| `MultiPaymentService::recordPaymentOnAccount()` | `web_admin` |
| `PaymentRefundService::refundPayment()` | inherit the original payment's `origin` |
| `PaymentRefundService::partialRefund()` | inherit the original payment's `origin` |
| `PaymentRefundService` receipt-proration refund rows | inherit the original payment's `origin` |
| `VendorRefundService::refundPrepayment()` | `web_admin` |

`App\Modules\Billing\Domain\Payment` is a separate model — **not** in scope. Any pre-existing rows (preflight gate permitting) → `unknown_legacy`. For non-fiscal web/admin payments `fiscal_event_id` stays NULL. This changes **no** GL behavior, allocation behavior, or payment semantics — purely additive metadata. Tests cover **every** writer in the table above.

---

## 14. The receipt-chain clean rebuild

The existing receipt chain server-recomputes-and-overwrites and throws-and-rolls-back on mismatch — the source of the production chain-breaks. It is **discarded and rebuilt clean** as a **consumer of the engine** (§5.0). **No migration** (preflight gate, §2).

| Component | Disposition | Detail |
|---|---|---|
| `receiptService.ts` | **REWORK** | Becomes the `SALE_RECEIPT` business-document assembler — calls `FiscalEventEngine.append()` inside one SQLite transaction (§5.0, §6.1). Its business-assembly logic is reused; its independent hash/seal/chain code is replaced by `append()`. |
| `apps/pos/src/lib/offline/offlineCheckoutService.ts` — `executeCheckout()` / `onlineCheckout()` (resolves Codex v5 BLOCKER) | **REWORK (`onlineCheckout` branch DISCARDED)** | `executeCheckout()` (`:178-199`) currently picks `onlineCheckout()` whenever connectivity is online; `onlineCheckout()` (`:83-115`) server-authors via `createReceipt()` / `processReceiptPayments()`, bypassing `receiptService.ts` `[Codex v5]`. Post-rebuild `executeCheckout()` has **one path** — it always authors locally via the `receiptService.ts` assembler → `FiscalEventEngine.append()` (i.e. always what `offlineCheckout()` does today); the `onlineCheckout()` server-authoring branch is **discarded**. Connectivity affects only whether an immediate sync flush follows the local seal. **Behavior change:** online Tauri checkouts move from server-authored-immediate to device-authored-then-sync — which *is* device authority `[SoT §1, D1]`. |
| `apps/pos/src/api/receiptApi.ts` — `createReceipt()` / `processReceiptPayments()` | **DISCARD (new-sale authoring) / REUSE (reads)** | `createReceipt()` (`:14-17` → `POST /pos/receipts`) and `processReceiptPayments()` (`:19-27` → `POST /pos/receipts/{id}/payments`) are the Tauri callers of the server-authoring new-sale routes — **discarded** for new-sale authoring (the only production caller is `onlineCheckout()`, above). `fetchReceipt()` and other read methods are unaffected. |
| `terminal_state`, `offline_receipts` (client) | **REWORK** | `terminal_state` gains the single fiscal-event chain head (§6.2); `offline_receipts` becomes a projection row mirroring the `fiscal_events` chain values, gains `canonical_bytes`. |
| `ReceiptSyncService` — batch transport/orchestration | **DISCARD** | `/pos/receipts/sync` is retired; the device posts to the single fiscal-event endpoint (§7.1). Cleanup checklist: §14.1. |
| `ReceiptSyncService` — stock/voucher/`ReceiptPayment` business-effect logic | **REUSE (relocated → `PosCoreReceiptProjection`)** | Extracted into the POS-core projection (§7.4), invoked idempotently by `OutboxIngestor`. This is `pos_receipts`/lines/VAT, `ReceiptPayment` rows, voucher redemption, stock movement — **POS module**. Its only cross-module dependency is inbound mirrored reference data (§5.0). |
| `ReceiptPaymentService` — **split** across two projectors | **REUSE (split — relocated → `PosCoreReceiptProjection` + `TreasuryReceiptBridge`)** | `ReceiptPaymentService` creates *both* the POS-core `ReceiptPayment` row (`:297`) *and* the Treasury `Payment` + GL (`:252`, `:269`) `[Opus v4 P3-1]`. The split: `ReceiptPayment::create` (`:297`) → `PosCoreReceiptProjection` (POS module, always runs); the Treasury `Payment` + `GeneralLedgerService::createPOSPaymentEntry()` portion → `TreasuryReceiptBridge` (Treasury module, gated by the `ModuleActivationResolver`; accepts `fiscal_event_id` / `origin`). It is **not** relocated wholesale. |
| `ReceiptFinalizationService::finalize()` (server-side seal + chain advance) | **DISCARD (for new-sale `SALE_RECEIPT`)** | `finalize()` is a server-side **authoring** act (it advances the terminal chain and writes `fiscal_hash`). For new-sale `SALE_RECEIPT` it is **not invoked server-side at all** post-rebuild — the device seals (§5.0). It is one of the two §14.3 chokepoints; every caller is dispositioned there. The void/return callers (`ReceiptReturnService`) are the knowingly-retained Phase 2+ carve-out (§14.2). |
| `ReceiptHashService::verifyTerminalChain()` / `verifyHash()` (server-side verification) | **REWORK** | From recompute-from-models → re-hash the stored `canonical_bytes`; verification walks the `fiscal_events` chain. Verification is read-only and stays; only the recompute-and-seal path is discarded (row above). |
| `ReceiptCreationService::createReceipt()` (server-side `SALE_RECEIPT` draft creation) | **§14.3 chokepoint** | The second of the two server-side `SALE_RECEIPT` authoring chokepoints. Every caller — `ReceiptController::store`, `OrderToReceiptService`, `ExchangeService` — is enumerated and dispositioned in §14.3. |
| `OfflineFiscalHashMismatchException` throw-and-rollback | **DISCARD** | Replaced by per-class accept/flag (§8). The class may remain as an audit-trail artifact; never thrown to block. Production import/throw at `ReceiptSyncService.php:23,629`; test deps at `ReceiptSyncServiceTrainingModeTest.php:136`, `ReceiptSyncServiceVoucherRedemptionTest.php:500` `[v2 Codex review]`. |
| `Compliance/FiscalHashService` chain-prefix logic | **REUSE** | Generic; unaffected. |
| `Nf525XmlBuilder` | **REUSE** | DTO→XML serialization of a derived export artifact — permitted under D2. |
| `Nf525DataProvider` + the JET verification path | **REWORK** | It reads `Receipt` models directly (`Nf525DataProvider.php:104-117`) and verifies by recomputing via `ReceiptHashService::calculateHash()` (`:278-318`) `[Codex v3]` — reworked to read verified `canonical_bytes` + quarantine state from `fiscal_events` (and `fiscal_event_quarantine`). |
| `canonical_bytes` columns (`pos_receipts` `BYTEA` / `offline_receipts` `TEXT`) | **NEW** | — |
| Per-class integrity-exception / quarantine path for receipts | **NEW** | Same machinery as §8. |
| JET export sections for quarantine / incidents / manifests | **NEW** | Reconciliation spans `fiscal_events` quarantine + `fiscal_event_quarantine`. |
| Web POS (`TerminalType::Web`) receipt-creation path | **DISPOSITION (preflight §2; checklist §14.2)** | Not reworked in Phase 1. Confirmed-unused or hidden/disabled so it does not coexist with the rebuilt chain (`[SoT D8]`); device-authority parity deferred (§18). |
| Backend new-sale-authoring routes — `POST /pos/receipts`, `POST /pos/receipts/{id}/payments` (`ReceiptController::store` / `storePayments`) | **DISPOSITION (checklist §14.2)** | Server-authoring of a new `SALE_RECEIPT` is retired for **all** callers — browser web POS *and* Tauri-online checkout (the two reworked client rows above). The routes are rejected/removed for new-sale `SALE_RECEIPT` authoring (`[SoT D8]`); any non-new-sale use is confirmed + preserved during the §14.2 cleanup. |
| POS order-close → receipt — `POST /pos/orders/{id}/close` (`OrderController::close` → `OrderManagementService::closeOrder` → `OrderToReceiptService::convertToReceipt` → `ReceiptCreationService::createReceipt()`) | **DISPOSITION (checklist §14.2)** `[Codex v6]` | Another server-authoring `SALE_RECEIPT` path — verified **`apps/web`-only** (no Tauri caller). Dispositioned with the web POS (§14.2): the order-close → receipt step is disabled/hidden for Phase 1; web-POS order management as a whole is part of the deferred web-POS device-authority parity (§18). |

`SALE_RECEIPT` is a **first-class fiscal event** on the single chain — no `SALE_RECEIPT_BRIDGE` (D10).

### 14.1 `/pos/receipts/sync` retirement — concrete cross-app cleanup checklist

The v2 and v3 Codex reviews verified the live dependency surface; the rebuild must clear all of it (no compatibility adapter unless the owner explicitly chooses one):

**POS client (`apps/pos`):**
- Replace `pushOfflineReceipts` / `receiptToPayload` (`syncService.ts:295,333-336,1767`) with the fiscal-event envelope push to `POST /api/v1/pos/sync/fiscal-events` (§7.1).
- Migrate the receipt-sync unit tests (`syncService.test.ts:293,800`) to fiscal-event ingestion tests.
- Migrate the **client integration tests** that import/call `pushOfflineReceipts` — `apps/pos/src/__tests__/integration/offlineFirstFlow.test.ts:110,279,340` `[Codex v3]`.
- Update `fetchWithTimeout.ts:30` and `fetchWithTimeout.test.ts:51,61` — the timeout helper/fixtures that encode the old path.

**Backend (`apps/api`):**
- Remove or hard-disable the `/pos/receipts/sync` route (`routes.php:85`) and `SyncController::syncReceipts` (`SyncController.php:49-68`).
- Retire or repoint the receipt-only old-sync classes `[Codex v3]`: `SyncReceiptPayload` (`SyncReceiptPayload.php:13`), `SyncReceiptsRequest` (`SyncReceiptsRequest.php:20`), `SyncReceiptResult` (`SyncReceiptResult.php:16`), and the receipt-sync entry surface of `ReceiptSyncService` (`ReceiptSyncService.php:54`). Any `SyncStatus` values that exist *only* to express receipt-sync semantics (e.g. the `SyncReceiptResult` `ChainBroken` factory path, `SyncReceiptResult.php:99-108`) are retired with them; `SyncStatus` values still used by other sync resources stay.
- Migrate the POS backend feature suites that target the retired endpoint — the `/api/v1/pos/receipts/sync` HTTP-endpoint suites `SyncReceiptsTest.php`, `SyncReceiptsRequestTest.php`, `ReceiptSyncServiceV3Test.php`, `ReceiptSyncServiceTrainingModeTest.php`, **`OfflineV3CutoverSyncTest.php`** `[Codex v5]`, plus the service-level suites `ReceiptSyncServiceInstrumentGuardTest.php`, `ReceiptSyncServiceVoucherRedemptionTest.php` `[Opus v4 P3-2]` — to fiscal-event ingestion tests.
- **`PosStabilizationTenantIsolationTest.php`** `[Codex v5]` is a **broader stabilization suite** that also asserts against `/pos/receipts/sync` (`:1366,1406`); migrate only its receipt-sync assertions to the fiscal-event ingestion path — do not delete the suite (its non-receipt-sync tenant-isolation assertions stay).

**Verification:** after cleanup, a repo-wide grep for `/pos/receipts/sync`, `pushOfflineReceipts`, `syncReceipts`, `SyncReceiptPayload`, `SyncReceiptsRequest`, `SyncReceiptResult` returns only the new fiscal-event path or intentional historical references — no live caller of the retired route. The grep is a *verification step*, not the specification of scope — the concrete classes above are the scope.

### 14.2 New-sale server-authoring disposition (web POS + Tauri-online) — concrete checklist

`[Codex v3 + Codex v5 + Codex v6 — the server-authoring `SALE_RECEIPT` path is live, reached by the browser web POS, the Tauri-online checkout branch, and POS order-close]`. A server-recompute *new-sale* `SALE_RECEIPT` authoring path cannot coexist with the rebuilt device-authority `SALE_RECEIPT` chain `[SoT D8]`. This checklist is the concrete cleanup surface; **§14.3 is the completeness authority** (the two-chokepoint rule + the full caller enumeration). The callers:
- the **browser web POS** new-sale flow — disabled here (it cannot device-author/seal; full device-authority parity is deferred, §18);
- the **POS order-close** flow (`apps/web`-only) — disabled here, with the rest of web-POS order management deferred (§18);
- the **Tauri-online checkout branch** — *reworked*, not just disabled: `executeCheckout()` is made connectivity-independent and always authors locally (the `offlineCheckoutService.ts` + `apps/pos/src/api/receiptApi.ts` rows in the §14 table above);
- the **backend new-sale-authoring surface** — `POST /pos/receipts` + `POST /pos/receipts/{id}/payments` *and* `POST /pos/orders/{id}/close` — retired/rejected for new-sale `SALE_RECEIPT` authoring for **all** callers.

**Scope of the "does not coexist" claim:** it applies to **new-sale `SALE_RECEIPT` authoring** — not to all receipt mutation; void/return are handled explicitly below. Concrete targets:

**Web frontend (`apps/web`):**
- Disable/hide the sale-completion flow in `POSTransactions.tsx` — the web-terminal resolution (`:111-124`), `createReceipt` call sites (`:313-318,366-368`), and `processReceiptPayments` / payment call sites (`:318-329,396`) `[Codex v3]`.
- The `receiptApi.ts` write methods backing them — `createReceipt` (`receiptApi.ts:73-77`), the payment post (`receiptApi.ts:121-128`).
- Disable/hide the **order-close** flow — `CloseOrderButton.tsx`, `OrderPanel.tsx` (`closeOrder.mutate`), `useOrders.ts` (`useCloseOrder`), `orderApi.ts:201` (`closeOrder` → `POST /pos/orders/{id}/close`) `[Codex v6]`. Order management as a whole is web-POS scope; its device-authority parity is deferred (§18).

**Tauri client (`apps/pos`):** reworked per the §14 table — `offlineCheckoutService.ts` (`onlineCheckout` branch discarded; `executeCheckout` always authors locally) and `apps/pos/src/api/receiptApi.ts` (`createReceipt` / `processReceiptPayments` discarded for new-sale authoring). Listed here so the cross-app surface is complete in one place. (No Tauri caller of order-close exists — verified `[Codex v6]`.)

**Backend (`apps/api`):**
- Retire/reject the new-sale-authoring routes for `SALE_RECEIPT` — `POST /pos/receipts` and `POST /pos/receipts/{id}/payments` (`routes.php:98,102`) and the `ReceiptController::store()` → `ReceiptCreationService::createReceipt()` (`ReceiptController.php:284-315`) and `ReceiptController::storePayments()` → `ReceiptPaymentService` (`ReceiptController.php:505-510`) paths `[Codex v3, Codex v5]` — for **all** callers (web *and* Tauri-online), not just `TerminalType::Web`. Any non-new-sale use of these routes/controllers is confirmed and preserved during cleanup.
- Retire/reject the **order-close → receipt** path — `POST /pos/orders/{id}/close` (`routes_orders.php:28`) → `OrderController::close` → `OrderManagementService::closeOrder` → `OrderToReceiptService::convertToReceipt` → `ReceiptCreationService::createReceipt()` `[Codex v6]`. The order-close *step that authors a `SALE_RECEIPT`* is disabled for Phase 1; the rest of order CRUD/lines/kitchen routes (`routes_orders.php`) are untouched here — their device-authority rework rides with the deferred web-POS order-management parity (§18).
- `routes.php:45` — confirm scope when implementing (the v3 review grouped it with the web-POS route block); disable only if it is part of the new-sale write surface.

**Knowingly retained server-side for Phase 1 — `void` and `processReturn` (resolves Opus v4 P2-1):** the web POS also has two further live server-authoring receipt paths — `ReturnItemsModal.tsx` → `POST /pos/receipts/{id}/return` → `ReceiptController::processReturn` (creates a new negative/return receipt server-side) and `ReceiptSearchPage.tsx` → `POST /pos/receipts/{id}/void` → `ReceiptController::void` (mutates receipt fiscal state via `ReceiptVoidService`); both routes are **also reached online-only by the Tauri POS** (`apps/pos/.../VoidReturnModal.tsx`) `[Opus v4 P2-1]`. These are **knowingly retained server-side for Phase 1** and are **not** disabled by this checklist: their fiscal-event types — `SALE_VOID`, `REFUND_RECEIPT`, `PARTIAL_REFUND` — are **reserved, not implemented, in Phase 1** (Appendix A; they are Phase 2+ work). They remain on the server-recompute path until their event types land in a later phase, at which point they get the same device-authority treatment as `SALE_RECEIPT`. The §14.2 "does not coexist" claim is therefore scoped to **new-sale (`SALE_RECEIPT`) authoring only** — void/return server-side mutation is a known, bounded, time-limited carve-out, not an oversight. **Do not disable `void` / `processReturn` in Phase 1** — they are shared with the online Tauri POS flow and disabling them would break online void/return for the offline client.

**Preserved (read-only — not disabled):** receipt search, receipt PDF / download, and the web shop-management (POS) section that *lists* receipts/transactions (§1.3) — these are read projections over the synced mirror and continue to work through the rebuild.

**Disposition outcome recorded in the preflight sign-off (§2):** the new-sale `SALE_RECEIPT` server-authoring path is closed for **all** callers — the browser web POS write path hidden/disabled, the POS order-close → receipt step disabled, the Tauri-online `executeCheckout` branch reworked to author locally (§14 table), and the backend routes retired/rejected for new-sale authoring; `void`/`processReturn` knowingly retained; web-POS device-authority parity deferred (§18).

### 14.3 Server-side `SALE_RECEIPT` authoring — the two-chokepoint completeness rule

**Why this section exists.** v5 and v6 were each BLOCKed for the *same class* of defect — "a server-authoring `SALE_RECEIPT` caller the spec didn't disposition" (v5: the Tauri-online checkout branch; v6: POS order-close). Dispositioning callers one at a time is whack-a-mole. v7 dispositions the **chokepoints** instead, so completeness is a *mechanical check*, not an enumeration that can miss one.

**The invariant.** No server-side code path may **create** or **seal** a new-sale `SALE_RECEIPT`. After the rebuild, a `SALE_RECEIPT` is authored only by the device via `FiscalEventEngine.append()` (`[SoT §1, D1, D8]`).

**The two chokepoints.** Server-side `SALE_RECEIPT` authoring funnels through exactly two methods:
1. **`ReceiptCreationService::createReceipt()`** — server-side `SALE_RECEIPT` *draft creation*.
2. **`ReceiptFinalizationService::finalize()`** — server-side *seal + chain advance* (writes `fiscal_hash`, advances the terminal chain).

**Completeness rule for the writing-plan.** The Phase 1 cleanup must `grep` every production caller of **both** methods and give each a disposition: **(a)** reworked to device-authority, **(b)** disabled/deferred with a recorded rationale, or **(c)** the knowingly-retained Phase 2+ void/return carve-out (§14.2). A caller with no disposition is an incomplete plan. This grep is a **CI gate** (§17.5) — not a one-time check.

**Enumerated callers (grep-verified 2026-05-14 — the implementation must re-grep, as line numbers and callers may drift):**

| Chokepoint caller | Path | Disposition |
|---|---|---|
| `ReceiptController::store()` → `createReceipt()` | `ReceiptController.php:298` | **(b)** disabled — web POS new-sale + Tauri-online both retired (§14.2); Tauri client reworked to device-authority (§14 table). |
| `OrderToReceiptService::convertToReceipt()` → `createReceipt()` | `OrderToReceiptService.php:68`, via `OrderManagementService::closeOrder()` `:419-438`, `OrderController::close()`, `routes_orders.php:28` | **(b)** disabled — `apps/web`-only order-close; deferred with web-POS order-management parity (§14.2, §18) `[Codex v6]`. |
| `ExchangeService::createReceipt()` → `createReceipt()` | `ExchangeService.php:220` (sale-half of an exchange); also `ReceiptFinalizationService::finalize()` at `ExchangeService.php:286,289` | **service-level — no live route/caller found in the 2026-05-14 grep** `[Codex v6 out-of-scope note]`. The implementation **must** re-grep for a live entrypoint: if one exists it is dispositioned (a/b/c); if confirmed dead it is removed or left inert with a recorded note. Not a Phase 1 blocker absent a live caller, but it **is** on this checklist. |
| `ReceiptPaymentService` → `finalize()` | `ReceiptPaymentService.php:402` | **(b)** — invoked from the `storePayments` path, retired with the backend new-sale routes (§14.2). The `ReceiptPaymentService` Treasury `Payment` + GL logic itself is separately split into the Treasury bridge (§7.4); the *`finalize()` seal call* is discarded. |
| `ReceiptSyncService` → `finalize()` | `ReceiptSyncService.php:623` | **(b)** discarded — `/pos/receipts/sync` is retired (§14.1). |
| `ReceiptReturnService` → `finalize()` | `ReceiptReturnService.php:351` | **(c)** knowingly-retained void/return carve-out — `REFUND_RECEIPT` / `PARTIAL_REFUND` are reserved Phase 2+ event types (§14.2, Appendix A). |

After Phase 1, the only remaining production callers of either chokepoint are the **(c)** void/return carve-out. The §17.5 CI grep enforces this.

---

## 15. Phase 1 commands

### 15.1 `fiscal:verify-event-chain`

```
php artisan fiscal:verify-event-chain {--tenant=} {--terminal=} {--from-sequence=}
```
- Walks `fiscal_events` for the terminal in `sequence_number` order; re-hashes the stored `canonical_bytes`; asserts `current_hash` matches; asserts `previous_hash` links to the prior event's `current_hash` (first event → terminal `fiscal_event_genesis_seed`).
- Also reports any `fiscal_event_quarantine` rows for the terminal as chain incidents — using `claimed_sequence_number`, `envelope_event_id`, `current_hash`, `conflicting_event_id` (§8) — so a `sequence_conflict` is visible to the verifier, not just clean-chain breaks.
- **Exit codes:** `0` = chain verified, no incidents; non-zero = a break or a quarantine incident, with the `sequence_number` and the expected-vs-actual hash on stderr.
- Filters: `--tenant`, `--terminal`, `--from-sequence`. Permission-gated by `fiscal.events.verify_chain`.
- CI fixtures: a valid seeded chain (passes); a deliberately-tampered fixture (fails at the right point); a seeded `sequence_conflict` fixture (fails as an incident).

### 15.2 `fiscal:enqueue-resolved-event-projections`

```
php artisan fiscal:enqueue-resolved-event-projections {--fiscal-event-id=} {--tenant=}
```
- For a `fiscal_events` row whose `payload_parse_status` is `parsed` (§7.5): (a) creates any **missing** `pending` `fiscal_event_projections` rows for the currently-active projectors (via the `FiscalEventProjectionRegistry` + `ModuleActivationResolver`, §7.3), and (b) **enqueues** every `pending` row that has no live queue job. Normally the resolution transaction already created the rows (§7.5) — so the common path is just the enqueue.
- Invoked by the parse-failure resolution action **after the resolution transaction commits**; also runnable **standalone** — it is the named recovery path for the "`payload_parse_status` is `parsed` but projection rows are missing, or exist as `pending` and were never enqueued" state (e.g. a crash between the resolution commit and the enqueue — Opus v4 P2-2).
- Idempotent: never duplicates a `fiscal_event_projections` row (the `UNIQUE (fiscal_event_id, projector_name)` constraint backs this); never resets or re-enqueues a `running` / `applied` / `dead_lettered` row; re-enqueues only `pending` rows with no live job. Safe to re-run any number of times.
- Permission-gated by `fiscal.events.resolve_quarantine`.

---

## 16. Migration plan

All migrations additive; the preflight gate (§2) is the hard precondition.

1. **Preflight verification gate** (§2) — server-side + device-side + web-POS disposition (§14.2); first task; blocks all schema-destructive work.
2. `create_fiscal_events_table` — server PostgreSQL (§3.2), indexes, CHECK constraints.
3. `create_fiscal_events_immutability` — the §3.3 triggers + `REVOKE TRUNCATE` + break-glass runbook note.
4. `create_fiscal_event_projections_table` — the §7.5 projection-tracking table (mutable, no immutability trigger; `projection_status` ∈ `pending|running|applied|dead_lettered`).
5. `create_fiscal_event_quarantine_table` — the §8 non-admissible-envelope quarantine partition (full envelope + metadata columns).
6. Device SQLite migration — `fiscal_events` table (§3.1) + its triggers + `terminal_state` single fiscal-event chain head (§6.2).
7. `add_canonical_bytes_to_pos_receipts` (`BYTEA`) + device `offline_receipts.canonical_bytes` (`TEXT`); convert `pos_receipts`/`offline_receipts` chain columns to mirrors (§5.0).
8. `add_origin_and_fiscal_event_id_to_payments` (§13) — Treasury-module integration migration.
9. Company-integrity event-type registration (§11) — `TERMINAL_REGISTRY_SNAPSHOT` handler + DTO; `COMPANY_DAY_CLOSURE_MANIFEST` DTO + reserved registration.

No fiscal data backfill — the chain is born empty (preflight gate confirms this). The `FiscalEventProjectionRegistry`, `FiscalEventProjector` implementations, and `ModuleActivationResolver` (§5.0, §7.3) are code, not migrations.

---

## 17. Testing strategy

### 17.1 Device (Vitest)
- `FiscalEventEngine.append()` — sequence increments; `previous_hash` links; first event → genesis seed; runs inside the caller's transaction; rollback leaves no row.
- `receiptService.ts` as assembler — a `SALE_RECEIPT` is authored via `append()` in one SQLite transaction with the projection write + voucher updates; one chain, one chain head.
- `FiscalEventCanonicalEncoder` + `HashChainIntegrityProvider` — deterministic; tamper → `verify` false.
- Device-side idempotency — re-emitting a source-backed event returns the existing row.
- Cross-language golden vectors (§4) — TS side reproduces every fixture.

### 17.2 Server — ingestion (PHPUnit, `RefreshDatabase`)
- `OutboxIngestor.ingest()` — validate-before-insert ordering; verified path; each in-table integrity-exception class → `fiscal_events` quarantine with mandatory reason; the row is always persisted.
- `ON CONFLICT` idempotency — concurrent duplicate ingestion of the **same** event → one row, idempotent success, projection dispatched once.
- `sequence_conflict` — a **different** event at an occupied `(tenant,terminal,sequence)` slot → routed to `fiscal_event_quarantine` with the full envelope + metadata columns populated, admin alert, **not** idempotent success; chain-incident path triggered.
- The strict parser — rejects duplicate keys, out-of-grammar numbers, invalid Unicode; `canonical_parse_failure` → quarantine, `payload = NULL`, projection suppressed.
- Immutability — `UPDATE`/`DELETE`/`TRUNCATE` on `fiscal_events` raise per §3.3; allowed-column updates work; forbidden column updates raise; `TRUNCATE` denied to the app role.
- Golden-vector PHP side — `sha256(expected_canonical_string) == expected_sha256_hex`.

### 17.3 Server — projection seam (PHPUnit, `RefreshDatabase`)
- `ModuleActivationResolver` — `isActive('Treasury', tenant, company)` reflects the underlying config surface; the registry includes/excludes the Treasury bridge accordingly.
- **Canonical module-token contract (Opus v4 P1)** — `isActive('Treasury', …)` returns `true` for a standard seeded tenant (whose `Vertical::defaultModules()` includes `'Treasury'`); the `TreasuryReceiptBridge`'s `requiresModule()` returns exactly `'Treasury'` (PascalCase). This test fails if the token drifts to lowercase — locking the contract that `CompanyConfig::hasModule()` strict-compares.
- `FiscalEventProjectionRegistry` — POS-core projector always included; Treasury bridge included only when the resolver reports Treasury active; **excluded when the resolver reports Treasury inactive**.
- `PosCoreReceiptProjection` — idempotent on `(fiscal_event_id, 'pos_core_receipt')`; creates `pos_receipts` row + lines + VAT, `ReceiptPayment` rows, voucher redemption, stock movement exactly once; **runs to completion with the resolver reporting Treasury inactive** (it depends only on mirrored reference data, not the Treasury operational module).
- `TreasuryReceiptBridge` — idempotent on `(fiscal_event_id, 'treasury_receipt_bridge')`; creates Treasury `Payment` rows (`origin='pos'`, `fiscal_event_id` set) + GL posting exactly once; **does not run** when the resolver reports Treasury inactive.
- End-to-end with Treasury active — one `SALE_RECEIPT` produces, exactly once: `pos_receipts` + lines + VAT, `ReceiptPayment` rows, voucher redemptions, stock movements (POS-core), **and** Treasury `Payment` rows + GL entries (bridge).
- Projection failure contract — a bridge failure leaves `fiscal_events` untouched, the queue retries (`attempts` / `last_error` / `last_attempted_at` advance), and after the queue exhausts retries the row is `dead_lettered` with `dead_lettered_at` + operator alert; manual replay re-applies idempotently. POS-core success + Treasury-bridge dead-letter leaves the POS-core effects intact.
- Suppression + resume — `canonical_parse_failure` creates no `fiscal_event_projections` rows; after resolution writes `payload` + flips `payload_parse_status → parsed`, `fiscal:enqueue-resolved-event-projections` creates the `pending` rows and the POS-core projection then runs (the resolve→project path). `canonical_hash_mismatch` / `time_anomaly` proceed flagged.
- **Resume atomicity + recovery (Opus v4 P2-2)** — the resolution transaction (`payload` write + `payload_parse_status → parsed` flip + `fiscal_event_projections` row inserts) is one transaction; a simulated crash *after* that commit but *before* the queue enqueue leaves `parsed` + `pending` rows, and a standalone `fiscal:enqueue-resolved-event-projections` run recovers it (enqueues the pending rows, projection completes) — without re-writing the write-once `payload`.

### 17.4 Receipt-chain rebuild
- `ReceiptFinalizationService` / `verifyTerminalChain` re-hash stored `canonical_bytes` (no recompute-from-models).
- A receipt sync replay → no duplicate (the `UNIQUE` + `source_event_*` + `ON CONFLICT` path holds).
- `Nf525DataProvider` reads verified `canonical_bytes` + includes quarantine state from both quarantine surfaces.

### 17.5 `/pos/receipts/sync` retirement + new-sale server-authoring disposition
- The device posts `SALE_RECEIPT` to `POST /api/v1/pos/sync/fiscal-events`; the retired route is gone or hard-disabled.
- The §14.1 checklist is complete: POS-client caller + integration tests replaced, backend route/controller removed, `SyncReceiptPayload` / `SyncReceiptsRequest` / `SyncReceiptResult` / `ReceiptSyncService` receipt-sync entry retired or repointed, all receipt-sync feature suites migrated (including `OfflineV3CutoverSyncTest.php` and the receipt-sync assertions of `PosStabilizationTenantIsolationTest.php`), timeout helpers/fixtures updated.
- **Tauri checkout is connectivity-independent (Codex v5 BLOCKER):** an integration test runs `executeCheckout()` with connectivity **online** and asserts it authors via `FiscalEventEngine.append()` and does **not** call `POST /pos/receipts` or `POST /pos/receipts/{id}/payments`; the same test offline asserts identical local authoring. The `onlineCheckout()` server-authoring branch is gone.
- The §14.2 web-POS write path is hidden/disabled (or confirmed unused); the backend new-sale-authoring routes are retired/rejected for `SALE_RECEIPT` authoring for all callers; read-only receipt search / PDF / download routes still function.
- **POS order-close no longer server-authors a `SALE_RECEIPT` (Codex v6 BLOCKER):** a test asserts `POST /pos/orders/{id}/close` no longer reaches `ReceiptCreationService::createReceipt()` after the rebuild (the order-close → receipt step is disabled per §14.2).
- **The §14.3 two-chokepoint completeness rule is a CI gate:** a CI grep enumerates every production caller of `ReceiptCreationService::createReceipt()` and `ReceiptFinalizationService::finalize()` and fails if any caller is not in the §14.3 disposition table (or, after Phase 1, if any caller outside the **(c)** void/return carve-out remains). This is the mechanical guard against the v5/v6 class of defect recurring.
- Verification grep (§14.1) returns no live caller of the retired route or the retired old-sync classes; a grep for `createReceipt` / `processReceiptPayments` in `apps/pos` returns no new-sale caller.

### 17.6 `Payment` writers (Treasury-module integration, §13)
- **Every** writer in the §13 table stamps `origin`; the `TreasuryReceiptBridge` path also stamps `fiscal_event_id`.

### 17.7 Chain verifier
- `fiscal:verify-event-chain` (§15.1) — passes on a seeded valid chain; fails with the break point on a tampered fixture; fails as an incident on a seeded `sequence_conflict` fixture. CI gate.

---

## 18. Open items

1. **Preflight gate execution** — server-side + device-side + web-POS disposition; written owner sign-off before any schema-destructive work (§2, §14.2).
2. **`payment_methods` / tender / repository ownership** — these are *setup reference data*, defined in the web and mirrored to the POS local mirror; the POS referencing them (e.g. `ReceiptPayment.payment_method_id` FK to `payment_methods`) is a permitted inbound dependency `[SoT §13.6 as refined]`, and Phase 1 **deliberately keeps them where they are** — no relocation. Whether the tender/payment-method concept should eventually move to a shared core (so the *naming* matches the reference-data role) is a future architectural-placement question, **not Phase 1 scope**, and not required for the bounded-modules seam (the seam is the outbound operational dependency, which the projector registry + `ModuleActivationResolver` already gate).
3. **`ModuleActivationResolver` production scope** — the Phase 1 resolver interface is per-`(tenant, company)` and makes the seam real and testable, but its Phase 1 implementation reads the existing module-activation surface, which is tenant-level-cached and where every current vertical default includes `Treasury` / `Accounting` — and `Treasury` is **not even a `compatibleExtras()` toggle**, so no current vertical can run with it inactive `[Opus v4 — `Vertical.php:104-114,124-136`]`. **Consequence the owner should be aware of:** in Phase 1 the bounded-modules seam is therefore exercised only by a **test double** (§17.3 constructs the resolver to report Treasury inactive) — never yet by a production deployment. This is consistent with `[SoT D16]`, which defines the *mechanism*; Phase 1 builds and test-locks the mechanism. A *production* deployment that runs the Treasury module genuinely inactive needs a later config-model change (genuine per-company activation; a vertical/profile that omits Treasury, or makes it a `compatibleExtras()` toggle). Tracked here; not Phase 1.
4. **Web-POS device-authority parity** — `TerminalType::Web` is scoped out of Phase 1 as a device-authority target and dispositioned (hidden/disabled or confirmed-unused) in the preflight gate (§2, §14.2). How (or whether) a browser-based web POS is brought onto the device-authority pattern — or replaced — is deferred parity work, tracked here, owner-decided in a later phase. **This includes web-POS order management** (`apps/web` orders / tables / kitchen / order-close — `routes_orders.php`, `OrderController`, `OrderManagementService`, `OrderToReceiptService`): order-close currently server-authors a `SALE_RECEIPT` `[Codex v6]`, so the *order-close → receipt step* is disabled for Phase 1 (§14.2, §14.3); bringing the full order-management flow onto device authority rides with this web-POS parity item.
5. **Z-report chain** — independent at the protocol level but shares `terminal_state` + `Nf525DataProvider` (roadmap v2 coordination caveat); its rebuild is a separate task that must coordinate migrations/touchpoints with Phase 1.
6. **Exact `pos_receipts` constraints** — confirm the current `receipt_type` migration + totals CHECK when the rebuild touches `pos_receipts` (reality-doc §6 discrepancies; neither affects a Phase 1 design decision).

---

## Appendix A — Phase 1 `FiscalEventType` reserved values

**Implemented in Phase 1** (has an `append()` handler): `SALE_RECEIPT` (first-class via the rebuilt chain, §5.0), `CHAIN_BREAK_DETECTED`, `CHAIN_RESTART`, `TERMINAL_REGISTRY_SNAPSHOT` (§11).

**Reserved** (CHECK-listed; `append()` throws `FiscalEventTypeNotImplemented` until the type's phase): `COMPANY_DAY_CLOSURE_MANIFEST` (§11), `ACCOUNT_PAYMENT`, `ACCOUNT_CHARGE`, `ACCOUNT_REFUND`, `ACCOUNT_PAYMENT_RECONCILED`, `ACCOUNT_CREDIT_ISSUE`, `ACCOUNT_CREDIT_USAGE`, `DEPOSIT_RECEIPT`, `IDENTITY_ALIAS_RECONCILED`, `SALE_VOID`, `SALE_CORRECTION`, `REFUND_RECEIPT`, `PARTIAL_REFUND`, `RETURN_WITHOUT_RECEIPT`, `OPENING_FLOAT`, `CASH_IN`, `CASH_OUT`, `SAFE_DROP`, `CASH_CORRECTION`, `SESSION_OPEN`, `SESSION_CLOSE`, `X_REPORT`, `Z_REPORT`, `REPRINT_COPY`.

---

**End of Phase 1 spec v7.**
