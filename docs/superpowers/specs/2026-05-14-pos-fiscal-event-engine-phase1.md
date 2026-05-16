# POS Fiscal Event Engine — Phase 1 Design Spec

**Date:** 2026-05-14
**Phase:** 1 of 5 (see roadmap)
**Status:** Drafted; awaiting self-review → owner review → Codex round 4.
**Roadmap:** `apps/erp/docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap.md`
**Codebase reality reference:** `apps/erp/docs/superpowers/research/2026-05-14-pos-fiscal-codebase-reality.md` — **every codebase claim in this spec is grounded there; citations use the form `[reality §N]`.**
**Predecessors:** v1, v1.1, v2.0 specs (archived); Codex reviews rounds 1-3.

---

## 1. Overview

### 1.1 What Phase 1 is

Phase 1 builds the **server-side Fiscal Event Engine** — the append-only, hash-chained fiscal ledger that every later phase emits into. It is pure foundation: no customer-facing feature, no UI.

Phase 1 is split into two increments:

- **Phase 1 Core** — the engine itself: the `fiscal_events` table, the `FiscalEventEngine` service, the `FiscalEventV1SignatureProvider`, the canonical-serialization contract with cross-language golden vectors, the typed payload DTO layer, the immutability triggers, the source-of-truth declaration, the new `pos_terminals` chain columns, the `Payment.origin` / `Payment.fiscal_event_id` columns + writer updates, and the chain verifier command. **This is the deliverable.** It is validated by unit tests, golden vectors, and synthetic-event integration tests — it needs no real producer to be complete and correct.
- **Phase 1 Bridge** *(separable; time-permitting)* — the `SALE_RECEIPT_BRIDGE` event type and its integration into `ReceiptFinalizationService`, giving the engine its first real producer. This is the increment that touches the live receipt-finalization transaction (round-3 P1.1 flagged this as the delicate part). If Phase 1 Core lands fast, the Bridge ships with it; if not, the Bridge folds into Phase 2 without blocking Core.

### 1.2 What Phase 1 is NOT

- **No client-side fiscal infrastructure.** No `fiscal_events_local` SQLite table, no outbox endpoint, no `OutboxIngestor`. Phase 1 has no client-emitted fiscal events — `SALE_RECEIPT_BRIDGE` is emitted **server-side** by `ReceiptFinalizationService`, both on online finalization and during `/pos/receipts/sync` (which runs `finalize()` server-side [reality §1.1, §3.2]). The outbox transport is built in Phase 2 against its first real client producer (`ACCOUNT_PAYMENT`).
- **No customer-facing events.** `ACCOUNT_PAYMENT`, `ACCOUNT_CHARGE`, `ACCOUNT_REFUND`, `DEPOSIT_RECEIPT`, `ACCOUNT_STATUS_CHANGED`, `OVERRIDE_AUDITED`, `CASH_OUT_EXECUTED` — all Phase 2-5. Their names are **reserved** in the `FiscalEventType` enum so later phases add handlers, not migrations.
- **No charge-to-account, no AR posting, no GL changes.** The desktop POS has no charge-to-account today [reality §2.2, confirmed by owner]; that is Phase 3.
- **No projection layer beyond a stub.** Printable `FiscalDocument` projection is Phase 2+.
- **No Spatie `stored_events` or `audit_events` changes.** Those remain exactly as they are [reality §4].

### 1.3 Grounding

Three Codex review rounds BLOCKed earlier specs partly because they made unverified codebase claims. Phase 1 is written against the codebase reality reference. The relevant verified facts:

- The existing receipt "V3" is a receipt-specific canonical-JSON hash with `previous_hash` embedded in the JSON; the new fiscal-event signature provider is a **different protocol** and is named distinctly [reality §1.4, §7.2].
- All existing fiscal hashes are 64-char hex strings (`CHAR(64)`) [reality §1.3]. Phase 1 uses the same representation throughout — no `BYTEA`, no mixing.
- `CanonicalJsonEncoder` supports `null`/`bool`/`int`/`string`/`array`, throws on floats, and has a documented PHP-vs-JS U+2028/U+2029 divergence [reality §1.4].
- Existing fiscal stack formats money as decimal strings via `CurrencyScale::bcformat()` [reality §1.4]; project memory `project_monetary_precision.md` mandates `bcformat`. Phase 1 follows the same convention — no parallel integer-cents representation inside the fiscal layer.
- `audit_events` is **not a chain** (per-event `event_hash`, no `previous_hash`); `getHashableData()` has **zero callers** [reality §4]. The Fiscal Event Engine is genuinely new — it does not upgrade either existing store.
- The `pos_receipts` immutability trigger covers `UPDATE`/`DELETE` only, not `TRUNCATE` [reality §1.5]. Phase 1's trigger covers all three.
- `Payment` has no `origin` / `fiscal_event_id` columns [reality §2.3].
- `pos_terminals` has `genesis_seed`, `last_hash`, `current_sequence` for the **receipt** chain [reality §1.3]. The fiscal-event chain needs its **own** head-tracking columns.
- `ReceiptFinalizationService::finalize()` runs inside `DB::transaction()` and locks the Terminal row with `lockForUpdate()` before hash computation [reality §1.1].

