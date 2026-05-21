# POS Phase 1 — Foundation: Fiscal Event Engine + Receipt-Chain Clean Rebuild (v3)

**Date:** 2026-05-14
**Phase:** 1 of 5 (roadmap v2)
**Status:** Drafted. v1 → Codex BLOCK (3 BLOCKER / 3 P1 / 3 P2); v2 → Codex BLOCK (2 BLOCKER / 1 P1 / 1 P2); v3 resolves all 4 v2 findings and threads the bounded-modules seam. Awaiting self-review → owner review → Codex re-review → writing-plans.
**Supersedes:** v2 (`2026-05-14-pos-phase1-foundation-spec-v2.md`), v1 (`2026-05-14-pos-phase1-foundation-spec.md`).

**Grounding (every codebase claim traces to one of these):**
- **Source-of-truth v3** (LOCKED; amended 2026-05-14 with §13 guardrail 6 + D16) — `2026-05-14-offline-first-fiscal-source-of-truth-v3.md`. Cited `[SoT §N]`.
- **Codebase reality audit** — `2026-05-14-pos-fiscal-codebase-reality.md`. Cited `[reality §N]`.
- **Receipt-chain clean-rebuild scoping map** — 2026-05-14 scoping audit.
- **Roadmap v2** (corrected) — `2026-05-14-pos-customer-accounts-roadmap-v2.md`.
- **Owner strategy docs** — `fiscal_chain_architecture_strategy.md`, `pos_printable_documents_architecture.md`.

**v2 → v3 changelog:**
- **BLOCKER 1** (`ReceiptBusinessProjection` based on a false source) — the projection is split into a **POS-core projection** (always runs; the `ReceiptSyncService` business-effect logic — `pos_receipts`/lines/VAT/`ReceiptPayment`/voucher/stock) plus a **pluggable Treasury bridge** (the `ReceiptPaymentService` logic — Treasury `Payment` + GL). This resolves the false-source attribution *and* threads the SoT §13.6 / D16 bounded-modules seam — they are the same seam (§5.0, §7.3, §7.4).
- **BLOCKER 2** (projection failure semantics undefined) — `fiscal_events` persists in its own transaction; business projection runs separately as an idempotent operation tracked in a new `fiscal_event_projections` table with per-projector `projection_status`, retry/backoff, dead-letter, operator alert. Projection failure never mutates or deletes the fiscal event (§7.5).
- **P1** (`ON CONFLICT` treats non-identical sequence conflicts as success; self-contradictory step ordering) — ingest now validates hash/parse/linkage/clock **before** the insert; on conflict it loads the existing row and compares identity + `canonical_bytes` + `current_hash`; a non-identical conflict is a `sequence_conflict` chain incident, not silent success (§7.2, §8).
- **P2** (`/pos/receipts/sync` retirement cleanup surface) — a concrete cross-app cleanup checklist added (§14.1, §17.5).
- **Modular seam threading** — §13 (`Payment.origin`/`fiscal_event_id`) reframed as **Treasury-module integration**; the web POS scoped out with a preflight/cleanup hook (§1.3, §2, §14.1); module-activation signal named (`RequireModule` / `enabled_extras`).

---

## 1. Overview

### 1.1 What Phase 1 is

The foundation. Phase 1 establishes the **one fiscal pattern** `[SoT §1]` — device authors and seals locally, server verifies-verbatim and mirrors — and **rebuilds the existing receipt chain clean on that pattern, as a consumer of the engine**. No customer-facing feature. When Phase 1 is done: the fiscal event engine exists; `SALE_RECEIPT` is a first-class fiscal event flowing through it; there is **one chain and one ingestion path**; the server-side business projection is a **POS-core projection plus pluggable module bridges** `[SoT §13.6, D16]`; the schema carries the non-retrofittable hooks later phases depend on.

### 1.2 Scope (in)

1. The **preflight verification gate** — server-side + device-side + web-POS disposition (§2).
2. The **`fiscal_events` table** — device SQLite + server PostgreSQL mirror, with the specified immutability triggers (§3).
3. The **canonical serialization contract** (§4).
4. The **central integration model** — receipt path as engine consumer; the **projector registry / pluggable-bridge seam** `[SoT §13.6]` (§5.0); `HashChainIntegrityProvider` + `SignatureProviderInterface` (§5).
5. The **device-side fiscal event engine** — `append()`, the single chain head (§6).
6. The **server-side verify-only mirror** — the single typed fiscal-event ingestion endpoint, `OutboxIngestor`, the strict parser (§7.1–§7.2, §7.6).
7. The **POS-core receipt projection** + the **pluggable Treasury receipt bridge** + the **projection failure/retry contract** (§7.3–§7.5).
8. The **per-anomaly-class integrity-exception path** + quarantine, including `sequence_conflict` (§8).
9. **Chain-recovery events** (§9).
10. The **clock / time model** (§10).
11. **Company-level integrity record types** — `TERMINAL_REGISTRY_SNAPSHOT` (implemented), `COMPANY_DAY_CLOSURE_MANIFEST` (reserved) (§11).
12. **Off-device durability controls** `[SoT §8]` (§12).
13. **`Payment.origin` / `Payment.fiscal_event_id`** — the Treasury-module integration migration + `PaymentOrigin` enum + the **complete** Treasury `Payment` writer-update inventory (§13).
14. The **receipt-chain clean rebuild** — reuse/rework/discard/retire, with the concrete `/pos/receipts/sync` cleanup checklist (§14).
15. The **`fiscal:verify-event-chain` command** (§15).

