# Offline-First POS Fiscal Architecture — Source of Truth

**Created:** 2026-05-14
**Status:** Authoritative. This document is the single grounding reference for the offline-first POS fiscal architecture and NF525 posture. Every spec, plan, and Codex review prompt for this work must be consistent with it. Where a prior spec (v1, v1.1, v2.0, Phase-1 draft) conflicts with this document, **this document wins** and the spec is corrected.
**Why this exists:** Three Codex review rounds and one architectural mis-placement happened partly because the design drifted from the two owner-provided strategy documents. This consolidates those documents + the codebase reality audit + the NF525 research + every locked decision into one reference, so the drift cannot recur.

**Inputs consolidated here:**
- Owner strategy doc 1 — fiscal chain architecture (`~/Downloads/fiscal_chain_architecture_strategy.md`)
- Owner strategy doc 2 — printable documents architecture (`~/Downloads/pos_printable_documents_architecture.md`)
- Codebase reality audit — `apps/erp/docs/superpowers/research/2026-05-14-pos-fiscal-codebase-reality.md`
- Deep trace of the existing receipt chain (client + server) — 2026-05-14 audit agents
- NF525 + comparable-regime research — 2026-05-14 research agent
- Locked owner decisions through 2026-05-14

---

## 1. The core architectural principle

**The offline-first POS device is the fiscal source of truth. The server is a verify-only mirror. The server never re-authors, never re-serializes, never recomputes-and-replaces.**

This is locked. It is not a preference — it is required by the use case (African markets, flaky internet, the device must operate for days without the server) and it is the dominant compliant pattern across NF525 and every comparable EU regime (§4).

### 1.1 Device responsibilities (the authority)

The Tauri POS application, against its local SQLite database:

- Authors every fiscal event.
- Canonically serializes the event payload to an exact byte string.
- Computes the chained hash over that byte string.
- Assigns the gap-free per-chain sequence number.
- Seals the event **inalterably at the moment of capture** — the local seal is the authoritative fiscal act; it does not depend on later server contact.
- Maintains the chain head locally.
- Retains everything locally until it is safely synced and archived.

### 1.2 Server responsibilities (the verify-only mirror)

The Laravel/PostgreSQL server:

- **Receives** the device's sealed events verbatim, including the **canonical byte string** the device produced and hashed.
- **Verifies** integrity by:
  - re-hashing the device's exact canonical bytes (`SHA256(canonical_string)`) and comparing to the stored `current_hash`;
  - walking chain linkage (`event[N].previous_hash == event[N-1].current_hash`).
- **Stores** the events verbatim — the canonical string, the hashes, the structured payload — as a durable mirror.
- **Provides** the audit/export surface (chain verification command, JET export) operating on the stored mirror.
- **Flags** any mismatch as a tamper/integrity event — it does not "fix" it, does not overwrite.

### 1.3 What the server must NEVER do

- **Never re-serialize** a fiscal payload. Canonical serialization is the cross-language divergence surface; it lives **only on the device, in TypeScript**. PHP never has a canonical encoder for fiscal events.
- **Never recompute-and-replace** a hash. The device's hash is canonical. Server "recomputation" is limited to re-hashing the device's *exact bytes* for verification — a standardized `SHA256` operation, not a re-derivation.
- **Never re-author, re-sequence, renumber, or mutate** any fiscal field.

### 1.4 Why the canonical-bytes-verbatim rule eliminates the cross-language risk

The danger is that PHP and Node.js serialize the same structured payload to *different bytes* (number formatting, Unicode escaping, key-order edge cases) — producing different hashes and breaking the chain. The rule that **the server stores and re-hashes the device's exact canonical byte string** eliminates this:

- Serialization (divergence-prone) happens once, on the device, in one language.
- The server's verification operates on bytes the device produced — `SHA256` of a given byte string is identical in every language; chain-linkage walking is pure hex comparison.
- There is **no second encoder to maintain** and **no byte-for-byte cross-language parity test to keep green**. The maintenance burden the owner correctly flagged does not exist in this design.
- The server *may* parse the canonical string into structured data (JSON parsing is safe and standardized) for querying, rendering, and export — parsing is not serialization. It must never go structure → canonical-string.

---

## 2. The fiscal event engine model

From owner strategy doc 1, reconciled with the codebase reality.