### 1.4 Round-3 findings addressed in Phase 1

| Finding | Increment | How |
|---|---|---|
| B2 — signature provider naming collision | Core | `FiscalEventV1SignatureProvider`, `signature_version = 'fiscal-event-v1'`, distinct protocol (§6) |
| P1.1 — bridge append vs transaction/lock boundaries | Bridge | `append()` takes the already-locked Terminal; called inside `ReceiptFinalizationService`'s existing transaction (§10) |
| P1.2 — immutability trigger omits TRUNCATE | Core | Trigger covers `UPDATE`/`DELETE`/`TRUNCATE`; `TRUNCATE` revoked from app role (§8) |
| P1.6 — dual event stores, no bridging contract | Core | Source-of-truth declaration; synchronous-only emission; no-duplicate constraint (§9) |
| P1.7 — `Payment.origin` / `fiscal_event_id` not in write model | Core | New columns + `PaymentOrigin` enum + all writers updated in the same PR (§11) |
| P2.1 — JSONB payloads lack typed DTOs | Core | `FiscalEventPayload` interface + per-type readonly DTOs + PHP enums (§5) |
| P2.2 — canonical JSON exceeds the encoder's value set | Core | Canonical value grammar; `FiscalEventCanonicalEncoder`; cross-language golden vectors (§7, App. B) |
| P3.1 — `ACCOUNT_PAYMENT` misnamed for credit-line draw | Core | `FiscalEventType` enum reserves `ACCOUNT_PAYMENT` (money received) and `ACCOUNT_CHARGE` (AR created at sale) as distinct values (§4) |

P2.3 (`OutboxIngestor`) moves to Phase 2. Round-3 BLOCKERs 1, 3, 4 and P1.3/P1.4/P1.5/P1.8/P2.4/P2.5/P2.6 are all Phase 2-5 scope per the roadmap.

---

## 2. The `fiscal_events` table

Server-side, PostgreSQL. The canonical, append-only fiscal ledger.

```
fiscal_events {
  id                        UUID PK
  tenant_id                 UUID NOT NULL
  company_id                UUID NOT NULL
  terminal_id               UUID NOT NULL          -- FK pos_terminals; chain partition
  operator_id               UUID NOT NULL          -- the user the event is attributed to

  event_type                VARCHAR(64) NOT NULL   -- FiscalEventType enum value
  event_version             SMALLINT  NOT NULL DEFAULT 1   -- payload schema version for this event_type
  signature_version         VARCHAR(32) NOT NULL   -- e.g. 'fiscal-event-v1'

  sequence_number           BIGINT    NOT NULL     -- per (tenant_id, terminal_id), monotonic, continuous across years
  event_timestamp           TIMESTAMPTZ NOT NULL   -- canonical event time, UTC
  business_date             DATE      NOT NULL     -- fiscal day attribution
  origin_emitted_at         TIMESTAMPTZ NOT NULL   -- when the originating device emitted it (= event_timestamp for server-emitted)
  server_received_at        TIMESTAMPTZ NOT NULL   -- when the server persisted it (= event_timestamp for server-emitted)

  reference_event_id        UUID NULL              -- compensating events (Phase 2+)
  reference_document_id     UUID NULL              -- cross-link to pos_receipts.id etc.
  source_event_class        VARCHAR(255) NULL      -- for idempotent bridge emission
  source_event_id           UUID NULL              -- for idempotent bridge emission

  partner_id                UUID NULL              -- live FK (Phase 2+)
  partner_identity_snapshot JSONB NULL             -- immutable identity snapshot (Phase 2+)

  payload                   JSONB NOT NULL         -- typed per event_type (see §5)

  previous_hash             CHAR(64) NOT NULL      -- hex; terminal.fiscal_event_genesis_seed for the first event
  current_hash              CHAR(64) NOT NULL      -- hex; this event's hash

  created_at                TIMESTAMPTZ NOT NULL DEFAULT NOW()
}
```

**Indexes & constraints:**

```
UNIQUE (tenant_id, terminal_id, sequence_number)            -- chain integrity + monotonicity
UNIQUE (source_event_class, source_event_id)
       WHERE source_event_id IS NOT NULL                    -- no-duplicate bridge emission (§9.3)
INDEX  (reference_event_id)      WHERE reference_event_id IS NOT NULL
INDEX  (reference_document_id)   WHERE reference_document_id IS NOT NULL
INDEX  (tenant_id, partner_id)   WHERE partner_id IS NOT NULL

CHECK (sequence_number > 0)
CHECK (current_hash  ~ '^[0-9a-f]{64}$')
CHECK (previous_hash ~ '^[0-9a-f]{64}$')
CHECK (event_type IN ( ...FiscalEventType reserved values, see §4... ))
```