### 1.3 Scope (out — Phase 2+, per roadmap v2)

Anything customer-facing (customer mirror, search/create/attach, `ACCOUNT_PAYMENT`) — Phase 2. Charge-to-account / AR GL path / B2B Facture routing — Phase 3. Account-status, override flows, approval primitive — Phase 4. Deposits, identity reconciliation, AML, store credit — Phase 5. The Z-report chain clean rebuild — its own task (coordination caveat in roadmap v2). The actual `TseSignatureProvider` — interface + nullable columns only here.

**The web POS (`TerminalType::Web`) is out of scope.** `TerminalType` carries `Web` and `Physical` values `[reality §1.5]`, and a browser-based web POS is structurally not a device-authoring fiscal source of truth — it cannot seal locally. Bringing the web POS onto the device-authority pattern is **parity work, deferred** (tracked, §18). But Phase 1 cannot silently ignore it: receipt finalization (`ReceiptFinalizationService` → the `pos_receipts` chain) is the shared path, so leaving a server-recompute web-POS receipt-creation path alive next to the rebuilt chain would violate `[SoT D8]` ("one fiscal pattern, no two-model coexistence"). Phase 1 therefore **dispositions the web POS in the preflight gate** — confirm its receipt-creation path is unused, or hide/disable it until parity work — see §2 and §14.1. No web-POS device-authority work happens in Phase 1; only the disposition.

**There is no new "web transaction view" to build.** POS transactions are already viewable through the existing web shop-management (POS) section — a read projection over the synced server mirror `[SoT §13.6]`. That is sufficient for the simplest (POS-only) use case and needs no Treasury module. Nothing in Phase 1 builds or changes it beyond it reading the rebuilt `pos_receipts` projection rows.

---

## 2. The preflight verification gate

`[SoT §1, §15.1]` — a **hard precondition** before any destructive rebuild step. The clean-rebuild *decision* is locked (no migration); the *precondition* (no live fiscal data) is operational and must be verified. `offline_receipts` is a Tauri SQLite table, not a server table `[reality §3]`, so the gate has **three surfaces**:

**Server-side** — query every staging and production tenant PostgreSQL database for: `pos_receipts`, `pos_z_reports`, `pos_terminals` chain state (`last_hash` / `current_sequence` non-default), receipt-print records.

**Device-side** — inventory every installed/deployed Tauri terminal's SQLite store for: `offline_receipts`, `terminal_state`, `z_reports`, and any pending-sync rows. **If no deployed terminals exist, record that operational fact explicitly.**

**Web-POS disposition** — determine whether any `TerminalType::Web` terminal has a live receipt-creation path producing `pos_receipts` rows. If yes: the web POS receipt-creation path is **hidden/disabled** before the rebuild (it cannot coexist with the device-authority chain — `[SoT D8]`), and web-POS device-authority parity is logged as deferred work (§18). If the web POS has no live receipt-creation usage, record that fact and the rebuild proceeds unblocked.

**Sign-off** — a written owner sign-off covering **all three surfaces**: the environments are empty (or anything found is disposable test data), and the web-POS disposition is decided. If any non-disposable fiscal data is found: stop; define and execute an archival/export path first; re-scope. The gate is the **first task** of the implementation plan, blocking all schema-destructive work.

---

## 3. The `fiscal_events` table

The canonical append-only fiscal ledger. **Device SQLite is authoritative; server PostgreSQL is a verbatim verify-only mirror** `[SoT §1, §11]`. Unchanged from v2 except where noted.

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

Unchanged from v2. The precise mechanism, modelled on the existing `pos_receipts` immutability trigger `[reality §1.5]`:

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

`[SoT §1.2, §5]`. Unchanged from v2 — the device serializes **once**; the server **never re-serializes**.

- **Canonical object** — sorted-key JSON (RFC 8785 / JCS) over `business_date, company_id, event_time_device, event_type, event_version, operator_id, payload, previous_hash, reference_document_id, reference_event_id, sequence_number, signature_version, tenant_id, terminal_id`. `previous_hash` is embedded as a hex string, matching the existing receipt-V3 pattern `[reality §1.4]`.
- **Value grammar** — integers only, no floats; money as `CurrencyScale::bcformat()` decimal strings; UTC ISO-8601 second-precision timestamps; UTF-8 NFC strings with U+2028/U+2029 stripped at the producer; sorted arrays; sorted object keys.
- **`canonical_bytes`** — the UTF-8 encoding, stored verbatim (device `TEXT`, server `BYTEA`), **never round-tripped through a JSON/JSONB column** `[SoT §1.2]`.
- **`current_hash` = `lowercase_hex(SHA-256(canonical_bytes))`.** The server verifies by re-hashing the device's exact bytes — never re-serializes (D2).
- **`FiscalEventCanonicalEncoder`** (device, TypeScript) — applies the string normalization, reuses the structural logic of the existing `CanonicalJsonEncoder` pattern `[reality §1.4]`; not mirrored in PHP.
- **Cross-language golden vectors** — committed fixture set `{ payload_dto_input, expected_canonical_string, expected_sha256_hex }`. TS test reproduces the canonical string + hash; PHP test asserts only `sha256(expected_canonical_string) == expected_sha256_hex` (PHP never serializes). Matrix must include: TND 3-decimal, 2-decimal, 0-decimal currencies, a negative amount, empty arrays / null optionals, multibyte/NFC, U+2028/U+2029 normalization, non-ASCII key ordering.

