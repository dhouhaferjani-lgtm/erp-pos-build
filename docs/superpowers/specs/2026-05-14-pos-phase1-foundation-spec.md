# POS Phase 1 — Foundation: Fiscal Event Engine + Receipt-Chain Clean Rebuild

**Date:** 2026-05-14
**Phase:** 1 of 5 (roadmap v2)
**Status:** Drafted; awaiting self-review → owner review → Codex adversarial review → writing-plans.

**Grounding (all locked / done — every codebase claim in this spec is traceable to one of these):**
- **Source-of-truth v3** (LOCKED, Codex SOUND-WITH-CORRECTIONS) — `2026-05-14-offline-first-fiscal-source-of-truth-v3.md`. Cited as `[SoT §N]`.
- **Codebase reality audit** — `2026-05-14-pos-fiscal-codebase-reality.md`. Cited as `[reality §N]`.
- **Receipt-chain clean-rebuild scoping map** — 2026-05-14 scoping audit (with the `Nf525DataProvider` correction in `[SoT §12]`).
- **Roadmap v2** (corrected) — `2026-05-14-pos-customer-accounts-roadmap-v2.md`.

**Predecessors superseded:** the pre-pivot `2026-05-14-pos-fiscal-event-engine-phase1.md` draft (server-authored design — wrong, replaced by the device-authority model).

---

## 1. Overview

### 1.1 What Phase 1 is

The foundation. Phase 1 establishes the **one fiscal pattern** `[SoT §1]` — device authors and seals locally, server verifies-verbatim and mirrors — and **rebuilds the existing receipt chain clean on that pattern**. No customer-facing feature. When Phase 1 is done, the fiscal event engine exists, the receipt chain runs the correct pattern, and the schema carries the non-retrofittable hooks (signature lifecycle, durability, company-integrity record types) that later phases depend on.

### 1.2 Scope (in)

1. The **preflight verification gate** (§2) — executed before any destructive rebuild step.
2. The **`fiscal_events` table** — device SQLite + server PostgreSQL mirror (§3).
3. The **canonical serialization contract** (§4).
4. **`HashChainIntegrityProvider`** + the **`SignatureProviderInterface`** abstraction (§5).
5. The **device-side fiscal event engine** — authoring, sealing, chain-head management (§6).
6. The **server-side verify-only mirror** — the typed fiscal-event outbox endpoint + `OutboxIngestor`, verify-verbatim, the strict parser (§7).
7. The **per-anomaly-class integrity-exception path** + quarantine (§8).
8. **Chain-recovery events** — `CHAIN_BREAK_DETECTED` + `CHAIN_RESTART` (§9).
9. The **clock / time model** (§10).
10. **Company-level integrity record types** — `TERMINAL_REGISTRY_SNAPSHOT`, `COMPANY_DAY_CLOSURE_MANIFEST` (§11).
11. **Off-device durability controls** `[SoT §8]` (§12).
12. **`Payment.origin` / `Payment.fiscal_event_id`** — migration, `PaymentOrigin` enum, writer updates (§13).
13. The **receipt-chain clean rebuild** — reuse/rework/discard/new (§14).

### 1.3 Scope (out — Phase 2+, tracked in roadmap v2)

- Anything customer-facing — customer mirror, search/create/attach, `ACCOUNT_PAYMENT` (Phase 2).
- Charge-to-account, the AR GL path, B2B Facture routing (Phase 3).
- Account-status lifecycle, override flows, the approval primitive (Phase 4).
- Deposits, identity reconciliation, AML, store credit (Phase 5).
- The **Z-report chain clean rebuild** — independent at the chain-protocol level; its own task; coordination caveat in roadmap v2.
- The actual `TseSignatureProvider` — Phase 1 ships only the *interface* and the nullable signature columns (design-for-not-build, `[SoT §4.2]`).
- Session events, B2B document events into the engine, NF525 certification submission.

### 1.4 The failure modes Phase 1 must not repeat

The earlier specs hit four Codex BLOCKs. Two root causes, both now closed:
- **Mis-placed architecture** — closed: device-authority is locked in `[SoT §1]`.
- **Unverified codebase claims** — closed by discipline: every codebase claim here carries a `[reality §N]` citation or is explicitly flagged "to verify."

---