**Notes:**
- `previous_hash` is **never NULL**. The first event on a terminal uses `terminal.fiscal_event_genesis_seed` (§3) as its `previous_hash`. This is simpler than a nullable-genesis convention and matches the existing receipt chain's use of `genesis_seed` [reality §1.3].
- Hash representation is **lowercase hex, `CHAR(64)`**, everywhere — consistent with the existing receipt chain [reality §1.3, §7 conclusion 2]. No `BYTEA`.
- `sequence_number` is **continuous across fiscal years** — it does not reset. The fiscal year is recoverable from `business_date`. This is a simpler invariant than the receipt chain's year-scoped sequence and avoids year-boundary edge cases.
- For Phase 1 server-emitted events, `event_timestamp == origin_emitted_at == server_received_at`. The three columns exist now so Phase 4's cross-chain ordering work (round-3 P1.5) needs no migration.
- `partner_id` / `partner_identity_snapshot` are present in the schema but unused in Phase 1 (no event type populates them yet).

---

## 3. `pos_terminals` chain columns

The fiscal-event chain is a **separate chain** from the existing receipt chain, on the **same terminal**. It needs its own head-tracking columns. Per round-3 P1.1: "Add separate columns for receipt chain and fiscal-event chain."

New columns on `pos_terminals`:

```
fiscal_event_genesis_seed  CHAR(64)  NOT NULL   -- random 256-bit hex; generated at terminal provisioning
fiscal_event_last_hash     CHAR(64)  NULL       -- head of the fiscal-event chain; NULL until first event
fiscal_event_sequence      BIGINT    NOT NULL DEFAULT 0   -- last assigned fiscal-event sequence_number
```

- `fiscal_event_genesis_seed` is **distinct from** the existing receipt-chain `genesis_seed` — generated independently so the two chains share no bootstrap value. The migration generates a fresh random seed for every existing terminal; the `Terminal` model generates one on creation.
- `fiscal_event_last_hash` / `fiscal_event_sequence` are updated by `FiscalEventEngine::append()` (§7), under the terminal row lock, in the same transaction as the `fiscal_events` insert.

---

## 4. `FiscalEventType` enum

PHP enum, the validation authority for `event_type`. The DB `CHECK` constraint (§2) mirrors it.

**Phase 1 — implemented (has a registered handler + DTO):**
- `SALE_RECEIPT_BRIDGE` — bridge attestation for a finalized POS receipt (Phase 1 Bridge increment).

**Reserved — name fixed now, handler added in its phase:**
- `ACCOUNT_PAYMENT` — money **received** from a customer toward an existing balance. *(Phase 2.)*
- `ACCOUNT_CHARGE` — accounts-receivable **created** at the point of sale (customer leaves owing). Distinct from `ACCOUNT_PAYMENT` — opposite balance direction. *(Phase 3. Resolves round-3 P3.1.)*
- `ACCOUNT_REFUND` — refund of a prior `ACCOUNT_PAYMENT`. *(Phase 2/4.)*
- `ACCOUNT_PAYMENT_RECONCILED` — server reconciliation note when offline allocation differs from authoritative. *(Phase 2.)*
- `ACCOUNT_CREDIT_ISSUE`, `ACCOUNT_CREDIT_USAGE` — store credit / wallet. *(Phase 5.)*
- `DEPOSIT_RECEIPT` — deposit toward a specific future sale (the **offline-first B2C** deposit — distinct from the B2B online deposit flow; see roadmap §2.1). *(Phase 5.)*
- `ACCOUNT_STATUS_CHANGED` — customer account status transition. *(Phase 4.)*
- `OVERRIDE_AUDITED` — override-decision record. *(Phase 4.)*
- `CASH_OUT_EXECUTED` — cash leaving the till. *(Phase 4.)*
- `IDENTITY_ALIAS_RECONCILED` — temp→canonical customer merge. *(Phase 2/5.)*

`FiscalEventEngine::append()` rejects any `event_type` without a registered handler with an explicit `FiscalEventTypeNotImplementedException` — so reserved-but-unimplemented types fail loudly, not silently.

Genuinely-new event types not foreseen here will need a migration to extend the `CHECK` constraint + the enum. That is deliberate and rare.

---

## 5. Typed payload DTOs

Per round-3 P2.1 and CLAUDE.md ("JSONB columns must have a corresponding PHP DTO"; "Enums for all status/type columns").

```
interface FiscalEventPayload {
    // Returns the payload as a canonical-ready associative array:
    // no floats, decimal-string money, sorted arrays — see §7.
    public function toCanonicalArray(): array;
}
```