---

## 5. The central integration model + the providers

### 5.0 The central integration decision + the bounded-modules seam

`[SoT §1, D8 — "one fiscal pattern"; SoT §13.6, D16 — "bounded modules compose"]` together force this. v2 resolved the one-chain/one-route question; v3 additionally threads the bounded-modules seam — and these are the **same seam**.

**The receipt path is a *consumer* of the fiscal event engine — not a parallel sealer, not a separate chain, not a separate ingestion path.**

- **`SALE_RECEIPT` is a fiscal event.** It is authored only through `FiscalEventEngine.append()`. There is **one chain** — `fiscal_events`.
- **`receiptService.ts` becomes a business-document assembler.** It builds the receipt business document (lines, totals, vouchers, payment lines), then calls `FiscalEventEngine.append({ type: SALE_RECEIPT, reference_document_id: <offline_receipts row id>, payload })` **inside one SQLite transaction** — the same transaction that writes the `offline_receipts` projection row and updates voucher balances. It no longer computes its own independent hash or advances its own chain head.
- **`pos_receipts` / `offline_receipts` become projection rows.** Their `fiscal_hash` / `previous_hash` / `hash_sequence` columns **mirror** the authoritative `fiscal_events` row's values (kept for backward-compatible reads); they are no longer an independent chain.
- **One ingestion path.** The device posts **all** fiscal events — `SALE_RECEIPT` included — through the single fiscal-event endpoint to `OutboxIngestor` (§7). `/pos/receipts/sync` is retired (clean rebuild, no live data — no compatibility adapter; cleanup checklist in §14.1).

**The bounded-modules seam — the engine publishes, modules consume via pluggable bridges** `[SoT §13.6, D16]`:

- The fiscal event engine and its server-side `OutboxIngestor` are **POS-module-owned** and have **zero hard dependency** on Treasury, accounting, or sales.
- After `OutboxIngestor` verifies and stores a `fiscal_events` row, it dispatches the event to every **active projector** registered for that event type, via a `FiscalEventProjectionRegistry` (§7.3).
- The **POS-core projection always runs** (it is part of the POS module). **Module bridges run only when their module is active** — "active" being the existing module-activation signal: the `RequireModule` middleware / `enabled_extras` configuration surfaced by `CompanyConfigService` `[reality — module-activation mechanism: `CompanyConfigService`, `CompanyConfig` DTO, `RequireModule` middleware]`. The registry queries module activation per `(tenant, company)` and binds only the active modules' bridges.
- In Phase 1 there is exactly one bridge — the **Treasury receipt bridge** (§7.4) — bound when the Treasury module is active. The reference deployment composes Treasury in, so the bridge runs there; a POS-only deployment runs only the POS-core projection and is fully functional without it.

"REUSE" of receipt code therefore means reuse of its **business-assembly and business-projection logic**, relocated into the POS-core projection (and, for the Treasury-specific effects, into the Treasury bridge) — not reuse of its independent sealing/chaining/sync — see §14.

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

### 7.2 `OutboxIngestor.ingest()` — validate-then-insert, explicit conflict handling (v2 P1)

The v2 algorithm was self-contradictory (prose said checks decide the insert; pseudocode put the insert first) and treated any sequence conflict as idempotent success. v3 makes the ordering unambiguous and the conflict path explicit.

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

  // ---- Step 2: attempt the atomic insert ----
  INSERT INTO fiscal_events (... , server_received_at = now(), integrity_status, payload, ...)
  VALUES (...)
  ON CONFLICT (tenant_id, terminal_id, sequence_number) DO NOTHING
  RETURNING id;

  // ---- Step 3: inserted ----
  if a row was returned:
      dispatch projection (§7.3) unless suppressed (§7.5)
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
          INSERT the conflicting envelope verbatim into fiscal_event_quarantine (§8)
                 with integrity_exception_class = 'sequence_conflict' + mandatory reason
          raise an admin alert
          this terminal's chain enters recorded incident state; resolution is a
                 CHAIN_BREAK_DETECTED / CHAIN_RESTART recovery path (§9)
          return a sequence_conflict result — NOT idempotent success
```

`INSERT … ON CONFLICT DO NOTHING RETURNING id` remains the atomic primitive: two concurrent deliveries of the same event cannot both insert; the loser falls through to Step 4 and, finding an *identical* existing row, returns idempotent success. A *non-identical* conflict is never silently acknowledged — it is quarantined and raised as a chain incident (§8). The row is **always** persisted somewhere — `fiscal_events` (verified or in-table-quarantined) or `fiscal_event_quarantine` (sequence_conflict) — and the device is never blocked.

### 7.3 The projection seam — `FiscalEventProjectionRegistry`

After Step 3 stores a `fiscal_events` row, `OutboxIngestor` dispatches the event to its **active projectors** `[SoT §13.6, D16]`.

```
interface FiscalEventProjector {
    name(): string                          // e.g. 'pos_core_receipt', 'treasury_receipt_bridge'
    handlesEventType(type): bool             // which FiscalEventType values this projector consumes
    requiresModule(): ?string                // null = always active (POS-core); 'treasury' = module-gated
    apply(fiscalEvent): void                 // idempotent, keyed on (fiscal_event_id, projector name)
}