## 2. The preflight verification gate

`[SoT §1]` requires this before **any** destructive rebuild step. The clean-rebuild *decision* is locked (no migration); the *precondition* — that no live fiscal data exists — is an operational fact, not a codebase fact, and must be verified.

**The gate:**
1. Query every staging and production tenant database for fiscal data: `pos_receipts`, `offline_receipts` (SQLite), `z_reports`, `pos_terminals` chain state (`last_hash` / `current_sequence` non-default), and receipt-print records.
2. Produce a written report of what was found, per environment.
3. **Written owner sign-off** that the environments are empty (or that any data found is disposable test data).
4. **If any non-disposable fiscal data is found:** stop. The clean rebuild does not proceed; an archival/export path is defined and executed first, and the rebuild approach is re-scoped.

The gate is a **hard precondition** on the Phase 1 implementation plan — the first task, blocking all schema-destructive work.

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
  event_time_device          TEXT     NOT NULL       -- device-claimed UTC ISO-8601 (untrusted; see §10)
  business_date              TEXT     NOT NULL       -- YYYY-MM-DD; assigned per §10 closure rule
  last_server_time_seen      TEXT                    -- most recent trusted server time the device observed (§10)

  reference_event_id         TEXT                    -- compensating events; NULL in Phase 1 except CHAIN_* recovery
  reference_document_id      TEXT                    -- cross-link to a pos_receipts row etc.
  source_event_class         TEXT                    -- for idempotent emission (e.g. 'Receipt')
  source_event_id            TEXT                    -- for idempotent emission
  partner_id                 TEXT                    -- Phase 2+; NULL in Phase 1
  partner_identity_snapshot  TEXT                    -- JSON; Phase 2+; NULL in Phase 1

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

Local triggers: `BEFORE UPDATE` and `BEFORE DELETE` on `fiscal_events` `RAISE(ABORT)` — except the controlled `sync_status` / `sync_error` / `synced_at` transitions owned by the sync layer (the same offline-row discipline the existing `offline_receipts` table uses `[reality §3]`). The fiscal columns (`canonical_bytes`, `previous_hash`, `current_hash`, `sequence_number`, payload fields) are never updated after insert.

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
  server_received_at         TIMESTAMPTZ  NOT NULL        -- set by the OutboxIngestor on ingestion (§7, §10)

  reference_event_id         UUID
  reference_document_id      UUID
  source_event_class         VARCHAR(255)
  source_event_id            UUID
  partner_id                 UUID
  partner_identity_snapshot  JSONB

  canonical_bytes            BYTEA        NOT NULL        -- the device's exact canonical bytes, stored verbatim
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
  integrity_exception_class  VARCHAR(32)                                -- §8.1 class, when quarantined
  integrity_exception_reason TEXT                                       -- mandatory structured reason when quarantined
  integrity_resolved_at      TIMESTAMPTZ
  integrity_resolved_by      UUID

  -- derived structured payload (§7.3)
  payload                    JSONB                                      -- derived server-side by the strict parser; NULL if parse failed
  payload_parse_status       VARCHAR(16)  NOT NULL DEFAULT 'parsed'      -- parsed|failed

  created_at                 TIMESTAMPTZ  NOT NULL DEFAULT NOW()
}
```

**Indexes & constraints:**
```
UNIQUE (tenant_id, terminal_id, sequence_number)                       -- chain integrity + idempotency key
UNIQUE (source_event_class, source_event_id)
       WHERE source_event_id IS NOT NULL                               -- idempotent bridge/source emission
INDEX  (reference_event_id)      WHERE reference_event_id IS NOT NULL
INDEX  (reference_document_id)   WHERE reference_document_id IS NOT NULL
INDEX  (integrity_status)        WHERE integrity_status <> 'verified'   -- quarantine queue
CHECK  (sequence_number > 0)
CHECK  (current_hash  ~ '^[0-9a-f]{64}$')
CHECK  (previous_hash ~ '^[0-9a-f]{64}$')
CHECK  ((source_event_class IS NULL     AND source_event_id IS NULL)
     OR (source_event_class IS NOT NULL AND source_event_id IS NOT NULL))   -- both-or-neither