- One **readonly** DTO per implemented event type. Phase 1 implements one: `SaleReceiptBridgePayload` (§10.2).
- DTOs use PHP enums for any status/type field inside the payload.
- A `FiscalEventPayloadRegistry` maps `FiscalEventType` → DTO class + `event_version`. `FiscalEventEngine::append()` resolves the DTO via the registry; an unregistered type throws `FiscalEventTypeNotImplementedException`.
- `php artisan typescript:transform` regenerates the TypeScript types after any DTO change; generated files are committed [reality: CLAUDE.md type-generation rule].

---

## 6. `FiscalEventV1SignatureProvider`

The new signature protocol. **Deliberately distinct** from the existing receipt `V3ReceiptHashComputer` — different name, different field set, an explicit `signature_version` discriminator inside the hashed object so the two can never be confused or cross-verified [round-3 B2; reality §1.4, §7 conclusion 2].

```
interface FiscalSignatureProvider {
    public function version(): string;                       // e.g. 'fiscal-event-v1'
    public function canonicalString(FiscalEvent $event): string;   // §7
    public function computeHash(FiscalEvent $event): string;       // 64-char lowercase hex
    public function verify(FiscalEvent $event): bool;
}

final class FiscalEventV1SignatureProvider implements FiscalSignatureProvider
```

**Protocol (`fiscal-event-v1`):**

1. Build the **canonical object** — a JSON object with these keys (encoded with lexicographically-sorted keys per §7):
   - `business_date` — `YYYY-MM-DD`
   - `company_id` — UUID string
   - `event_timestamp` — UTC ISO-8601, second precision, `Z` suffix: `2026-05-14T13:22:05Z`
   - `event_type` — enum value string
   - `event_version` — integer
   - `operator_id` — UUID string
   - `payload` — the event's `toCanonicalArray()` output (§7 grammar)
   - `previous_hash` — 64-char hex string
   - `reference_document_id` — UUID string or `null`
   - `reference_event_id` — UUID string or `null`
   - `sequence_number` — integer
   - `signature_version` — the literal string `"fiscal-event-v1"`
   - `tenant_id` — UUID string
   - `terminal_id` — UUID string
2. `canonicalString` = the canonical JSON encoding of that object (§7).
3. `computeHash` = `lowercase_hex( SHA-256( utf8_bytes( canonicalString ) ) )`.
4. `verify` = recompute `computeHash` and compare to the row's `current_hash`.

**Why `previous_hash` is inside the canonical object** rather than concatenated separately: it matches the existing receipt-V3 pattern [reality §1.4 — "previous_hash is embedded IN the canonical JSON object as a hex string"], so PHP and the TS client share one mental model and one encoder path. There is exactly one hash representation in the whole protocol: 64-char lowercase hex.

**Why it cannot be confused with receipt V3:** the receipt-V3 canonical object has receipt-only top-level keys (`receipt_number`, `posted_at`, `schema_version`, `voucher_ledger_hash`, …) [reality §1.4]; the fiscal-event-v1 object has `event_type`, `sequence_number`, `signature_version: "fiscal-event-v1"`, etc. The two object shapes are disjoint, and the `signature_version` discriminator is itself hashed.

Future country-specific providers (`Nf525LneSignatureProvider`, `ZatcaSignatureProvider`, …) implement the same interface; a terminal's active provider is selected by a `fiscal_signature_version` setting (Phase 4+ introduces per-terminal selection; Phase 1 hard-uses `fiscal-event-v1`).

---

## 7. Canonical serialization contract

Per round-3 P2.2. The byte-exact contract — see Appendix B for the full grammar and golden-vector format.

### 7.1 Value grammar

The `payload` of every fiscal event, and the canonical object of §6, obey:

- **Numbers:** integers only. **No floats — ever.** The `CanonicalJsonEncoder` already throws on floats [reality §1.4]; this grammar guarantees it never sees one.
- **Money:** decimal **strings**, formatted via `CurrencyScale::bcformat($value, CurrencyScale::for($currency))` — the existing fiscal-stack convention [reality §1.4] and project-memory discipline (`project_monetary_precision.md`). E.g. `"123.450"` for a TND amount. Never a float, never an unformatted decimal.
- **Timestamps:** UTC, ISO-8601, **second precision**, `Z` suffix: `2026-05-14T13:22:05Z`. No sub-second component, no numeric offset. (The producer converts from whatever local representation to this normalized form before serialization.)
- **Strings:** UTF-8, **NFC-normalized**. **U+2028 and U+2029 are stripped at the producer** — they have no legitimate use in fiscal free-text and are the documented source of the PHP-vs-JS encoder divergence [reality §1.4]. Stripping at the producer closes the divergence at its root.
- **Arrays:** where element order is not semantically meaningful, the array is **sorted by a defined key** before serialization (e.g. VAT breakdown sorted ascending by rate; payment breakdown sorted by method code). Each DTO documents its sort keys.
- **Object keys:** sorted **lexicographically** (RFC 8785 / JCS).
- **Booleans / null:** literal `true` / `false` / `null`.

### 7.2 Encoder