### 2.1 Append-only event ledger, not a receipt-flag table

Every fiscal or monetary operation is an **immutable, typed event** appended to a hash-chained ledger. Nothing is modified after sealing. Corrections are **compensating events** that reference the original. This is implemented as the device's local SQLite ledger, mirrored to the server.

### 2.2 Typed events

Events are explicitly typed (`SALE_RECEIPT`, `ACCOUNT_PAYMENT`, `ACCOUNT_CHARGE`, `ACCOUNT_REFUND`, `DEPOSIT_RECEIPT`, `CASH_IN`, `CASH_OUT`, `SESSION_OPEN`, `SESSION_CLOSE`, `Z_REPORT`, etc.). No generic weakly-typed receipts. Each event type has a typed payload contract.

`ACCOUNT_PAYMENT` (money **received** toward a balance) and `ACCOUNT_CHARGE` (accounts-receivable **created** at a sale — customer leaves owing) are **distinct event types** — opposite balance directions, never the same type.

### 2.3 Hash chain

- Each event carries `sequence_number`, `previous_hash`, `current_hash`.
- The chain is scoped per `(tenant_id, terminal_id)` — each terminal has its own chain.
- Genesis: the terminal's genesis seed (issued once by the server at terminal provisioning) is the `previous_hash` of the first event.
- Hash representation: lowercase hex strings throughout — consistent with the existing receipt chain. No mixing of representations.
- The chain is **continuous across fiscal years** (the year is recoverable from the event's business date) — simpler than a year-scoped reset.

### 2.4 Sessions and closures

`SESSION_OPEN` → transactions / payments / cash movements → `SESSION_CLOSE` → `Z_REPORT`. The Z-report freezes session totals and archives the session. The existing system already has a Z-report chain (a parallel chain on `terminal_state`); the event engine generalizes this pattern. *(Session/Z-report events are later-phase scope — see the roadmap — but the engine is designed to carry them.)*

### 2.5 Signature provider abstraction

Hash/signature computation is behind an interface so country-specific schemes (NF525-LNE, ZATCA, TTN, SAF-T) can be added as implementations without changing event ingestion. The first implementation is the project's own chained-SHA256 scheme. **Naming:** the new fiscal-event signature provider is named distinctly from the existing receipt `V3` hash computer — they are different protocols and must never be cross-wired.

### 2.6 Pattern-derived flags are NOT fiscal events

The fiscal chain records **facts** — what happened. Judgements ("suspicious operation", fraud heuristics, anomaly flags) are produced **downstream** of the event stream, by analytics/ML over the data fed to TimescaleDB, and emitted to a separate analytic store. A heuristic is never wired into the fiscal chain.

---

## 3. Event vs printable document — they are different things

From owner strategy doc 2. This separation is deliberate and load-bearing.

| Concept | What it is |
|---|---|
| **Fiscal event** | The immutable, hash-chained record of "this happened." Authored once, when the operation is finalized/sealed. |
| **Printable document** | A **projection** of one or more fiscal events into a customer- or operator-facing rendering (thermal receipt, A4 PDF, email). |

- Finalizing the operation creates the **event**. Printing **renders** the event. They are not the same action.
- One event → can be rendered many times, in many formats. **Reprinting does not create a new event** — it re-renders the existing one (and may log a `REPRINT_COPY` audit entry).
- Printable types in the system's vocabulary (subset, full list in strategy doc 2): `SALE_RECEIPT`, `REFUND_RECEIPT`, `ACCOUNT_PAYMENT_RECEIPT`, `ACCOUNT_REFUND_RECEIPT`, `DEPOSIT_RECEIPT`, `STORE_CREDIT_RECEIPT`, `CASH_IN_SLIP`, `CASH_OUT_SLIP`, `X_REPORT`, `Z_REPORT`, `REPRINT_COPY`.
- Card-terminal slips are payment-processor acknowledgements, **not** fiscal documents. Kitchen/preparation tickets are operational, **non-fiscal**. Quotes/estimates/pro-forma are **non-fiscal** and editable.
- A printable document model links to its source fiscal event(s) + a printable template + the rendered data. Projection is **pure, deterministic, and versioned**.

---

## 4. Why this is the compliant model (NF525 + comparable regimes)

From the NF525 research. Full sourcing in that report; the load-bearing conclusions:

- **NF525 / Art. 88 LF 2016 (BOI-TVA-DECLA-30-10-30) is architecture-agnostic.** It requires integrity to be *demonstrable* (inalterability, security, conservation, archiving — the "ISCA" principles), not produced in any particular place. **Server-side recomputation is not required.** BOFiP §190 explicitly contemplates distributed point-of-sale → centralizer topologies; §250 explicitly permits remote-server archiving.
- **Device/software-side authoring is the dominant European pattern.** Germany (TSE — a local, offline-by-design security module that authors the chain; the near-exact precedent for our model), Italy (Registratore Telematico), Spain (VeriFactu / TicketBAI), Portugal (certified billing software hash chain), Austria (RKSV) — all mandate or assume the chain is produced at the device/software layer. **None require a downstream tax server to author or recompute the chain.**
- **The server as receive-verify-archive is an accepted audit surface** under NF525, provided the synced copy carries the same integrity guarantees the device produced (intact chain, sequence/closure counters). Nothing requires the physical device be presented at audit.
- **Offline operation with deferred sync is contemplated and compliant.** The German TSE is offline-by-design. Italy explicitly permits offline memorization with a 12-day transmission window. NF525 itself is *silent* on a maximum offline duration — which is a genuine gap, not a permission: the safe reading is **seal-on-capture** (the device must guarantee inalterability the moment data is recorded), which a device-authored seal satisfies directly.

**Honest caveats (carried forward, not hidden):**
1. NF525 **certification** by an accredited body becomes **mandatory in France from 1 September 2026** — editor self-attestation is being phased out. Architectural compliance (this document) is established; *certification* is a separate, mandatory administrative step that must cover the device + server as one certified system. It is out of scope for the build phases but must not be forgotten.
2. NF525's silence on maximum offline duration has no French analog to Italy's explicit 12-day rule. Build to the strict seal-on-capture reading; do not represent "France permits unlimited offline" as a sourced fact.
3. The JET (Journal des Événements Techniques) is an NF525-*standard* artifact, not a legal-text artifact — but it is required for certification. Its source data is the device-produced event/technical log.

---

## 5. Where everything lives — responsibility matrix

| Concern | Device (Tauri / SQLite / TypeScript) | Server (Laravel / PostgreSQL / PHP) |
|---|---|---|
| Author a fiscal event | **Yes — the authority** | Never |
| Canonical serialization of payload | **Yes — the only place** | **Never** |
| Compute chained hash | **Yes** | Re-hash device's exact bytes for verification only |
| Assign sequence number | **Yes** | Never |
| Maintain chain head | **Yes — local chain head is authoritative** | Mirrors the device's chain head; never advances it independently |
| Seal inalterably at capture | **Yes** | Stores the sealed event verbatim |
| Store the event | Local SQLite ledger | Verbatim mirror (canonical string + hashes + structured payload) |
| Verify chain integrity | Local self-check available | **Yes — re-hash + linkage walk; read-only** |
| Parse payload for query/render/export | Yes | Yes (parsing is safe; serialization is not) |
| Produce printable documents | Yes (projection from local events) | Yes (projection from mirrored events, e.g. web reprint) |
| Audit / JET export surface | Local export available | **Yes — operates on the stored mirror** |
| Reconcile / re-author / mutate | Compensating events only | **Never** |

---

## 6. The existing precedent — what we reuse, where we deliberately diverge

The codebase audit traced the existing **receipt** fiscal chain in full. This is the precedent. It is mostly the right shape, with one deliberate divergence.

### 6.1 What the existing receipt chain does (verified)

**Client side (the right precedent — reuse it):** the Tauri app authors and seals receipts entirely locally and offline. `terminal_state` (SQLite) is the local chain head (`genesis_seed`, `last_hash`, `hash_sequence`). The seal flow — idempotency guard → read chain head → compute totals → allocate sequence → compute hash → atomically insert the receipt row + advance the chain head + update voucher balances in one SQLite transaction → debounced sync — is exactly the model the fiscal event engine should follow. The local seal is the authoritative fiscal act locally; `status='pending'` is sync status, not fiscal status.

**Server side (the divergence):** on sync, the existing receipt path has the server **recompute** the hash (`ReceiptFinalizationService::finalize()` → `V3ReceiptHashComputer::compute()`), **overwrite** the receipt's `fiscal_hash` with the server's value, and advance the server-side terminal chain with the server's hash. The client's `offline_fiscal_hash` is used only as a comparison input; mismatch throws and rolls back. **The existing receipt chain makes the server the authority.**

### 6.2 The deliberate divergence

The new fiscal event engine is **Option B — device-authority, server-verify-only** (§1). This **deliberately diverges** from the existing receipt chain's server-recompute model. The reasons are locked (owner decision, 2026-05-14):

- Offline-first: the device runs for days without the server; the server cannot be the authority for data it has not seen.
- Cross-language safety: server recomputation = server re-serialization = the PHP/Node.js divergence surface. The canonical-bytes-verbatim rule (§1.4) removes it; server-recompute reintroduces it.
- NF525 and every comparable regime treat device-authoring as the norm; server-recompute is not required (§4).

**Tracked inconsistency:** after this work, the receipt chain (server-authority) and the fiscal event engine (device-authority) will have different server-side semantics. Whether to realign the receipt chain to the device-authority model is a **separate, larger initiative** — explicitly out of scope for the customer-accounts phases — but it is recorded here so it is not lost. See §10.

### 6.3 Directly reusable patterns (from the audit)

The fiscal event engine should reuse these proven patterns rather than invent parallel ones:

1. **Chain-head table** — a single-row-per-entity table (`terminal_state` analog) holding `genesis_seed`, `last_hash`, `sequence`, updated under a guarded "sequence must increase" check.
2. **Sequence allocation** — read chain head → allocate `sequence + 1` → write the event + advance the head atomically.
3. **Hash-compute-and-advance** — read `previous_hash` from the head, build canonical input, hash, update head to the new hash + sequence, all in one local transaction.
4. **Offline-row lifecycle** — events sealed with `status='pending'`; sync layer owns the status transitions (`pending → syncing → synced/failed`); idempotency key prevents double-insert.
5. **Debounced sync trigger** — fire-and-forget sync scheduling after the local commit.
6. **Server verify-and-store** — the existing `verifyTerminalChain()` / `VerifyPosChainCommand` / `ExportNf525JetCommand` pattern is reusable for the server mirror's verification and audit surface — *with the correction that the new engine's server verification re-hashes the device's bytes and stores them verbatim, rather than recomputing-and-overwriting.*

---

## 7. Cross-cutting guardrails

These apply to every phase. Any spec or plan that violates them is wrong.

### 7.1 B2B and B2C are distinct — never conflate

- The **sale application** (Tauri POS) is **B2C-primary**. It may also serve B2B customers, but its flows, fiscal treatment, and data model are distinct from the **B2B web flows**.
- The existing web/treasury B2B mechanisms — `PaymentController`, `PaymentAllocationService`, `MultiPaymentService` (including its existing `createOnAccountPayment()`), `Document` invoices — are **B2B web flows**. The POS needs **parity** functionality for B2C client accounts (its own payment emitters, its own FIFO open-balance settlement). Do not assume a B2B web mechanism is reusable in the POS path without verifying it; the POS path uses the device-authored fiscal event engine.
- Concrete example: the **offline-first deposit** in the sale application is a different feature from the **B2B online deposit flow** — same word, different feature, different fiscal handling, different store.

### 7.2 Web and offline-first are distinct — never conflate

- Web flows assume connectivity and a live server.
- Offline-first flows (the sale app) assume the **local SQLite ledger is the source of truth**, with the server reconciling *to* it. Never specify a sale-app flow that silently assumes server availability.

### 7.3 Every offline-first operation carries a reconciliation classification

Each operation in the sale app must declare one of:

- **`offline_authoritative`** — the device is the source of truth; on reconnect, the server verifies and stores; online never overrides offline. The default for fiscal events under Option B.
- **`server_reconciles`** — the operation proceeds offline against last-known state; on sync the server computes a downstream result (e.g. payment allocation across invoices) and emits a reconciliation event; the offline record stays valid.
- **`block_unless_online`** — too risky on stale data; the device refuses it offline (e.g. give-out-cash operations above a threshold).

### 7.4 Fiscal events are factual

Per §2.6 — the chain records facts, not judgements.

### 7.5 No unverified codebase claims

Every spec's codebase claim must be traceable to the codebase reality audit, this document, or be explicitly flagged "to verify." Three Codex rounds were partly caused by unverified claims.

---

## 8. Compliance posture summary

- **Architecture:** device-authority + server-verify-only mirror — compliant under NF525 (architecture-agnostic, distributed topologies explicitly contemplated) and matching the dominant pattern across Germany/Italy/Spain/Portugal/Austria. **Established.**
- **Certification:** accredited-body NF525 certification is mandatory in France from 2026-09-01. A separate administrative step, out of build scope, **not forgotten**.
- **Seal-on-capture:** the device must guarantee inalterability at the moment of recording — do not depend on later server contact for integrity.
- **Offline duration:** build to the strict seal-on-capture reading; NF525 sets no explicit cap (unlike Italy's 12 days).
- **Jurisdictions:** the signature-provider abstraction (§2.5) keeps country-specific schemes pluggable; concrete country providers ship per-market, not upfront.

---

## 9. Locked decisions (as of 2026-05-14)

| # | Decision |
|---|---|
| D1 | The offline-first POS device is the fiscal source of truth. Server is a verify-only mirror. (Option B.) |
| D2 | The server never re-serializes a fiscal payload. Canonical serialization is device-only, TypeScript-only. No PHP fiscal encoder, no mirrored encoder. |
| D3 | The device syncs the canonical byte string verbatim; the server stores it verbatim and verifies by re-hashing those exact bytes + walking chain linkage. |
| D4 | The fiscal layer is an append-only, typed-event, hash-chained ledger — not a receipt-flag table. |
| D5 | Fiscal event and printable document are distinct concepts; printing is a projection; reprints create no new event. |
| D6 | `ACCOUNT_PAYMENT` (money received) and `ACCOUNT_CHARGE` (AR created at sale) are distinct event types. |
| D7 | The fiscal event engine reuses the existing receipt-chain *client-side* patterns (chain-head table, sequence allocation, hash-compute-and-advance, offline-row lifecycle, debounced sync). |
| D8 | The fiscal event engine deliberately diverges from the existing receipt chain's *server-side* recompute-and-overwrite model. The receipt chain is not realigned as part of this work; the inconsistency is tracked (§10). |
| D9 | Charge-to-account does not exist in the POS today and has no AR/GL path; it is later-phase work, not first-slice. |
| D10 | First go-live slice = fiscal event engine + `ACCOUNT_PAYMENT` + minimum customer attach + server mirror + `ACCOUNT_PAYMENT_RECEIPT` printout. No `SALE_RECEIPT_BRIDGE`. |
| D11 | Cross-cutting guardrails §7 are permanent and apply to every phase. |

---

## 10. Tracked inconsistencies & open questions

1. **Receipt chain vs event engine server-side semantics** — the receipt chain server-recomputes-and-overwrites (server-authority); the new event engine server-verifies-verbatim (device-authority). Realigning the receipt chain to device-authority is a separate future initiative, deliberately out of customer-accounts scope. Tracked here so it is not lost.
2. **NF525 certification** — mandatory 2026-09-01; separate administrative track; owner to schedule.
3. **Existing `MultiPaymentService::createOnAccountPayment()`** — a B2B web on-account payment path already exists. The POS B2C on-account flow is parity work, not reuse — but the two should be reconciled at the data/reporting layer eventually. Phase 2/3 input.
4. **NF525 offline-duration gap** — no explicit French maximum; build to seal-on-capture; revisit if certification guidance clarifies.
5. **Web B2B payment GL behavior** — whether `PaymentController` payments post AR/GL entries is a later-phase (charge-to-account / web-retrofit) input, not a first-slice question. To verify before that phase.

---

## 11. Document control

- This document **supersedes** the architectural-placement assumptions in: v1, v1.1, v2.0 specs, and the 2026-05-14 Phase-1 draft. Those remain as audit history; where they conflict with this document, this document is correct.
- It **complements** (does not supersede): the codebase reality audit (`2026-05-14-pos-fiscal-codebase-reality.md`) and the multi-phase roadmap (`2026-05-14-pos-customer-accounts-roadmap.md`) — those are companion references. The roadmap's cross-cutting principles are restated and locked here in §7.
- Every future phase spec opens by referencing this document and the codebase reality audit, and must be consistent with both.
- Every future Codex review prompt must instruct the reviewer to ground findings in this document and the two owner strategy documents.

---

**End of source-of-truth document.**