CHECK  (event_type IN ( ...Appendix A reserved values... ))
```

**Immutability** `[SoT §8 — corrects the existing pos_receipts trigger which misses TRUNCATE, reality §1.5]`:
- Trigger `fiscal_events_immutability`: `BEFORE UPDATE OR DELETE ... FOR EACH ROW → RAISE EXCEPTION`; `BEFORE TRUNCATE ... FOR EACH STATEMENT → RAISE EXCEPTION`. The **only** permitted updates are the controlled `integrity_status` / `integrity_exception_*` / `integrity_resolved_*` transitions and the `payload` / `payload_parse_status` fields written once by the ingestor — these are excluded from the trigger by a column-scoped allow-list.
- `REVOKE TRUNCATE ON fiscal_events FROM <application_role>`.
- Break-glass: DBA-only maintenance touching `fiscal_events` requires a signed full export first; documented in an ops runbook.

**Notes:**
- `previous_hash` is **never NULL** — the first event on a terminal uses the terminal's fiscal-event genesis seed (§6.2, Appendix B of `[SoT]`).
- Hash representation is **lowercase hex, `CHAR(64)`**, everywhere `[reality §1.3]`. Only `canonical_bytes` is binary (`BYTEA` / SQLite `TEXT` holding the exact UTF-8 byte string).
- `sequence_number` is **continuous across fiscal years** `[SoT §3]`; the year is recoverable from `business_date`.

---

## 4. Canonical serialization contract

`[SoT §1.2, §5]`. The device serializes **once**; the server **never re-serializes**.

### 4.1 The canonical object

For each fiscal event the device builds a canonical JSON object with **lexicographically sorted keys** (RFC 8785 / JCS), containing:
```
business_date, company_id, event_time_device, event_type, event_version,
operator_id, payload, previous_hash, reference_document_id, reference_event_id,
sequence_number, signature_version, tenant_id, terminal_id
```
`previous_hash` is **embedded in the canonical object** as a hex string — matching the existing receipt-V3 pattern `[reality §1.4]` so the device and any future re-implementation share one mental model.

### 4.2 Value grammar

- **Numbers:** integers only. **No floats — ever.** (The existing `CanonicalJsonEncoder` throws on floats `[reality §1.4]`; this grammar guarantees it never sees one.)
- **Money:** decimal **strings** via `CurrencyScale::bcformat()` — the existing fiscal-stack convention `[reality §1.4]` and project-memory discipline.
- **Timestamps:** UTC ISO-8601, second precision, `Z` suffix: `2026-05-14T13:22:05Z`.
- **Strings:** UTF-8, NFC-normalized; **U+2028 / U+2029 stripped at the producer** (closes the documented PHP/JS divergence `[reality §1.4]`).
- **Arrays:** sorted by a DTO-documented key where order is not semantically meaningful.
- **Object keys:** sorted lexicographically.

### 4.3 `canonical_bytes`, hash, and the cross-language guarantee

- `canonical_bytes` = the UTF-8 encoding of the canonical object. It is **stored verbatim** — device SQLite `TEXT`, server `BYTEA` — and **never round-tripped through a JSON/JSONB column** `[SoT §1.2]`.
- `current_hash` = `lowercase_hex( SHA-256( canonical_bytes ) )`.
- The server verifies by re-hashing the device's exact `canonical_bytes` — `SHA-256` of an identical byte string is identical in every language. **The server never re-serializes a fiscal payload for hashing** (D2). This is the RFC-7797 *principle* (verify the bytes as received); Phase 1 is not JWS and gains no authorship guarantees from it `[SoT §1.2]`.

### 4.4 The encoder + golden vectors

- `FiscalEventCanonicalEncoder` (device, TypeScript) — applies the §4.2 string normalization, then structural canonical encoding. It reuses the structural logic of the existing `CanonicalJsonEncoder` pattern `[reality §1.4]`; it is **not** mirrored in PHP.
- **Cross-language golden vectors** — a committed fixture set `{ payload_dto_input, expected_canonical_string, expected_sha256_hex }`. A **TypeScript test** asserts the device encoder reproduces them; a **PHP test** asserts only that `sha256(expected_canonical_string) == expected_sha256_hex` (PHP never serializes — it only re-hashes). The fixture matrix must include: a TND 3-decimal amount, a 2-decimal currency, a 0-decimal currency, a negative amount, empty arrays / null optionals, a multibyte/NFC string, a U+2028/U+2029 producer-normalization case, and a non-ASCII key-ordering case.

---

## 5. `HashChainIntegrityProvider` + `SignatureProviderInterface`

`[SoT §4]`. Integrity and signature are **two orthogonal concerns**.

### 5.1 `HashChainIntegrityProvider` — the first and only provider in Phase 1

Honestly named — it provides **sequence integrity** (chained SHA-256 makes insertion/deletion/reordering detectable), **not authorship** `[SoT §4.1]`. `signature_version = 'hash-chain-integrity-v1'`.

```
interface FiscalIntegrityProvider {
    version(): string                                  // 'hash-chain-integrity-v1'
    canonicalBytes(event): bytes                        // §4
    computeHash(canonicalBytes): hex64                  // SHA-256, lowercase hex
    verify(event): bool                                 // recompute over stored canonical_bytes; compare current_hash
}
```

### 5.2 `SignatureProviderInterface` — designed-for, not built

The authorship layer (future TSE etc.). **Phase 1 ships the interface and the nullable signature columns (§3); no concrete provider.** `[SoT §4.2]`. The interface is **async-capable** — `sign()` may return `pending` — with capability flags (`requires_connectivity`, `signs_synchronously`, `assigns_transaction_id`).

```
interface SignatureProvider {
    version(): string
    capabilities(): { requires_connectivity, signs_synchronously, assigns_transaction_id }
    sign(event): SignatureResult | Pending              // may complete asynchronously
    verifySignature(event): bool
}
```

The `signature_status` enum (`not_required | pending | signed | failed`) and the signature object are **non-retrofittable** — they are in the Phase 1 schema (§3) so a future `TseSignatureProvider` is additive, not a migration over immutable rows. In Phase 1 every event is `signature_status = 'not_required'`. **"Signed" is reserved for jurisdictions with an active signature provider; pre-signature events are never retroactively upgraded** `[SoT §4.2]`.

---

## 6. The device-side fiscal event engine

The authority `[SoT §1.1]`. Lives in the Tauri app, against device SQLite. Reuses the proven receipt-sealing patterns `[reality §3 — chain-head table, sequence allocation, hash-compute-and-advance, offline-row lifecycle, debounced sync]`.

### 6.1 `FiscalEventEngine` (device)

```
FiscalEventEngine.append(request: FiscalEventEmissionRequest): FiscalEvent
  // runs inside one SQLite transaction:
  1. resolve the payload DTO + event_version via the FiscalEventPayloadRegistry;
     throw FiscalEventTypeNotImplemented if the type has no Phase-1 handler
  2. read the terminal fiscal-event chain head (§6.2): previous_hash, sequence_number
  3. build the canonical object (§4.1), apply the value grammar (§4.2),
     produce canonical_bytes
  4. current_hash = HashChainIntegrityProvider.computeHash(canonical_bytes)
  5. INSERT the fiscal_events row (sync_status='pending', signature_status='not_required')
  6. advance the terminal fiscal-event chain head: last_hash := current_hash,
     sequence := sequence_number
  7. COMMIT
  8. (post-commit) schedule a debounced sync flush