`FiscalEventCanonicalEncoder` — a thin wrapper that:
1. Applies the §7.1 string normalization pass (NFC + U+2028/U+2029 strip) to every string value in the structure.
2. Delegates the structural encoding to the existing `CanonicalJsonEncoder` [reality §1.4] — reused, not forked, since our grammar (no floats) is within its supported value set.

### 7.3 Cross-language golden vectors

A committed fixture set under `apps/api/tests/Fixtures/fiscal-event-golden-vectors/`:

- Each fixture: `{ description, payload_dto_input, expected_canonical_string, expected_sha256_hex }`.
- A **PHP test** asserts `FiscalEventCanonicalEncoder` + `FiscalEventV1SignatureProvider` reproduce `expected_canonical_string` and `expected_sha256_hex`.
- A **TypeScript test** (in `apps/pos`) asserts the client-side encoder reproduces the *same* `expected_canonical_string` and `expected_sha256_hex` byte-for-byte.
- Both run in CI. A divergence fails the build. This is the gate that prevents the offline client and the server from disagreeing on a hash — the failure mode round-3 P2.2 warned about.

(The TS client-side encoder is built in Phase 1 Core even though Phase 1 has no client-emitted events — it's needed *only* for the golden-vector parity test, so Phase 2's offline `ACCOUNT_PAYMENT` inherits a proven cross-language encoder.)

---

## 8. Immutability

Per round-3 P1.2. The existing `pos_receipts` trigger covers `UPDATE`/`DELETE` only [reality §1.5]; `fiscal_events` must also block `TRUNCATE` and schema-level bypasses.

- **Trigger `fiscal_events_immutability`:**
  - `BEFORE UPDATE OR DELETE ON fiscal_events FOR EACH ROW` → `RAISE EXCEPTION`.
  - `BEFORE TRUNCATE ON fiscal_events FOR EACH STATEMENT` → `RAISE EXCEPTION`.
- **`REVOKE TRUNCATE ON fiscal_events FROM <application_role>`** — the app DB role cannot truncate the table even if the trigger were somehow dropped.
- **Break-glass procedure** (documented in the migration + an ops runbook): any DBA-level maintenance that must touch `fiscal_events` (partition operations, recovery) requires a **signed full export first**, performed by a DBA role, logged. This is procedural, not enforced in code — but it is documented so it is not improvised.
- Compensation, not mutation: corrections happen only by appending a new event with `reference_event_id` set. Phase 1's single event type (`SALE_RECEIPT_BRIDGE`) has no compensation; the mechanism is exercised from Phase 2.

---

## 9. Source-of-truth declaration

Per round-3 P1.6. There are now potentially three event-ish stores; this section is the normative contract.

### 9.1 The three stores

| Store | Role | Changed by Phase 1? |
|---|---|---|
| `fiscal_events` | **Source of truth for in-scope fiscal facts.** Append-only, hash-chained. | New table |
| `stored_events` (Spatie) | Domain-event store for aggregate replay. Not fiscal, not chained. [reality §4.1] | No |
| `audit_events` | Operational audit log. Per-event hash, **not a chain**. [reality §4.2] | No |

`fiscal_events` does **not** replace or upgrade either of the others. Spatie events and audit events continue exactly as they are. A fact being in `stored_events` or `audit_events` does not make it a fiscal fact; only an entry in `fiscal_events` does.

### 9.2 Emission contract

The **only** way a row enters `fiscal_events` is `FiscalEventEngine::append()`, called **synchronously by a command service inside that command's own DB transaction**. Specifically:

- **Never** via the after-commit `DomainEventSubscriber` path — that path dispatches after commit and swallows persistence failures [reality §4.2], which would let a fiscal fact silently fail to chain (the original round-1 B1 problem).
- **Never** asynchronously, never from a queue listener, never best-effort.
- If `append()` throws, the caller's transaction rolls back — the business operation and its fiscal event succeed or fail together.

In Phase 1, the only command service that calls `append()` is `ReceiptFinalizationService` (Phase 1 Bridge, §10). In Phase 1 Core with no Bridge, `append()` is exercised only by tests.

### 9.3 No-duplicate bridge contract

When a fiscal event is emitted as a derivative of another persisted record (the bridge case), it carries `source_event_class` + `source_event_id`. The `UNIQUE (source_event_class, source_event_id) WHERE source_event_id IS NOT NULL` constraint (§2) guarantees at most one fiscal event per source record. `FiscalEventEngine::append()` catches the unique-violation and treats it as **idempotent success** (returns the existing event) — so a retried receipt-sync replay cannot double-chain.

---

## 10. `FiscalEventEngine` service

### 10.1 Interface