FiscalEventProjectionRegistry.activeProjectorsFor(fiscalEvent):
  for each registered projector P where P.handlesEventType(fiscalEvent.event_type):
     if P.requiresModule() is null            → include P
     else if module P.requiresModule() is active for (tenant, company)   → include P
        // module activation read from the RequireModule / enabled_extras
        // surface via CompanyConfigService [reality — module-activation mechanism]
```

- **POS-core projection** (`requiresModule() = null`) — always registered, always runs. The POS module owns it.
- **Treasury receipt bridge** (`requiresModule() = 'treasury'`) — registered always, but `activeProjectorsFor()` includes it only when the Treasury module is active for that tenant/company.
- The engine and `OutboxIngestor` depend only on the `FiscalEventProjector` interface and the registry — **never** on Treasury, accounting, or sales code directly `[SoT §13.6]`.

### 7.4 The Phase 1 projectors

**`PosCoreReceiptProjection`** — always runs; **POS module**. For a `SALE_RECEIPT` fiscal event it creates the POS-core business effects, which are the business-effect logic of the old `ReceiptSyncService` (its batch transport/orchestration is *not* reused — only its business effects):
- the `pos_receipts` projection row + lines + VAT breakdown,
- `ReceiptPayment` rows — the **POS payment record** (`ReceiptSyncService.php:598-609`),
- voucher redemption (`ReceiptSyncService.php:683-710`),
- stock movement (`ReceiptSyncService.php:713-735`).
It is **idempotent**, keyed on `(fiscal_event.id, 'pos_core_receipt')`. It has **zero** Treasury dependency — a POS-only deployment is complete with just this projector.

**`TreasuryReceiptBridge`** — runs only when the Treasury module is active; **Treasury module**. For a `SALE_RECEIPT` fiscal event it creates the Treasury-specific effects, which are the logic of `ReceiptPaymentService` (`ReceiptPaymentService.php:35-43, 252-276`) `[reality §2.1–§2.2]`:
- one Treasury `Payment` row per receipt payment line, stamped `origin = pos` **and** `fiscal_event_id = <the SALE_RECEIPT event id>` (§13),
- the direct-to-revenue GL posting via `GeneralLedgerService::createPOSPaymentEntry()`.
It is **idempotent**, keyed on `(fiscal_event.id, 'treasury_receipt_bridge')`. It accepts `fiscal_event_id` and `origin` as inputs — the bridge is where those fields are populated.

> **Resolves v2 BLOCKER 1.** v2 wrongly said the Treasury `Payment` effects come from `ReceiptSyncService`. `ReceiptSyncService` creates `ReceiptPayment` rows (POS module); **`ReceiptPaymentService` creates Treasury `Payment` rows + GL** `[reality §2.1–§2.2; confirmed by the v2 Codex review]`. v3 puts the POS-core effects in `PosCoreReceiptProjection` and the Treasury effects in `TreasuryReceiptBridge` — which is exactly the SoT §13.6 / D16 seam.

### 7.5 Projection failure / retry contract (v2 BLOCKER 2)

v2 left undefined whether projection runs in the `fiscal_events` insert transaction or separately. v3 specifies it:

- **`fiscal_events` persists in its own transaction** (the §7.2 insert). Once committed, the fiscal event is durable, authoritative chain truth — independent of any projection outcome.
- A new **`fiscal_event_projections`** table tracks each projector's run:

```
fiscal_event_projections {
  id                   UUID         PK
  fiscal_event_id      UUID         NOT NULL  REFERENCES fiscal_events(id)
  projector_name       VARCHAR(64)  NOT NULL            -- 'pos_core_receipt' | 'treasury_receipt_bridge'
  projection_status    VARCHAR(16)  NOT NULL DEFAULT 'pending'   -- pending|applied|failed
  attempts             INTEGER      NOT NULL DEFAULT 0
  last_error           TEXT
  applied_at           TIMESTAMPTZ
  created_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW()
  updated_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW()
  UNIQUE (fiscal_event_id, projector_name)
}
```

  This table is **mutable** (it is not chain truth) and carries no immutability trigger.
- **Dispatch:** in the §7.2 insert transaction, `OutboxIngestor` also inserts one `fiscal_event_projections` row (`pending`) per active projector for the event type. After the transaction commits, it enqueues one projection job per `pending` row.
- **Apply:** each projection job runs `projector.apply(fiscalEvent)` in **its own transaction**, idempotently (keyed on `(fiscal_event_id, projector_name)`). On success → `projection_status = 'applied'`, `applied_at` set. On failure → `projection_status = 'failed'`, `last_error` set, `attempts` incremented; the job retries with backoff.
- **Dead-letter:** after a configured max attempts the projection stays `failed`, an **operator alert** is raised, and the row is surfaced in an operator-visible dead-letter view for manual replay. Replay re-runs the same idempotent `apply()`.
- **Suppression:** if the event was quarantined with `canonical_parse_failure`, **no `fiscal_event_projections` rows are created** and no projection is dispatched — there is no trusted `payload` to project; projection resumes only after operator resolution flips `payload_parse_status → parsed`. For `canonical_hash_mismatch` and `time_anomaly` the event is accepted-and-flagged and **projection proceeds** (the transaction physically happened and counts, per `[SoT §7.1]`); the resulting `pos_receipts` / `Payment` / GL rows inherit the flag via the event's `integrity_status`, and operator resolution can reverse them with compensating events if warranted.
- **Invariant:** a projection failure **never** mutates or deletes the `fiscal_events` row. Fiscal truth is preserved regardless of business-projection outcome `[SoT D1, D13]`. Conversely, a projection is never run inside the fiscal-event insert transaction — "the row is always persisted" (§7.2) is never in tension with a projection failure.

### 7.6 The strict parser

The structured `payload` JSONB is derived server-side from the verified `canonical_bytes` by a strict parser — rejects duplicate keys, out-of-grammar numbers, invalid Unicode, event-type schema violations `[SoT §5]`. On failure: `payload = NULL`, `payload_parse_status = 'failed'`, `integrity_status = 'quarantined'`, `integrity_exception_class = 'canonical_parse_failure'`. Canonical bytes remain authoritative.

---

## 8. Per-anomaly-class integrity exceptions + quarantine

`[SoT §7]`. **Accept-and-flag per anomaly class — never block the device.**

| Class | Trigger | Persisted to | Handling |
|---|---|---|---|
| `canonical_hash_mismatch` | `SHA-256(canonical_bytes) ≠ current_hash` | `fiscal_events`, `integrity_status='quarantined'` | accept, quarantine, annotate; counts, flagged; projection proceeds (§7.5) |
| `canonical_parse_failure` | bytes hash OK but fail the strict grammar | `fiscal_events`, `integrity_status='quarantined'` | accept, quarantine, annotate; **`payload` NULL, projection suppressed** until resolution; raw bytes conserved |
| `time_anomaly` | clock rollback / excessive drift (§10) | `fiscal_events`, `integrity_status='quarantined'` | accept, quarantine, annotate; projection proceeds (§7.5) |
| `sequence_gap` | gap/break in the per-terminal sequence detected at linkage check | `fiscal_events`, `integrity_status='quarantined'` | accept; chain in recorded incident state; the gap is an **explicit exception total**, not silently in clean totals |
| `sequence_conflict` | a *different* event claims an already-occupied `(tenant,terminal,sequence)` slot (§7.2 Step 4) | **`fiscal_event_quarantine`** — it cannot enter `fiscal_events` (UNIQUE key) | persist verbatim; admin alert; chain incident → `CHAIN_BREAK_DETECTED` / `CHAIN_RESTART` (§9); **never idempotent success** |

`sequence_conflict` is the "break in the per-terminal sequence" case of `[SoT §7.1]`'s `sequence_gap` family, distinguished here because its storage path differs: the conflicting envelope physically cannot occupy the taken sequence slot in `fiscal_events`, so it goes to a dedicated **`fiscal_event_quarantine`** table (a quarantine partition for envelopes not admissible to the chain). In-table `integrity_status='quarantined'` covers admitted-but-flagged events; `fiscal_event_quarantine` covers non-admissible envelopes.

```
fiscal_event_quarantine {
  id                          UUID         PK
  tenant_id                   UUID         NOT NULL
  terminal_id                 UUID         NOT NULL
  claimed_sequence_number     BIGINT       NOT NULL
  envelope_event_id           UUID                       -- the device-claimed id, for forensics
  canonical_bytes             BYTEA        NOT NULL       -- the conflicting envelope, verbatim
  current_hash                CHAR(64)     NOT NULL
  previous_hash               CHAR(64)     NOT NULL
  integrity_exception_class   VARCHAR(32)  NOT NULL       -- 'sequence_conflict' in Phase 1
  integrity_exception_reason  TEXT         NOT NULL
  conflicting_event_id        UUID                        -- the fiscal_events row already holding the slot
  server_received_at          TIMESTAMPTZ  NOT NULL
  resolved_at                 TIMESTAMPTZ
  resolved_by                 UUID
  created_at                  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
}
```

`signature_invalid` does not arise in Phase 1 (no signature provider active). A quarantined event — in either table — is **always persisted** with a **mandatory structured reason**, an admin alert, and an operator-resolution path. **Exports include a quarantine section with reconciliation totals — never silent exclusion** `[SoT §7.2]`; the export reconciliation must span both quarantine surfaces.

---

## 9. Chain-recovery events

`[SoT §7.3]`. On a local chain break, the terminal continues operating in a recorded `degraded` mode. Recovery is **two chained incident events** (not "signed" — no signature provider in Phase 1): `CHAIN_BREAK_DETECTED` (reason, last-good sequence + hash, offending record reference) and `CHAIN_RESTART` (new genesis reference, last-good anchor, operator authorization evidence, provenance link to the prior chain). Both are ordinary `fiscal_events` rows. The broken segment is never deleted — quarantined (§8), synced, flagged in exports. A server-detected `sequence_conflict` (§7.2 Step 4) is one trigger for this recovery path.

---

## 10. Clock / time model

`[SoT §6]`. The device clock is untrusted `[reality §3]`. Unchanged from v2: `event_time_device` (untrusted), `sequence_number` (the authoritative ordering), `last_server_time_seen`, `server_received_at`. Clock-rollback and drift detection → `time_anomaly` (§8), accepted not blocked. Normative closure-period rule: `business_date` is assigned by the **terminal-configured fiscal timezone and session boundary** — not `server_received_at`, not raw device time; a clock anomaly never moves an event between closure periods without an explicit correction event.

---

## 11. Company-level integrity record types

`[SoT §9]`.

- **`TERMINAL_REGISTRY_SNAPSHOT` — implemented in Phase 1.** It has no closure dependency: a registry snapshot (the authoritative list of terminals expected for a company at a point in time; carries a hash; links to the prior snapshot) can be emitted at terminal provisioning and on demand. Phase 1 delivers the event type, the payload DTO, the `append()` handler, and an initial-snapshot emission path.
- **`COMPANY_DAY_CLOSURE_MANIFEST` — reserved in Phase 1.** It depends on day-closures (a later phase). Phase 1 delivers the event type registration + the payload DTO schema only; `append()` throws `FiscalEventTypeNotImplemented` for it until the closure-rollout phase.

Appendix A reflects this split.

---

## 12. Off-device durability controls

`[SoT §8]`. Unchanged from v2. Device authority is not survivable without off-device conservation; an on-device backup encrypted with an on-device key is not a conservation control `[reality §3 — plaintext `.izipos_key`]`. Phase 1 delivers: at least one off-device durability path (encrypted removable archive / LAN peer / NAS / cloud-sync) with key custody **outside** the terminal disk; the on-device AES-GCM copy as crash-recovery only; an operator-visible unsynced-risk indicator; a forced archive/export threshold; a maximum-unsynced escalation; a device-loss incident register. **A Phase 1 gate before any Phase 2 customer-facing deployment.**

---

## 13. `Payment.origin` / `Payment.fiscal_event_id` — Treasury-module integration

`[reality §2.3 — the complete Treasury `Payment` writer inventory]`. **This section is Treasury-module integration work, not POS-core** `[SoT §13.6, D16]`: the `payments` table belongs to the Treasury module, every writer below is Treasury-module code, and the new `fiscal_event_id` FK runs `payments → fiscal_events` — the correct dependency direction (a module depends on the engine; the engine never depends on the module). The POS base does **not** depend on Treasury `Payment` rows existing. It is delivered in Phase 1 *for the reference deployment* (which composes Treasury in) and is consumed by the `TreasuryReceiptBridge` (§7.4); a POS-only deployment neither has nor needs it.

- **Migration:** add `payments.origin VARCHAR(32) NULL` and `payments.fiscal_event_id UUID NULL` (FK `fiscal_events(id)`).
- **`PaymentOrigin` enum:** `pos | web_admin | mobile | api | unknown_legacy`.
- **`Payment` model:** add both to `$fillable`; cast `origin` to `PaymentOrigin`.
- **Writer updates — the COMPLETE Treasury `Payment` writer inventory, all in the same change** `[reality §2.3; confirmed exhaustive by the v2 Codex review's live grep]`:

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
| `terminal_state`, `offline_receipts` (client) | **REWORK** | `terminal_state` gains the single fiscal-event chain head (§6.2); `offline_receipts` becomes a projection row mirroring the `fiscal_events` chain values, gains `canonical_bytes`. |
| `ReceiptSyncService` — batch transport/orchestration | **DISCARD** | `/pos/receipts/sync` is retired; the device posts to the single fiscal-event endpoint (§7.1). Cleanup checklist: §14.1. |
| `ReceiptSyncService` — stock/voucher/`ReceiptPayment` business-effect logic | **REUSE (relocated → `PosCoreReceiptProjection`)** | Extracted into the POS-core projection (§7.4), invoked idempotently by `OutboxIngestor`. This is `pos_receipts`/lines/VAT, `ReceiptPayment` rows, voucher redemption, stock movement — **POS module**. |
| `ReceiptPaymentService` — Treasury `Payment` + GL logic | **REUSE (relocated → `TreasuryReceiptBridge`)** | Extracted into the pluggable Treasury bridge (§7.4), invoked by `OutboxIngestor` only when the Treasury module is active; accepts `fiscal_event_id` / `origin`. **Treasury module.** |
| `ReceiptFinalizationService` + `ReceiptHashService::verifyTerminalChain()` / `verifyHash()` | **REWORK** | From recompute-from-models → re-hash the stored `canonical_bytes`; verification walks the `fiscal_events` chain. |
| `OfflineFiscalHashMismatchException` throw-and-rollback | **DISCARD** | Replaced by per-class accept/flag (§8). The class may remain as an audit-trail artifact; never thrown to block. Production import/throw at `ReceiptSyncService.php:23,629`; test deps at `ReceiptSyncServiceTrainingModeTest.php:136`, `ReceiptSyncServiceVoucherRedemptionTest.php:500` `[v2 Codex review]`. |
| `Compliance/FiscalHashService` chain-prefix logic | **REUSE** | Generic; unaffected. |
| `Nf525XmlBuilder` | **REUSE** | DTO→XML serialization of a derived export artifact — permitted under D2. |
| `Nf525DataProvider` + the JET verification path | **REWORK** | It reads `Receipt` models directly and verifies via `ReceiptHashService::calculateHash()` (server-recompute) — reworked to read verified `canonical_bytes` + quarantine state from `fiscal_events` (and `fiscal_event_quarantine`). |
| `canonical_bytes` columns (`pos_receipts` `BYTEA` / `offline_receipts` `TEXT`) | **NEW** | — |
| Per-class integrity-exception / quarantine path for receipts | **NEW** | Same machinery as §8. |
| JET export sections for quarantine / incidents / manifests | **NEW** | Reconciliation spans `fiscal_events` quarantine + `fiscal_event_quarantine`. |
| Web POS (`TerminalType::Web`) receipt-creation path | **DISPOSITION (preflight, §2)** | Not reworked in Phase 1. Confirmed-unused or hidden/disabled so it does not coexist with the rebuilt chain (`[SoT D8]`); device-authority parity deferred (§18). |

`SALE_RECEIPT` is a **first-class fiscal event** on the single chain — no `SALE_RECEIPT_BRIDGE` (D10).

### 14.1 `/pos/receipts/sync` retirement — concrete cross-app cleanup checklist (v2 P2)

The v2 Codex review verified the live dependency surface; the rebuild must clear all of it (no compatibility adapter unless the owner explicitly chooses one):

**POS client (`apps/pos`):**
- Replace `pushOfflineReceipts` / `receiptToPayload` (`syncService.ts:333-336`, `:1767-1877`) with the fiscal-event envelope push to `POST /api/v1/pos/sync/fiscal-events` (§7.1).
- Update `syncService.test.ts:293,800` — migrate the receipt-sync tests to fiscal-event ingestion tests.
- Update `fetchWithTimeout.ts:30` and `fetchWithTimeout.test.ts:51,61` — the timeout helper/fixtures that encode the old path.

**Backend (`apps/api`):**
- Remove or hard-disable the `/pos/receipts/sync` route (`routes.php:85`) and `SyncController::syncReceipts` (`SyncController.php:49-68`).
- Migrate the POS backend feature suites that target the retired endpoint — `SyncReceiptsTest.php`, `ReceiptSyncServiceV3Test.php`, and the other `tests/Feature/POS/*` receipt-sync tests — to fiscal-event ingestion tests.
- Retire `SyncReceiptPayload` (`SyncReceiptPayload.php:65-91`) and its DTO tests, or repoint them at the new typed envelope.

**Verification:** after cleanup, a repo-wide grep for `/pos/receipts/sync`, `pushOfflineReceipts`, `syncReceipts` returns only the new fiscal-event path or intentional historical references — no live caller of the retired route.

---

## 15. The `fiscal:verify-event-chain` command

```
php artisan fiscal:verify-event-chain {--tenant=} {--terminal=} {--from-sequence=}
```
- Walks `fiscal_events` for the terminal in `sequence_number` order; re-hashes the stored `canonical_bytes`; asserts `current_hash` matches; asserts `previous_hash` links to the prior event's `current_hash` (first event → terminal `fiscal_event_genesis_seed`).
- Also reports any `fiscal_event_quarantine` rows for the terminal as chain incidents (so a `sequence_conflict` is visible to the verifier, not just clean-chain breaks).
- **Exit codes:** `0` = chain verified, no incidents; non-zero = a break or a quarantine incident, with the `sequence_number` and the expected-vs-actual hash on stderr.
- Filters: `--tenant`, `--terminal`, `--from-sequence`. Permission-gated by `fiscal.events.verify_chain`.
- CI fixtures: a valid seeded chain (passes); a deliberately-tampered fixture (fails at the right point); a seeded `sequence_conflict` fixture (fails as an incident).

---

## 16. Migration plan

All migrations additive; the preflight gate (§2) is the hard precondition.

1. **Preflight verification gate** (§2) — server-side + device-side + web-POS disposition; first task; blocks all schema-destructive work.
2. `create_fiscal_events_table` — server PostgreSQL (§3.2), indexes, CHECK constraints.
3. `create_fiscal_events_immutability` — the §3.3 triggers + `REVOKE TRUNCATE` + break-glass runbook note.
4. `create_fiscal_event_projections_table` — the §7.5 projection-tracking table (mutable, no immutability trigger).
5. `create_fiscal_event_quarantine_table` — the §8 non-admissible-envelope quarantine partition.
6. Device SQLite migration — `fiscal_events` table (§3.1) + its triggers + `terminal_state` single fiscal-event chain head (§6.2).
7. `add_canonical_bytes_to_pos_receipts` (`BYTEA`) + device `offline_receipts.canonical_bytes` (`TEXT`); convert `pos_receipts`/`offline_receipts` chain columns to mirrors (§5.0).
8. `add_origin_and_fiscal_event_id_to_payments` (§13) — Treasury-module integration migration.
9. Company-integrity event-type registration (§11) — `TERMINAL_REGISTRY_SNAPSHOT` handler + DTO; `COMPANY_DAY_CLOSURE_MANIFEST` DTO + reserved registration.

No fiscal data backfill — the chain is born empty (preflight gate confirms this).

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
- `sequence_conflict` — a **different** event at an occupied `(tenant,terminal,sequence)` slot → routed to `fiscal_event_quarantine`, admin alert, **not** idempotent success; chain-incident path triggered.
- The strict parser — rejects duplicate keys, out-of-grammar numbers, invalid Unicode; `canonical_parse_failure` → quarantine, `payload = NULL`, projection suppressed.
- Immutability — `UPDATE`/`DELETE`/`TRUNCATE` on `fiscal_events` raise per §3.3; allowed-column updates work; forbidden column updates raise; `TRUNCATE` denied to the app role.
- Golden-vector PHP side — `sha256(expected_canonical_string) == expected_sha256_hex`.

### 17.3 Server — projection seam (PHPUnit, `RefreshDatabase`)
- `FiscalEventProjectionRegistry` — POS-core projector always included; Treasury bridge included only when the Treasury module is active for the tenant/company; excluded when inactive.
- `PosCoreReceiptProjection` — idempotent on `(fiscal_event_id, 'pos_core_receipt')`; creates `pos_receipts` row + lines + VAT, `ReceiptPayment` rows, voucher redemption, stock movement exactly once; runs with **no Treasury module active** and is complete.
- `TreasuryReceiptBridge` — idempotent on `(fiscal_event_id, 'treasury_receipt_bridge')`; creates Treasury `Payment` rows (`origin='pos'`, `fiscal_event_id` set) + GL posting exactly once; **does not run** when the Treasury module is inactive.
- End-to-end with Treasury active — one `SALE_RECEIPT` produces, exactly once: `pos_receipts` + lines + VAT, `ReceiptPayment` rows, voucher redemptions, stock movements (POS-core), **and** Treasury `Payment` rows + GL entries (bridge).
- Projection failure contract — a bridge failure leaves `fiscal_events` untouched, sets `projection_status='failed'` + `last_error`, increments `attempts`, retries; after max attempts → dead-letter + operator alert; manual replay re-applies idempotently. POS-core success + Treasury-bridge failure leaves the POS-core effects intact.
- Suppression — `canonical_parse_failure` creates no `fiscal_event_projections` rows; `canonical_hash_mismatch` / `time_anomaly` proceed flagged.

### 17.4 Receipt-chain rebuild
- `ReceiptFinalizationService` / `verifyTerminalChain` re-hash stored `canonical_bytes` (no recompute-from-models).
- A receipt sync replay → no duplicate (the `UNIQUE` + `source_event_*` + `ON CONFLICT` path holds).
- `Nf525DataProvider` reads verified `canonical_bytes` + includes quarantine state from both quarantine surfaces.

### 17.5 `/pos/receipts/sync` retirement (v2 P2)
- The device posts `SALE_RECEIPT` to `POST /api/v1/pos/sync/fiscal-events`; the retired route is gone or hard-disabled.
- The §14.1 checklist is complete: POS-client caller replaced, backend route/controller removed, receipt-sync feature suites migrated, timeout helpers/fixtures updated, `SyncReceiptPayload` retired/repointed.
- Verification grep (§14.1) returns no live caller of the retired route.

### 17.6 `Payment` writers (Treasury-module integration, §13)
- **Every** writer in the §13 table stamps `origin`; the `TreasuryReceiptBridge` path also stamps `fiscal_event_id`.

### 17.7 Chain verifier
- `fiscal:verify-event-chain` (§15) — passes on a seeded valid chain; fails with the break point on a tampered fixture; fails as an incident on a seeded `sequence_conflict` fixture. CI gate.

---

## 18. Open items

1. **Preflight gate execution** — server-side + device-side + web-POS disposition; written owner sign-off before any schema-destructive work (§2).
2. **Web-POS device-authority parity** — `TerminalType::Web` is scoped out of Phase 1 and dispositioned (hidden/disabled or confirmed-unused) in the preflight gate. How (or whether) a browser-based web POS is brought onto the device-authority pattern — or replaced — is deferred parity work, tracked here, owner-decided in a later phase.
3. **Z-report chain** — independent at the protocol level but shares `terminal_state` + `Nf525DataProvider` (roadmap v2 coordination caveat); its rebuild is a separate task that must coordinate migrations/touchpoints with Phase 1.
4. **Exact `pos_receipts` constraints** — confirm the current `receipt_type` migration + totals CHECK when the rebuild touches `pos_receipts` (reality-doc §6 discrepancies; neither affects a Phase 1 design decision).
5. **Module-activation read surface** — §7.3 reads module activation via `CompanyConfigService` / the `RequireModule` / `enabled_extras` surface; the writing-plan should confirm the exact call shape and per-`(tenant, company)` resolution against current code.

---

## Appendix A — Phase 1 `FiscalEventType` reserved values

**Implemented in Phase 1** (has an `append()` handler): `SALE_RECEIPT` (first-class via the rebuilt chain, §5.0), `CHAIN_BREAK_DETECTED`, `CHAIN_RESTART`, `TERMINAL_REGISTRY_SNAPSHOT` (§11).

**Reserved** (CHECK-listed; `append()` throws `FiscalEventTypeNotImplemented` until the type's phase): `COMPANY_DAY_CLOSURE_MANIFEST` (§11), `ACCOUNT_PAYMENT`, `ACCOUNT_CHARGE`, `ACCOUNT_REFUND`, `ACCOUNT_PAYMENT_RECONCILED`, `ACCOUNT_CREDIT_ISSUE`, `ACCOUNT_CREDIT_USAGE`, `DEPOSIT_RECEIPT`, `IDENTITY_ALIAS_RECONCILED`, `SALE_VOID`, `SALE_CORRECTION`, `REFUND_RECEIPT`, `PARTIAL_REFUND`, `RETURN_WITHOUT_RECEIPT`, `OPENING_FLOAT`, `CASH_IN`, `CASH_OUT`, `SAFE_DROP`, `CASH_CORRECTION`, `SESSION_OPEN`, `SESSION_CLOSE`, `X_REPORT`, `Z_REPORT`, `REPRINT_COPY`.

---

**End of Phase 1 spec v3.**