```

`append()` is the **only** way a fiscal event is authored. Corrections are compensating events (`reference_event_id` set), never mutations `[SoT §3]`.

### 6.2 The device chain head

The fiscal-event chain is **separate from** the receipt chain, on the **same terminal**. The device's `terminal_state` table `[reality §3]` gains fiscal-event chain-head columns: `fiscal_event_genesis_seed`, `fiscal_event_last_hash`, `fiscal_event_sequence` — distinct from the receipt-chain columns. The genesis seed is issued once by the server at terminal provisioning (Appendix B of `[SoT]`); it is the `previous_hash` of the first fiscal event.

### 6.3 Idempotency on the device

`append()` for a source-backed event first checks `(source_event_class, source_event_id)` locally; if a row exists, it returns it (idempotent — a retried emission does not double-chain). The `UNIQUE` constraint is the backstop.

---

## 7. The server-side verify-only mirror

`[SoT §1.1, §11]`. The server **receives, verifies, stores verbatim** — it never authors, never re-serializes, never recomputes-and-replaces.

### 7.1 The typed fiscal-event outbox endpoint + `OutboxIngestor`

A **new** endpoint, `POST /api/pos/sync/fiscal-events`, and a new `OutboxIngestor` class — **distinct from `/pos/receipts/sync`** `[reality §3 — no generic fiscal-event endpoint exists today]`. The transport carries a typed envelope `{ envelope_id, type: 'FISCAL_EVENT', payload_version, payload, idempotency_key }`. The idempotency key for a fiscal event is `(terminal_id, sequence_number)`.

### 7.2 Ingestion (per event, idempotent, standalone — not nested in a business transaction)

```
OutboxIngestor.ingest(envelope):
  1. idempotency: SELECT by (terminal_id, sequence_number).
     If found → return it (idempotent success). No exception-driven control flow.
  2. verify the integrity hash: SHA-256(canonical_bytes) == current_hash ?
        no  → integrity_exception_class = 'canonical_hash_mismatch'  (§8)
  3. verify chain linkage: previous_hash == (prior event's current_hash, same terminal)
     [first event: previous_hash == terminal fiscal_event_genesis_seed]
        gap/break → integrity_exception_class = 'sequence_gap'  (§8)
  4. clock check (§10): rollback/drift → integrity_exception_class = 'time_anomaly'
  5. strict-parse canonical_bytes → structured payload (§7.3)
        parse failure → integrity_exception_class = 'canonical_parse_failure'  (§8)
  6. INSERT the fiscal_events row, server_received_at = now():
        - all checks pass → integrity_status = 'verified'
        - any check failed → integrity_status = 'quarantined', exception class + mandatory reason (§8)
  7. the row is ALWAYS persisted (verified or quarantined) — never rejected, never blocks the device
```

Step 1 is **query-first**, so a constraint violation never aborts a transaction (the round-4 BLOCKER about catching a unique-violation mid-transaction does not apply — ingestion is a standalone operation, query-first, with the `UNIQUE` constraint only as a backstop).

### 7.3 The strict parser

The structured `payload` JSONB is **derived server-side** from the verified `canonical_bytes` by a **strict parser** — rejects duplicate keys, out-of-grammar numbers, invalid Unicode, event-type schema violations `[SoT §5]`. It is never accepted independently from the device. On parse failure: `payload = NULL`, `payload_parse_status = 'failed'`, `integrity_exception_class = 'canonical_parse_failure'`. The canonical bytes remain authoritative regardless.

---

## 8. Per-anomaly-class integrity exceptions + quarantine

`[SoT §7]`. **Accept-and-flag per anomaly class — never block the device.**

### 8.1 Exception classes

| Class | Trigger | Severity | Handling |
|---|---|---|---|
| `canonical_hash_mismatch` | `SHA-256(canonical_bytes) ≠ current_hash` | low | accept, quarantine, annotate; counts, flagged |
| `canonical_parse_failure` | canonical bytes hash OK but fail the strict grammar | low–medium | accept, quarantine, annotate; **no `payload` projection** until operator/developer resolution; raw bytes conserved |
| `time_anomaly` | clock rollback / excessive drift (§10) | low–medium | accept, quarantine, annotate |
| `sequence_gap` | gap or break in the per-terminal sequence | medium | accept the records; the terminal's chain is in recorded incident state; the gap is an **explicit exception total**, not silently folded into clean totals |

`signature_invalid` does **not arise in Phase 1** (no signature provider is active — launch markets accept hash chains). It is reserved for signature-required jurisdictions, handled when those markets are entered `[SoT §7.1]`.

### 8.2 Quarantine

A flagged event is **always persisted** with `integrity_status = 'quarantined'`, `integrity_exception_class`, and a **mandatory structured `integrity_exception_reason`**. An admin alert is raised. An operator resolves it (`integrity_resolved_at` / `integrity_resolved_by`). **Fiscal exports include a quarantine section with reconciliation totals — quarantined records are never silently excluded** `[SoT §7.2]`. Clean totals + quarantine totals + reconciliation = the full set.

---

## 9. Chain-recovery events

`[SoT §7.3]`. When a local chain breaks, the terminal **continues operating** in a recorded `degraded` mode (a hard-stop is more dangerous fiscally). Recovery is **two chained incident events** — not "signed" (no signature provider is active in Phase 1):

- **`CHAIN_BREAK_DETECTED`** — payload: reason, last-good `sequence_number` + `current_hash`, the offending record reference.
- **`CHAIN_RESTART`** — payload: a new genesis reference, the last-good anchor, **operator authorization evidence** (who/when/why), and a provenance link to the prior chain.

Both are ordinary `fiscal_events` rows (chained via the normal mechanism). The broken segment is **never deleted** — it is quarantined (§8), synced, and appears in exports flagged, alongside the two incident events.

---

## 10. Clock / time model

`[SoT §6]`. The device system clock is **untrusted** `[reality §3 — POS uses device `new Date()` for receipt time]`.

- **`event_time_device`** — device-claimed wall-clock at sealing. Untrusted.
- **`sequence_number`** — the monotonic per-terminal chain sequence. **This, not the clock, is the authoritative ordering.**
- **`last_server_time_seen`** — the most recent trusted server time the device observed, stamped into the event; bounds staleness.
- **`server_received_at`** — set by the `OutboxIngestor`; trusted, but only an upper bound on event time.

Rules:
- **Clock-rollback detection:** `event_time_device` moving backward relative to a prior event on the same chain (while `sequence_number` correctly increases) → accepted, flagged `time_anomaly` (§8).
- **Drift detection:** large divergence between `event_time_device` and `last_server_time_seen` / `server_received_at` → flagged.
- **Normative closure-period rule:** an event's `business_date` is assigned by the **terminal-configured fiscal timezone and session boundary** — not by `server_received_at`, not silently by raw device time. A clock anomaly **never moves an event between closure periods** without an explicit, recorded correction event. Closure manifests (§11) reference sequence ranges, not just dates.

---

## 11. Company-level integrity record types

`[SoT §9]`. Per-(tenant, terminal) chains alone cannot prove fleet completeness. Phase 1 defines two **immutable, chained** record types as `fiscal_events` event types (Appendix A) — the *structures* exist in the engine; they are *populated* as terminals and closures come online:

- **`TERMINAL_REGISTRY_SNAPSHOT`** — the authoritative list of every terminal expected for a company at a point in time; carries a hash; links to the prior snapshot.
- **`COMPANY_DAY_CLOSURE_MANIFEST`** — per business day: every expected terminal chain head, explicit missing-terminal / offline exceptions, preparer/approver, the §10 timestamp model, a hash, a link to the prior manifest.

Phase 1 delivers the event types + their payload DTOs + their place in the chain. Active population (the daily manifest job, the registry snapshot job) is downstream — but the engine carries them from day one so it is not a migration later.

---

## 12. Off-device durability controls

`[SoT §8]`. Device authority is not survivable when a terminal is lost/stolen/destroyed before sync without off-device conservation. **An on-device backup encrypted with an on-device key is NOT a conservation control** `[reality §3 — the AES key is a plaintext `.izipos_key` file]`.

Phase 1 delivers:
- **At least one off-device durability path** — an encrypted removable archive with a **separately-held recovery key**, and/or LAN peer replication / NAS, and/or periodic cloud sync when connectivity is available. Key custody is **separate from the terminal disk**.
- The **on-device AES-GCM copy** (existing `.izipos_key` crypto `[reality §3]`) is **crash-recovery only** — explicitly not the conservation answer.
- **Operator-visible unsynced-risk indicator** — unsynced fiscal-event age + count, always visible.
- **Forced archive/export** when offline beyond a configured threshold — produces a portable encrypted archive of the unsynced segment to the off-device path.
- **Maximum-unsynced threshold** — beyond it, escalation (operator warning; configurable harder gates).
- **Device-loss incident register** — device loss/failure is a recordable incident with a declaration/recovery procedure.

**This is a Phase 1 gate before any Phase 2 customer-facing deployment** (roadmap v2; D1).

---

## 13. `Payment.origin` / `Payment.fiscal_event_id`

`[reality §2.3 — `Payment` has neither column today; roadmap v2 places this in Phase 1]`.

- **Migration:** add `payments.origin VARCHAR(32) NULL` and `payments.fiscal_event_id UUID NULL` (FK `fiscal_events(id)`).
- **`PaymentOrigin` enum:** `pos | web_admin | mobile | api | unknown_legacy`.
- **`Payment` model:** add both to `$fillable`; cast `origin` to `PaymentOrigin`.
- **Writer updates — all in the same change** `[reality §2.3 names the writers]`:
  - `ReceiptPaymentService` → `origin = PaymentOrigin::Pos`.
  - `PaymentController::store()` → `origin = PaymentOrigin::WebAdmin`.
  - `PaymentController::storeMultiple()` → `origin = PaymentOrigin::WebAdmin`.
- Any pre-existing rows (preflight gate permitting) → `origin = 'unknown_legacy'`.
- `fiscal_event_id` stays NULL in Phase 1 — Phase 2's `ACCOUNT_PAYMENT` projection is the first writer to set it. Phase 2 *consumes* these columns; it does not introduce them.
- This changes **no** GL behavior, allocation behavior, or payment semantics — purely additive metadata.

---

## 14. The receipt-chain clean rebuild

The existing receipt chain server-recomputes-and-overwrites and throws-and-rolls-back on mismatch — the source of the production chain-breaks. It is **discarded and rebuilt clean** on the §1 pattern. **No migration** (preflight gate, §2). Per the scoping audit and `[SoT §12]` (incl. the `Nf525DataProvider` correction):

| Component | Disposition | Detail |
|---|---|---|
| `receiptService.ts`, `terminal_state`, `offline_receipts` (client authoring/sealing) | **REUSE** | The device already authors and seals correctly offline `[reality §3]`. One addition: store + transmit `canonical_bytes`. |
| `ReceiptSyncService` batch orchestration + stock/voucher/payment logic | **REUSE** | The orchestration is sound; only the hash-mismatch branch changes. |
| `Compliance/FiscalHashService` chain-prefix logic | **REUSE** | Generic; unaffected. |
| `Nf525XmlBuilder` | **REUSE** | DTO→XML serialization of a derived export artifact — permitted under D2. |
| `ReceiptFinalizationService` + `ReceiptHashService::verifyTerminalChain()` / `verifyHash()` | **REWORK** | From recompute-from-structured-models → **re-hash the stored `canonical_bytes`** `[reality §1, §2.1]`. |
| `ReceiptSyncService` hash-mismatch branch | **REWORK** | From **throw + roll back** → **per-class accept/flag** (§8). |
| `Nf525DataProvider` + the JET verification path | **REWORK** | It reads `Receipt` models directly and verifies via `ReceiptHashService::calculateHash()` (server-recompute) `[SoT §12]` — reworked to read verified `canonical_bytes` + quarantine state. |
| Server recompute-and-overwrite path for receipts | **DISCARD** | — |
| `OfflineFiscalHashMismatchException` throw-and-rollback | **DISCARD** | The class may remain as an audit-trail artifact; never thrown to block `[reality §2]`. |
| `canonical_bytes` columns (`pos_receipts` `BYTEA` / `offline_receipts` `TEXT`) | **NEW** | — |
| Client stores + transmits `canonical_bytes` | **NEW** | — |
| Per-class integrity-exception / quarantine path for receipts | **NEW** | Same machinery as §8. |
| JET export sections for quarantine / incidents / manifests | **NEW** | — |

The rebuilt receipt chain makes `SALE_RECEIPT` a **first-class fiscal event** in the new chain — which is why no `SALE_RECEIPT_BRIDGE` is needed (D10).

---

## 15. Migration plan

All migrations additive; the preflight gate (§2) is the hard precondition.

1. **Preflight verification gate** (§2) — first task; blocks all schema-destructive work.
2. `create_fiscal_events_table` — server PostgreSQL (§3.2), indexes, CHECK constraints.
3. `create_fiscal_events_immutability` — trigger (UPDATE/DELETE/TRUNCATE) + `REVOKE TRUNCATE` + break-glass runbook note.
4. Device SQLite migration — `fiscal_events` table (§3.1) + `terminal_state` fiscal-event chain-head columns (§6.2).
5. `add_canonical_bytes_to_pos_receipts` (`BYTEA`) + device `offline_receipts.canonical_bytes` (`TEXT`).
6. `add_origin_and_fiscal_event_id_to_payments` (§13).
7. Company-integrity record-type registration (§11) — no table; event-type + DTO registration.

No fiscal data backfill — the chains are born empty (preflight gate confirms this).

---

## 16. Testing strategy

### 16.1 Device (Vitest)
- `FiscalEventEngine.append()` — sequence increments per terminal; `previous_hash` links to prior `current_hash`; first event links to genesis seed; chain-head columns advance; one SQLite transaction; rollback leaves no row.
- `FiscalEventCanonicalEncoder` + `HashChainIntegrityProvider` — deterministic; tamper → `verify` false.
- Idempotency — re-emitting a source-backed event returns the existing row, no double-chain.
- The cross-language golden-vector fixture set (§4.4) — the TS side reproduces every fixture's canonical string + hash.

### 16.2 Server (PHPUnit, `RefreshDatabase`)
- `OutboxIngestor.ingest()` — query-first idempotency; verified path; each integrity-exception class → quarantine with mandatory reason; the row is always persisted.
- The strict parser — rejects duplicate keys, out-of-grammar numbers, invalid Unicode; `canonical_parse_failure` quarantines with `payload = NULL`.
- Immutability — `UPDATE`, `DELETE`, `TRUNCATE` on `fiscal_events` all raise; `TRUNCATE` also denied to the app role.
- The golden-vector PHP side — `sha256(expected_canonical_string) == expected_sha256_hex` (PHP never serializes).
- `Payment` writer updates — all three writers stamp `origin`; `fiscal_event_id` nullable.

### 16.3 Receipt-chain rebuild
- `ReceiptFinalizationService` / `verifyTerminalChain` re-hash stored `canonical_bytes` (no recompute-from-models).
- `ReceiptSyncService` hash-mismatch → per-class quarantine, **not** throw/rollback; the device is never blocked.
- A receipt sync replay → no duplicate (the `UNIQUE` / `source_event_*` constraints hold).
- `Nf525DataProvider` reads verified `canonical_bytes` + includes quarantine state.

### 16.4 Chain verifier
- `php artisan fiscal:verify-event-chain --terminal=...` walks `fiscal_events`, re-hashes stored `canonical_bytes`, checks linkage; passes on a seeded valid chain, fails with the break point on a tampered fixture. CI gate.

---

## 17. Open items

1. **Preflight gate execution** — must run and obtain written owner sign-off before any schema-destructive work (§2).
2. **Z-report chain** — independent at the protocol level but shares `terminal_state` + `Nf525DataProvider` (roadmap v2 coordination caveat); its rebuild is a separate task that must coordinate migrations/touchpoints with Phase 1.
3. **`fiscal:verify-event-chain` command** — new; mirrors the existing `pos:verify-chain` but walks `fiscal_events` and re-hashes stored bytes rather than recomputing.
4. **Exact `pos_receipts` constraints** — confirm the current `receipt_type` migration + totals CHECK when the rebuild touches `pos_receipts` (reality-doc §6 discrepancies; neither affects a Phase 1 design decision).

---

## Appendix A — Phase 1 `FiscalEventType` reserved values

Implemented in Phase 1: `SALE_RECEIPT` (first-class via the rebuilt chain), `CHAIN_BREAK_DETECTED`, `CHAIN_RESTART`, `TERMINAL_REGISTRY_SNAPSHOT`, `COMPANY_DAY_CLOSURE_MANIFEST`.

Reserved (CHECK-listed; handlers added in their phase): `ACCOUNT_PAYMENT`, `ACCOUNT_CHARGE`, `ACCOUNT_REFUND`, `ACCOUNT_PAYMENT_RECONCILED`, `ACCOUNT_CREDIT_ISSUE`, `ACCOUNT_CREDIT_USAGE`, `DEPOSIT_RECEIPT`, `IDENTITY_ALIAS_RECONCILED`, `SALE_VOID`, `SALE_CORRECTION`, `REFUND_RECEIPT`, `PARTIAL_REFUND`, `RETURN_WITHOUT_RECEIPT`, `OPENING_FLOAT`, `CASH_IN`, `CASH_OUT`, `SAFE_DROP`, `CASH_CORRECTION`, `SESSION_OPEN`, `SESSION_CLOSE`, `X_REPORT`, `Z_REPORT`, `REPRINT_COPY`.

`append()` throws `FiscalEventTypeNotImplemented` for a reserved-but-unimplemented type.

---

**End of Phase 1 spec.**