```
final class FiscalEventEngine {
    /**
     * Append one event to a terminal's chain.
     * MUST be called inside a caller-managed DB transaction.
     * The caller MUST pass the terminal already loaded under a row lock
     * (lockForUpdate) — append() does not open a transaction and does not
     * lock; it relies on the caller's lock. This makes lock ordering explicit
     * and testable (round-3 P1.1).
     */
    public function append(
        FiscalEventEmissionRequest $request,
        Terminal $lockedTerminal,
        FiscalActorContext $actor,
    ): FiscalEvent;

    /**
     * Walk a terminal's chain and verify every hash + linkage.
     */
    public function verifyChain(
        string $tenantId,
        string $terminalId,
        ?int $fromSequence = null,
    ): ChainVerificationResult;
}
```

`compensate()` and `project()` are deliberately **not** in the Phase 1 interface — they are added in Phase 2 when there is a real compensable event and a real printable projection. Phase 1 keeps the surface minimal.

For standalone / test callers that have not pre-locked the terminal, a thin `FiscalEventEngine::appendStandalone()` helper opens its own transaction and `lockForUpdate()`s the terminal, then calls `append()`. Production callers use `append()` with an already-locked terminal.

### 10.2 `append()` behavior

Inside the caller's transaction, with `$lockedTerminal` already locked:

1. Resolve the payload DTO + `event_version` via `FiscalEventPayloadRegistry`; throw `FiscalEventTypeNotImplementedException` if the type has no registered handler.
2. `sequence_number = $lockedTerminal->fiscal_event_sequence + 1`.
3. `previous_hash = $lockedTerminal->fiscal_event_last_hash ?? $lockedTerminal->fiscal_event_genesis_seed`.
4. Build the `FiscalEvent` row in memory: ids, `event_type`, `event_version`, `signature_version = 'fiscal-event-v1'`, `sequence_number`, timestamps, references, `payload` (from `DTO->toCanonicalArray()`), `previous_hash`.
5. `current_hash = FiscalEventV1SignatureProvider->computeHash($event)`.
6. Insert the `fiscal_events` row. If a `UNIQUE (source_event_class, source_event_id)` violation occurs → idempotent success: return the existing event (§9.3).
7. Update `$lockedTerminal->fiscal_event_last_hash = current_hash`, `$lockedTerminal->fiscal_event_sequence = sequence_number`; save the terminal.
8. Return the persisted `FiscalEvent`.

No `DB::transaction()` of its own; no `lockForUpdate()` of its own. Both are the caller's responsibility — which is what makes the Bridge integration (§10.3) safe and testable.

### 10.3 Phase 1 Bridge — `SALE_RECEIPT_BRIDGE` integration *(separable increment)*

`ReceiptFinalizationService::finalize()` today: `DB::transaction()` opens [reality §1.1 :54] → `Terminal::lockForUpdate()` [:56] → compute receipt hash → save receipt with `previous_hash`/`chain_sequence` [:78] → update `terminal.last_hash`/`current_sequence` [:80-81] → `DB::afterCommit()` dispatch `ReceiptCreated` [:88].

**Bridge change:** between `:81` (terminal receipt-chain updated) and the close of the transaction, add:

```
$bridgeEvent = $this->fiscalEventEngine->append(
    new FiscalEventEmissionRequest(
        eventType: FiscalEventType::SALE_RECEIPT_BRIDGE,
        payload: SaleReceiptBridgePayload::fromReceipt($receipt),
        referenceDocumentId: $receipt->id,
        sourceEventClass: Receipt::class,
        sourceEventId: $receipt->id,
        eventTimestamp: $receipt->posted_at_utc(),
        businessDate: $receipt->business_date,
    ),
    lockedTerminal: $terminal,   // the SAME row already locked at :56
    actor: FiscalActorContext::fromOperator($receipt->operator_id),
);
$receipt->fiscal_event_id = $bridgeEvent->id;   // optional cross-link on the receipt row
```

