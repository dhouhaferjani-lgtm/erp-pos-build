# POS Phase 1 — Foundation: Fiscal Event Engine + Receipt-Chain Clean Rebuild (v2)

**Date:** 2026-05-14
**Phase:** 1 of 5 (roadmap v2)
**Status:** Drafted. v1 → Codex BLOCK (3 BLOCKER / 3 P1 / 3 P2); v2 resolves all 9 findings. Awaiting self-review → owner review → Codex re-review → writing-plans.
**Supersedes:** v1 (`2026-05-14-pos-phase1-foundation-spec.md`).

**Grounding (every codebase claim traces to one of these):**
- **Source-of-truth v3** (LOCKED) — `2026-05-14-offline-first-fiscal-source-of-truth-v3.md`. Cited `[SoT §N]`.
- **Codebase reality audit** — `2026-05-14-pos-fiscal-codebase-reality.md` (its §2.3 Payment-writer inventory was corrected 2026-05-14 at the root, after v1's BLOCKER 1). Cited `[reality §N]`.
- **Receipt-chain clean-rebuild scoping map** — 2026-05-14 scoping audit.
- **Roadmap v2** (corrected) — `2026-05-14-pos-customer-accounts-roadmap-v2.md`.

**v1 → v2 changelog:** BLOCKER 1 — exhaustive Payment-writer inventory (§13) + root audit-doc corrected. BLOCKER 2+3 — the central integration decision is now explicit (§5.0): the receipt path is a *consumer* of the engine, one chain, one ingestion path. P1 — `fiscal_event_id` set in Phase 1 for POS receipt payments (§13); `INSERT … ON CONFLICT` idempotency (§7.2); `fiscal:verify-event-chain` moved into scope (§15). P2 — immutability trigger mechanics specified (§3.3); preflight gate split server/device (§2); company-integrity types split implemented/reserved (§11, Appendix A).

---

## 1. Overview

### 1.1 What Phase 1 is

The foundation. Phase 1 establishes the **one fiscal pattern** `[SoT §1]` — device authors and seals locally, server verifies-verbatim and mirrors — and **rebuilds the existing receipt chain clean on that pattern, as a consumer of the engine**. No customer-facing feature. When Phase 1 is done: the fiscal event engine exists; `SALE_RECEIPT` is a first-class fiscal event flowing through it; there is **one chain and one ingestion path**; the schema carries the non-retrofittable hooks later phases depend on.

### 1.2 Scope (in)

1. The **preflight verification gate** — server-side + device-side (§2).
2. The **`fiscal_events` table** — device SQLite + server PostgreSQL mirror, with the specified immutability triggers (§3).
3. The **canonical serialization contract** (§4).
4. The **central integration model** — receipt path as engine consumer (§5.0); `HashChainIntegrityProvider` + `SignatureProviderInterface` (§5).
5. The **device-side fiscal event engine** — `append()`, the single chain head (§6).
6. The **server-side verify-only mirror** — the single typed fiscal-event ingestion endpoint, `OutboxIngestor`, `ReceiptBusinessProjection`, the strict parser (§7).
7. The **per-anomaly-class integrity-exception path** + quarantine (§8).
8. **Chain-recovery events** (§9).
9. The **clock / time model** (§10).
10. **Company-level integrity record types** — `TERMINAL_REGISTRY_SNAPSHOT` (implemented), `COMPANY_DAY_CLOSURE_MANIFEST` (reserved) (§11).
11. **Off-device durability controls** `[SoT §8]` (§12).
12. **`Payment.origin` / `Payment.fiscal_event_id`** — migration, `PaymentOrigin` enum, the **complete** writer-update inventory (§13).
13. The **receipt-chain clean rebuild** — reuse/rework/discard/retire (§14).
14. The **`fiscal:verify-event-chain` command** (§15).

### 1.3 Scope (out — Phase 2+, per roadmap v2)

Anything customer-facing (customer mirror, search/create/attach, `ACCOUNT_PAYMENT`) — Phase 2. Charge-to-account / AR GL path / B2B Facture routing — Phase 3. Account-status, override flows, approval primitive — Phase 4. Deposits, identity reconciliation, AML, store credit — Phase 5. The Z-report chain clean rebuild — its own task (coordination caveat in roadmap v2). The actual `TseSignatureProvider` — interface + nullable columns only here.

---

## 2. The preflight verification gate

`[SoT §1]` — a **hard precondition** before any destructive rebuild step. The clean-rebuild *decision* is locked (no migration); the *precondition* (no live fiscal data) is operational and must be verified. v1 wrongly treated `offline_receipts` as a server table — it is a Tauri SQLite table `[reality §3]`, so the gate has **two surfaces**:

**Server-side** — query every staging and production tenant PostgreSQL database for: `pos_receipts`, `pos_z_reports`, `pos_terminals` chain state (`last_hash` / `current_sequence` non-default), receipt-print records.

**Device-side** — inventory every installed/deployed Tauri terminal's SQLite store for: `offline_receipts`, `terminal_state`, `z_reports`, and any pending-sync rows. **If no deployed terminals exist, record that operational fact explicitly.**

**Sign-off** — a written owner sign-off covering **both** surfaces that the environments are empty (or that anything found is disposable test data). If any non-disposable fiscal data is found: stop; define and execute an archival/export path first; re-scope. The gate is the **first task** of the implementation plan, blocking all schema-destructive work.

---

## 3. The `fiscal_events` table

The canonical append-only fiscal ledger. **Device SQLite is authoritative; server PostgreSQL is a verbatim verify-only mirror** `[SoT §1, §11]`.

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
  reference_document_id      TEXT                    -- cross-link (e.g. the pos_receipts/offline_receipts projection row)
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
  -- derived structured payload (§7.3)
  payload                    JSONB
  payload_parse_status       VARCHAR(16)  NOT NULL DEFAULT 'pending'    -- pending|parsed|failed
  created_at                 TIMESTAMPTZ  NOT NULL DEFAULT NOW()
}
```

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

### 3.3 Immutability triggers (mechanics specified — v1 P2.1)

v1 said "column-scoped allow-list," which is not how PostgreSQL triggers work. The precise mechanism, modelled on the existing `pos_receipts` immutability trigger `[reality §1.5]`:

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

**Notes:** `previous_hash` is never NULL (first event uses the terminal fiscal-event genesis seed, §6.2). Hashes are lowercase hex `CHAR(64)` everywhere `[reality §1.3]`; only `canonical_bytes` is binary. `sequence_number` is continuous across fiscal years `[SoT §3]`.

---

## 4. Canonical serialization contract

`[SoT §1.2, §5]`. Unchanged from v1 — the device serializes **once**; the server **never re-serializes**.

- **Canonical object** — sorted-key JSON (RFC 8785 / JCS) over `business_date, company_id, event_time_device, event_type, event_version, operator_id, payload, previous_hash, reference_document_id, reference_event_id, sequence_number, signature_version, tenant_id, terminal_id`. `previous_hash` is embedded as a hex string, matching the existing receipt-V3 pattern `[reality §1.4]`.
- **Value grammar** — integers only, no floats; money as `CurrencyScale::bcformat()` decimal strings; UTC ISO-8601 second-precision timestamps; UTF-8 NFC strings with U+2028/U+2029 stripped at the producer; sorted arrays; sorted object keys.
- **`canonical_bytes`** — the UTF-8 encoding, stored verbatim (device `TEXT`, server `BYTEA`), **never round-tripped through a JSON/JSONB column** `[SoT §1.2]`.
- **`current_hash` = `lowercase_hex(SHA-256(canonical_bytes))`.** The server verifies by re-hashing the device's exact bytes — never re-serializes (D2).
- **`FiscalEventCanonicalEncoder`** (device, TypeScript) — applies the string normalization, reuses the structural logic of the existing `CanonicalJsonEncoder` pattern `[reality §1.4]`; not mirrored in PHP.
- **Cross-language golden vectors** — committed fixture set `{ payload_dto_input, expected_canonical_string, expected_sha256_hex }`. TS test reproduces the canonical string + hash; PHP test asserts only `sha256(expected_canonical_string) == expected_sha256_hex` (PHP never serializes). Matrix must include: TND 3-decimal, 2-decimal, 0-decimal currencies, a negative amount, empty arrays / null optionals, multibyte/NFC, U+2028/U+2029 normalization, non-ASCII key ordering.

---

## 5. The central integration model + the providers

### 5.0 The central integration decision (v1 BLOCKER 2 + 3)

`[SoT §1, D8 — "one fiscal pattern"]` forces this. v1 left it unresolved; v2 makes it explicit:

**The receipt path is a *consumer* of the fiscal event engine — not a parallel sealer, not a separate chain, not a separate ingestion path.**

- **`SALE_RECEIPT` is a fiscal event.** It is authored only through `FiscalEventEngine.append()`. There is **one chain** — `fiscal_events`. The "fiscal-event chain is separate from the receipt chain" language from v1 is **removed**: the rebuilt receipt chain *is* the fiscal-event chain.
- **`receiptService.ts` becomes a business-document assembler.** It builds the receipt business document (lines, totals, vouchers, payment lines), then calls `FiscalEventEngine.append({ type: SALE_RECEIPT, reference_document_id: <offline_receipts row id>, payload })` **inside one SQLite transaction** — the same transaction that writes the `offline_receipts` projection row and updates voucher balances. It no longer computes its own independent hash or advances its own chain head.
- **`pos_receipts` / `offline_receipts` become projection rows.** Their `fiscal_hash` / `previous_hash` / `hash_sequence` columns **mirror** the authoritative `fiscal_events` row's values (kept for backward-compatible reads); they are no longer an independent chain.
- **One ingestion path.** The device posts **all** fiscal events — `SALE_RECEIPT` included — through the single fiscal-event endpoint to `OutboxIngestor` (§7). `OutboxIngestor` verifies + stores the `fiscal_events` row, then — for event types with business side-effects — invokes `ReceiptBusinessProjection` for the stock / voucher / `pos_receipts` / Treasury-`Payment` effects, idempotently keyed on the fiscal event id. **`/pos/receipts/sync` is retired** (clean rebuild, no live data — no compatibility adapter needed `[reality §3]`).

"REUSE" of receipt code therefore means reuse of its **business-assembly and business-projection logic**, not its independent sealing/chaining/sync — see §14.

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

## 7. The server-side verify-only mirror

`[SoT §1.1, §11]`. **One** ingestion path (§5.0).

### 7.1 The single fiscal-event ingestion endpoint

A new endpoint `POST /api/v1/pos/sync/fiscal-events` (under the existing `api/v1` POS prefix `[reality §3]`) and a new `OutboxIngestor`. The device posts **all** fiscal events here — `SALE_RECEIPT` included. `/pos/receipts/sync` is retired. The transport carries a typed envelope `{ envelope_id, type: 'FISCAL_EVENT', payload_version, payload, idempotency_key }`; the idempotency key is `(terminal_id, sequence_number)`.

### 7.2 `OutboxIngestor.ingest()` — atomic idempotency (v1 P1.2)

```
OutboxIngestor.ingest(envelope):                          // standalone operation, not nested in a business transaction
  1. atomic idempotency:
       INSERT INTO fiscal_events (...) VALUES (...)
       ON CONFLICT (tenant_id, terminal_id, sequence_number) DO NOTHING
       RETURNING id;
     if no row returned → SELECT the existing row and return it (idempotent success)
  2..5  (run before the INSERT, against the envelope; results decide integrity_status on insert)
       verify hash:      SHA-256(canonical_bytes) == current_hash ?     else canonical_hash_mismatch
       verify linkage:   previous_hash == prior event's current_hash ?  else sequence_gap
       clock check (§10):                                              else time_anomaly
       strict-parse canonical_bytes → payload (§7.3):                  else canonical_parse_failure
  6. the INSERT in step 1 carries server_received_at = now(),
     integrity_status = 'verified' (all checks passed) or 'quarantined' + class + mandatory reason
  7. for event types with business side-effects (SALE_RECEIPT): invoke
     ReceiptBusinessProjection.apply(fiscal_event) — idempotent, keyed on fiscal_event.id
  8. the row is ALWAYS persisted (verified or quarantined) — never rejected, never blocks the device