- It runs on the **same already-locked Terminal row** — no second lock, no lock-ordering hazard. The receipt chain columns (`last_hash`, `current_sequence`) and the fiscal-event chain columns (`fiscal_event_last_hash`, `fiscal_event_sequence`) are distinct (§3), so the two chains advance independently on one locked row.
- It runs inside `finalize()`'s existing `DB::transaction()` — if `append()` throws, the receipt, the terminal updates, and the bridge event all roll back together. No partial state.
- This applies both to online finalization and to finalization during `/pos/receipts/sync` (which calls `finalize()` server-side, nested in `ReceiptSyncService`'s transaction [reality §1.1, §3.2]). A sync replay of an already-finalized receipt hits the no-duplicate constraint (§9.3) → idempotent.

**`SaleReceiptBridgePayload`** (`toCanonicalArray()` per §7):
```
{
  "currency": "TND",
  "payment_breakdown": [ {"method_code": "...", "amount": "..."} , ... ],   // sorted by method_code
  "pos_receipt_fiscal_hash": "<the receipt's own V2/V3 hash>",
  "pos_receipt_id": "<uuid>",
  "pos_receipt_number": "<MAIN-POS01-2026-00000123>",
  "totals": { "gross": "...", "net": "...", "vat": "..." },                 // bcformat decimal strings
  "vat_breakdown": [ {"rate": "...", "base": "...", "amount": "..."} , ... ] // sorted by rate
}
```

The payload **independently binds the receipt's financial content** — totals, VAT breakdown, payment breakdown — not just the receipt's hash. So the bridge event is a real attestation of the receipt's content, not a soft pointer: tampering with the receipt's financials would be detectable by comparing against the immutable bridge payload. `partner_id` is copied onto the `fiscal_events` row when present on the receipt; `partner_identity_snapshot` stays NULL in Phase 1 (Phase 2 concern).

---

## 11. `Payment.origin` + `Payment.fiscal_event_id`

Per round-3 P1.7. Added in Phase 1 Core even though Phase 1 does not otherwise touch payments — so Phase 2 (which links `ACCOUNT_PAYMENT` projections to `Payment` rows) needs no migration and there is never a half-migrated state with null origins.

- **Migration:** add to `payments`:
  - `origin VARCHAR(32) NULL` — `PaymentOrigin` enum value.
  - `fiscal_event_id UUID NULL` — FK `fiscal_events(id)`.
- **`PaymentOrigin` PHP enum:** `pos`, `web_admin`, `mobile`, `api`, `unknown_legacy`.
- **`Payment` model:** add both to `$fillable`; cast `origin` to `PaymentOrigin`.
- **Writer updates — all in the same PR** [reality §2.3 identifies the writers]:
  - `ReceiptPaymentService` (the POS receipt-payment path) → sets `origin = PaymentOrigin::Pos`.
  - `PaymentController::store()` → sets `origin = PaymentOrigin::WebAdmin`.
  - `PaymentController::storeMultiple()` → sets `origin = PaymentOrigin::WebAdmin`.
- **Existing rows:** there is no production fiscal data (owner confirmed first tenants are new deployments). The migration sets any pre-existing `payments` rows to `origin = 'unknown_legacy'`. No fiscal backfill — `fiscal_events` is empty until the first event is emitted.
- **`fiscal_event_id` stays NULL in Phase 1** — no payment is yet derived from a fiscal event. Phase 2's `ACCOUNT_PAYMENT` projection is the first writer to set it.

This does **not** change any GL behavior, any allocation behavior, or any payment semantics. It is purely additive metadata plumbing.

---

## 12. Chain verifier command

```
php artisan fiscal:verify-event-chain {--tenant=} {--terminal=} {--from-sequence=}
```

- Walks `fiscal_events` for the given terminal in `sequence_number` order.
- For each event: recompute `current_hash` via `FiscalEventV1SignatureProvider` (selected by the row's `signature_version`); assert it matches the stored `current_hash`.
- Assert `previous_hash` of event N equals `current_hash` of event N-1 (and the first event's `previous_hash` equals the terminal's `fiscal_event_genesis_seed`).
- Reports the first break (sequence number, expected vs actual) or "chain verified, N events".
- Permission-gated by `fiscal.events.verify_chain`.
- Run in CI against seeded fixtures (a valid chain passes; a deliberately-tampered fixture fails) — see §13.

---

## 13. Testing strategy

### 13.1 Unit (PHPUnit, real DB via `RefreshDatabase`)

- `FiscalEventEngine::append()` — synthetic events: `sequence_number` increments per terminal; `previous_hash` links to prior `current_hash`; first event links to `fiscal_event_genesis_seed`; terminal chain columns update.
- `FiscalEventV1SignatureProvider` — `canonicalString` + `computeHash` deterministic; `verify` true for an untampered event; mutate any field → `verify` false.
- `FiscalEventTypeNotImplementedException` thrown for a reserved-but-unimplemented event type.
- Concurrency: two `append()` calls racing on one terminal serialize correctly through the caller's `lockForUpdate()` — no duplicate `sequence_number` (the `UNIQUE` constraint is the backstop).
- Rollback: a transaction that calls `append()` then throws → no `fiscal_events` row, terminal chain columns unchanged.

### 13.2 Golden vectors (cross-language)

- The committed fixture set (§7.3): PHP test and TS test both reproduce `expected_canonical_string` + `expected_sha256_hex` byte-for-byte. CI gate. Any divergence fails the build.

### 13.3 Immutability

- Migration test: `UPDATE`, `DELETE`, and `TRUNCATE` on `fiscal_events` each raise. `TRUNCATE` is also denied to the app role independent of the trigger.

### 13.4 Bridge increment (if shipped in Phase 1)

- `ReceiptFinalizationService::finalize()` emits exactly one `SALE_RECEIPT_BRIDGE` per finalized receipt; `reference_document_id` + `source_event_id` point to the receipt.
- Finalization failure after the bridge append → receipt, terminal columns, and bridge event all roll back (one transaction).
- Sync replay of an already-finalized receipt → no second bridge event (no-duplicate constraint); `append()` returns the existing event.
- `SaleReceiptBridgePayload` binds the receipt totals/VAT/payments; a test that mutates a receipt total and re-derives the payload shows a mismatch against the immutable bridge payload.

### 13.5 Verifier

- `fiscal:verify-event-chain` passes on a seeded valid chain; fails with the correct break point on a seeded tampered fixture. CI runs both.

---

## 14. Migration plan

All migrations are **additive**. No fiscal backfill (the table is born empty).

1. `create_fiscal_events_table` — the table (§2), indexes, CHECK constraints.
2. `create_fiscal_events_immutability_trigger` — the trigger (§8) + `REVOKE TRUNCATE` + the break-glass note.
3. `add_fiscal_event_chain_columns_to_pos_terminals` — `fiscal_event_genesis_seed` (generate random for existing rows), `fiscal_event_last_hash`, `fiscal_event_sequence` (§3).
4. `add_origin_and_fiscal_event_id_to_payments` — the two columns; set existing rows' `origin = 'unknown_legacy'` (§11).
5. *(Bridge increment)* `add_fiscal_event_id_to_pos_receipts` — optional cross-link column on `pos_receipts`.

Discrepancies §6.1/§6.2 of the reality doc (`pos_receipts.receipt_type` migration, totals CHECK formula) do **not** affect Phase 1 — Phase 1 does not alter `pos_receipts` except the optional `fiscal_event_id` cross-link in the Bridge increment.

---

## 15. Out of scope (named follow-ups)

- **Phase 2:** customer management + `ACCOUNT_PAYMENT` (offline-first) + the client-side outbox (`fiscal_events_local`, `OutboxIngestor`, sync route — round-3 P2.3) + `compensate()` + the printable projection layer + `NationalIdentityValidationService` (round-3 P1.8) + `PaymentAllocationService` command-DTO refactor + identity-map basics (round-3 P2.6).
- **Phase 3:** charge-to-account — settlement-vs-payment-line split, composite AR GL posting (round-3 B1), B2B `Facture` routing (round-3 B4), rules engine.
- **Phase 4:** account status workflow, `OVERRIDE_AUDITED` / `CASH_OUT_EXECUTED`, approval primitive + PIN crypto (round-3 P2.4), virtual admin terminal (round-3 P1.4), semantic ingestion validation (round-3 P1.3), cross-chain ordering columns (round-3 P1.5), `cash_out_policy` table.
- **Phase 5:** deposits (`DEPOSIT_RECEIPT`), full identity reconciliation, AML state machine, store credit.
- **Deferred:** full `SALE_RECEIPT` migration into `fiscal_events`; cash-drawer + session events; B2B document events; NF525 cert; country-specific signature providers; the `offline_authoritative` reconciliation model.

---

## 16. Open items

- **Reality-doc discrepancies §6.1 / §6.2** — confirm the exact `pos_receipts.receipt_type` migration and the current effective totals CHECK formula when the Bridge increment touches `pos_receipts`. Neither affects a Phase 1 design decision.
- **Bridge timing** — owner decision pending on whether the Bridge increment ships inside Phase 1 or folds into Phase 2, depending on how fast Phase 1 Core lands. The spec is structured so either path works without rework.
- **Web B2B payment GL behavior** — whether `PaymentController` payments create an AR/GL entry today is a Phase 3 input (the web-payment retrofit lives in Phase 3), not a Phase 1 question. Flagged in the roadmap.

---

## Appendix A — Round-3 findings → Phase 1 mapping

(See §1.4 table. Findings not listed there are Phase 2-5 per the roadmap.)

## Appendix B — Canonical serialization: byte-level contract

- **Encoding:** UTF-8.
- **Object:** `{` + sorted `"key":value` pairs joined by `,` + `}`. Keys sorted by Unicode code point.
- **Array:** `[` + values joined by `,` + `]`. Producer pre-sorts non-semantic arrays by the DTO-documented key.
- **String:** RFC 8785 JSON string escaping; input NFC-normalized; U+2028 and U+2029 stripped before encoding.
- **Integer:** decimal, no leading zeros, no `+`, `-` only for negatives.
- **Money:** a **string** produced by `CurrencyScale::bcformat($value, CurrencyScale::for($currency))` — fixed scale per currency, no thousands separators, `.` decimal point.
- **Timestamp:** a **string**, `YYYY-MM-DDTHH:MM:SSZ` (UTC, second precision).
- **Boolean / null:** `true` / `false` / `null`.
- **No floats appear anywhere.** The encoder throws if one does — that throw is a build-breaking bug, not a runtime fallback.
- **Hash:** `lowercase_hex( SHA-256( utf8_bytes( canonicalString ) ) )` — 64 characters, `^[0-9a-f]{64}$`.

The golden-vector fixture set (§7.3) is the executable form of this appendix; if the prose and a golden vector ever disagree, the golden vector wins and the prose is corrected.

---

**End of Phase 1 spec.**