```

`INSERT … ON CONFLICT DO NOTHING RETURNING id` is the atomic idempotency primitive — two concurrent deliveries of the same event cannot both insert; the loser gets no returned row and falls through to the SELECT. No exception-driven control flow, no transaction-abort hazard.

### 7.3 `ReceiptBusinessProjection`

The stock / voucher / `pos_receipts` projection-row / Treasury-`Payment` side-effects of a `SALE_RECEIPT` — the **business logic** extracted from the old `ReceiptSyncService` (`[reality]` — its batch transport/orchestration is *not* reused; its business-effect logic is). `OutboxIngestor` invokes it after the `fiscal_events` row is stored; it is **idempotent**, keyed on `fiscal_event.id`. The Treasury `Payment` rows it creates for POS receipt payment lines are stamped `origin = pos` **and** `fiscal_event_id = <the SALE_RECEIPT event id>` (§13, resolves v1 P1.1).

### 7.4 The strict parser

The structured `payload` JSONB is derived server-side from the verified `canonical_bytes` by a strict parser — rejects duplicate keys, out-of-grammar numbers, invalid Unicode, event-type schema violations `[SoT §5]`. On failure: `payload = NULL`, `payload_parse_status = 'failed'`, `integrity_exception_class = 'canonical_parse_failure'`. Canonical bytes remain authoritative.

---

## 8. Per-anomaly-class integrity exceptions + quarantine

`[SoT §7]`. **Accept-and-flag per anomaly class — never block the device.** Unchanged from v1.

| Class | Trigger | Handling |
|---|---|---|
| `canonical_hash_mismatch` | `SHA-256(canonical_bytes) ≠ current_hash` | accept, quarantine, annotate; counts, flagged |
| `canonical_parse_failure` | bytes hash OK but fail the strict grammar | accept, quarantine, annotate; **no `payload` projection** until resolution; raw bytes conserved |
| `time_anomaly` | clock rollback / excessive drift (§10) | accept, quarantine, annotate |
| `sequence_gap` | gap/break in the per-terminal sequence | accept; chain in recorded incident state; the gap is an **explicit exception total**, not silently in clean totals |

`signature_invalid` does not arise in Phase 1 (no signature provider active). A quarantined event is **always persisted** with a **mandatory structured `integrity_exception_reason`**, an admin alert, and an operator-resolution path. **Exports include a quarantine section with reconciliation totals — never silent exclusion** `[SoT §7.2]`.

---

## 9. Chain-recovery events

`[SoT §7.3]`. On a local chain break, the terminal continues operating in a recorded `degraded` mode. Recovery is **two chained incident events** (not "signed" — no signature provider in Phase 1): `CHAIN_BREAK_DETECTED` (reason, last-good sequence + hash, offending record reference) and `CHAIN_RESTART` (new genesis reference, last-good anchor, operator authorization evidence, provenance link to the prior chain). Both are ordinary `fiscal_events` rows. The broken segment is never deleted — quarantined (§8), synced, flagged in exports.

---

## 10. Clock / time model

`[SoT §6]`. The device clock is untrusted `[reality §3]`. Unchanged from v1: `event_time_device` (untrusted), `sequence_number` (the authoritative ordering), `last_server_time_seen`, `server_received_at`. Clock-rollback and drift detection → `time_anomaly` (§8), accepted not blocked. Normative closure-period rule: `business_date` is assigned by the **terminal-configured fiscal timezone and session boundary** — not `server_received_at`, not raw device time; a clock anomaly never moves an event between closure periods without an explicit correction event.

---

## 11. Company-level integrity record types

`[SoT §9]`. v1 P2.3 — "implemented" vs "reserved" is now explicit:

- **`TERMINAL_REGISTRY_SNAPSHOT` — implemented in Phase 1.** It has no closure dependency: a registry snapshot (the authoritative list of terminals expected for a company at a point in time; carries a hash; links to the prior snapshot) can be emitted at terminal provisioning and on demand. Phase 1 delivers the event type, the payload DTO, the `append()` handler, and an initial-snapshot emission path.
- **`COMPANY_DAY_CLOSURE_MANIFEST` — reserved in Phase 1.** It depends on day-closures (a later phase). Phase 1 delivers the event type registration + the payload DTO schema only; `append()` throws `FiscalEventTypeNotImplemented` for it until the closure-rollout phase.

Appendix A reflects this split.

---

## 12. Off-device durability controls

`[SoT §8]`. Unchanged from v1. Device authority is not survivable without off-device conservation; an on-device backup encrypted with an on-device key is not a conservation control `[reality §3 — plaintext `.izipos_key`]`. Phase 1 delivers: at least one off-device durability path (encrypted removable archive / LAN peer / NAS / cloud-sync) with key custody **outside** the terminal disk; the on-device AES-GCM copy as crash-recovery only; an operator-visible unsynced-risk indicator; a forced archive/export threshold; a maximum-unsynced escalation; a device-loss incident register. **A Phase 1 gate before any Phase 2 customer-facing deployment.**

---

## 13. `Payment.origin` / `Payment.fiscal_event_id`

`[reality §2.3 — corrected 2026-05-14: the complete writer inventory]`. v1 BLOCKER 1 — the writer list is now exhaustive.

- **Migration:** add `payments.origin VARCHAR(32) NULL` and `payments.fiscal_event_id UUID NULL` (FK `fiscal_events(id)`).
- **`PaymentOrigin` enum:** `pos | web_admin | mobile | api | unknown_legacy`.
- **`Payment` model:** add both to `$fillable`; cast `origin` to `PaymentOrigin`.
- **Writer updates — the COMPLETE Treasury `Payment` writer inventory, all in the same change:**

| Writer | `origin` |
|---|---|
| `ReceiptPaymentService` (POS receipt payment lines) | `pos` — **and `fiscal_event_id` set to the `SALE_RECEIPT` event id** (resolves v1 P1.1) |
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
| `terminal_state`, `offline_receipts` (client) | **REWORK** | `terminal_state` gains the single fiscal-event chain head (§6.2); `offline_receipts` becomes a projection row mirroring the `fiscal_events` chain values, gains `canonical_bytes`. |
| `ReceiptSyncService` — batch transport/orchestration | **DISCARD** | `/pos/receipts/sync` is retired; the device posts to the single fiscal-event endpoint (§7.1). |
| `ReceiptSyncService` — stock/voucher/payment business-effect logic | **REUSE (relocated)** | Extracted into `ReceiptBusinessProjection`, invoked idempotently by `OutboxIngestor` (§7.3). |
| `ReceiptFinalizationService` + `ReceiptHashService::verifyTerminalChain()` / `verifyHash()` | **REWORK** | From recompute-from-models → re-hash the stored `canonical_bytes`; verification walks the `fiscal_events` chain. |
| `OfflineFiscalHashMismatchException` throw-and-rollback | **DISCARD** | Replaced by per-class accept/flag (§8). The class may remain as an audit-trail artifact; never thrown to block. |
| `Compliance/FiscalHashService` chain-prefix logic | **REUSE** | Generic; unaffected. |
| `Nf525XmlBuilder` | **REUSE** | DTO→XML serialization of a derived export artifact — permitted under D2. |
| `Nf525DataProvider` + the JET verification path | **REWORK** | It reads `Receipt` models directly and verifies via `ReceiptHashService::calculateHash()` (server-recompute) — reworked to read verified `canonical_bytes` + quarantine state from `fiscal_events`. |
| `canonical_bytes` columns (`pos_receipts` `BYTEA` / `offline_receipts` `TEXT`) | **NEW** | — |
| Per-class integrity-exception / quarantine path for receipts | **NEW** | Same machinery as §8. |
| JET export sections for quarantine / incidents / manifests | **NEW** | — |

`SALE_RECEIPT` is a **first-class fiscal event** on the single chain — no `SALE_RECEIPT_BRIDGE` (D10).

---

## 15. The `fiscal:verify-event-chain` command

v1 P1.3 — moved from "open items" into Phase 1 scope (it is a CI gate; §16.4 depends on it).

```
php artisan fiscal:verify-event-chain {--tenant=} {--terminal=} {--from-sequence=}
```
- Walks `fiscal_events` for the terminal in `sequence_number` order; re-hashes the stored `canonical_bytes`; asserts `current_hash` matches; asserts `previous_hash` links to the prior event's `current_hash` (first event → terminal `fiscal_event_genesis_seed`).
- **Exit codes:** `0` = chain verified; non-zero = a break, with the break's `sequence_number` and the expected-vs-actual hash on stderr.
- Filters: `--tenant`, `--terminal`, `--from-sequence`. Permission-gated by `fiscal.events.verify_chain`.
- CI fixtures: a valid seeded chain (passes) and a deliberately-tampered fixture (fails at the right point).

---

## 16. Migration plan

All migrations additive; the preflight gate (§2) is the hard precondition.

1. **Preflight verification gate** (§2) — server-side + device-side; first task; blocks all schema-destructive work.
2. `create_fiscal_events_table` — server PostgreSQL (§3.2), indexes, CHECK constraints.
3. `create_fiscal_events_immutability` — the §3.3 triggers + `REVOKE TRUNCATE` + break-glass runbook note.
4. Device SQLite migration — `fiscal_events` table (§3.1) + its triggers + `terminal_state` single fiscal-event chain head (§6.2).
5. `add_canonical_bytes_to_pos_receipts` (`BYTEA`) + device `offline_receipts.canonical_bytes` (`TEXT`); convert `pos_receipts`/`offline_receipts` chain columns to mirrors (§5.0).
6. `add_origin_and_fiscal_event_id_to_payments` (§13).
7. Company-integrity event-type registration (§11) — `TERMINAL_REGISTRY_SNAPSHOT` handler + DTO; `COMPANY_DAY_CLOSURE_MANIFEST` DTO + reserved registration.

No fiscal data backfill — the chain is born empty (preflight gate confirms this).

---

## 17. Testing strategy

### 17.1 Device (Vitest)
- `FiscalEventEngine.append()` — sequence increments; `previous_hash` links; first event → genesis seed; runs inside the caller's transaction; rollback leaves no row.
- `receiptService.ts` as assembler — a `SALE_RECEIPT` is authored via `append()` in one SQLite transaction with the projection write + voucher updates; one chain, one chain head.
- `FiscalEventCanonicalEncoder` + `HashChainIntegrityProvider` — deterministic; tamper → `verify` false.
- Device-side idempotency — re-emitting a source-backed event returns the existing row.
- Cross-language golden vectors (§4) — TS side reproduces every fixture.

### 17.2 Server (PHPUnit, `RefreshDatabase`)
- `OutboxIngestor.ingest()` — `INSERT … ON CONFLICT` idempotency under **concurrent duplicate** ingestion; verified path; each integrity-exception class → quarantine with mandatory reason; the row is always persisted.
- `ReceiptBusinessProjection` — idempotent on `fiscal_event.id`; stock/voucher/`pos_receipts`/Treasury effects applied once.
- The strict parser — rejects duplicate keys, out-of-grammar numbers, invalid Unicode; `canonical_parse_failure` → quarantine, `payload = NULL`.
- Immutability — `UPDATE`/`DELETE`/`TRUNCATE` raise per §3.3; the allowed-column update set works; forbidden column updates raise; `TRUNCATE` denied to the app role.
- Golden-vector PHP side — `sha256(expected_canonical_string) == expected_sha256_hex`.
- `Payment` writers — **every** writer in the §13 table stamps `origin`; `ReceiptPaymentService` also stamps `fiscal_event_id`.

### 17.3 Receipt-chain rebuild
- `ReceiptFinalizationService` / `verifyTerminalChain` re-hash stored `canonical_bytes` (no recompute-from-models).
- A receipt sync replay → no duplicate (the `UNIQUE` + `source_event_*` + `ON CONFLICT` path holds).
- `Nf525DataProvider` reads verified `canonical_bytes` + includes quarantine state.
- `/pos/receipts/sync` is gone — the device posts `SALE_RECEIPT` to the single fiscal-event endpoint.

### 17.4 Chain verifier
- `fiscal:verify-event-chain` (§15) — passes on a seeded valid chain; fails with the break point on a tampered fixture. CI gate.

---

## 18. Open items

1. **Preflight gate execution** — server-side + device-side; written owner sign-off before any schema-destructive work (§2).
2. **Z-report chain** — independent at the protocol level but shares `terminal_state` + `Nf525DataProvider` (roadmap v2 coordination caveat); its rebuild is a separate task that must coordinate migrations/touchpoints with Phase 1.
3. **Exact `pos_receipts` constraints** — confirm the current `receipt_type` migration + totals CHECK when the rebuild touches `pos_receipts` (reality-doc §6 discrepancies; neither affects a Phase 1 design decision).

---

## Appendix A — Phase 1 `FiscalEventType` reserved values

**Implemented in Phase 1** (has an `append()` handler): `SALE_RECEIPT` (first-class via the rebuilt chain, §5.0), `CHAIN_BREAK_DETECTED`, `CHAIN_RESTART`, `TERMINAL_REGISTRY_SNAPSHOT` (§11).

**Reserved** (CHECK-listed; `append()` throws `FiscalEventTypeNotImplemented` until the type's phase): `COMPANY_DAY_CLOSURE_MANIFEST` (§11), `ACCOUNT_PAYMENT`, `ACCOUNT_CHARGE`, `ACCOUNT_REFUND`, `ACCOUNT_PAYMENT_RECONCILED`, `ACCOUNT_CREDIT_ISSUE`, `ACCOUNT_CREDIT_USAGE`, `DEPOSIT_RECEIPT`, `IDENTITY_ALIAS_RECONCILED`, `SALE_VOID`, `SALE_CORRECTION`, `REFUND_RECEIPT`, `PARTIAL_REFUND`, `RETURN_WITHOUT_RECEIPT`, `OPENING_FLOAT`, `CASH_IN`, `CASH_OUT`, `SAFE_DROP`, `CASH_CORRECTION`, `SESSION_OPEN`, `SESSION_CLOSE`, `X_REPORT`, `Z_REPORT`, `REPRINT_COPY`.

---

**End of Phase 1 spec v2.**
