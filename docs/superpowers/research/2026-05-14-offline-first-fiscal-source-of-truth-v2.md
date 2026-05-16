# Offline-First POS Fiscal Architecture — Source of Truth (v2)

**Created:** 2026-05-14
**Status:** Authoritative. The single grounding reference for the offline-first POS fiscal architecture and NF525 posture. Every spec, plan, and Codex review prompt for this work must be consistent with it. Where any prior spec conflicts, **this document wins.**
**Supersedes:** `2026-05-14-offline-first-fiscal-source-of-truth.md` (v1). v1 was adversarially assessed by Codex (`2026-05-14-offline-first-fiscal-source-of-truth-codex-assessment.md`) — verdict UNSOUND, 4 CRITICAL / 8 MAJOR / 3 MINOR. v2 resolves all 15 findings and incorporates the owner's clean-rebuild decision. v1 + its assessment remain as audit history.

**Inputs consolidated here:**
- Owner strategy doc 1 — fiscal chain architecture (`~/Downloads/fiscal_chain_architecture_strategy.md`)
- Owner strategy doc 2 — printable documents architecture (`~/Downloads/pos_printable_documents_architecture.md`)
- Codebase reality audit — `2026-05-14-pos-fiscal-codebase-reality.md`
- Receipt-chain trace (client + server), 2026-05-14
- Receipt-chain clean-rebuild scoping map, 2026-05-14
- NF525 + comparable-regime research, 2026-05-14
- Signature-based regimes research (German TSE), 2026-05-14
- Integrity-mismatch + cross-component-hash + chain-recovery research, 2026-05-14
- Codex v1 assessment (15 findings), 2026-05-14
- Locked owner decisions through 2026-05-14, including the clean-rebuild decision

---

## 1. Core architectural principle

**There is one fiscal pattern. The offline-first POS device is the fiscal source of truth. The server is a verify-only mirror. The server never re-authors, never re-serializes a fiscal payload for hashing, never recomputes-and-replaces.**

This is locked. It is required by the use case (African markets, flaky internet — the device must operate for days without the server) and it matches the dominant compliant practice (§10).

**Single pattern, no exceptions.** v1 carried a "deliberate divergence" — the new fiscal event engine on device-authority, the existing receipt chain left on server-recompute. That is gone. The existing receipt chain is being **rebuilt clean** on this same pattern (§12). There is no migration (no live fiscal data exists), no two-model coexistence, no "realign later." One pattern, applied everywhere.

### 1.1 The device authors; the server verifies

- The **device** (Tauri/SQLite): authors every fiscal event, canonically serializes the payload to an exact byte string, hashes that string, chains it, assigns the gap-free sequence, and **seals it inalterably at the moment of capture**. The local seal is the authoritative fiscal act; it does not depend on later server contact.
- The **server** (Laravel/PostgreSQL): receives the device's sealed events verbatim — **including the canonical byte string** — verifies integrity (re-hash the device's exact bytes; walk chain linkage), stores everything verbatim as a durable mirror, and provides the audit/export surface. On any anomaly it **flags**, never blocks (§7).

### 1.2 The canonical-bytes-verbatim rule

The cross-language hashing risk (PHP and Node.js serializing the same object to different bytes) is eliminated **by construction**, not mitigated:

- The device serializes **once**, in TypeScript, and transmits the canonical byte string **verbatim**.
- The server stores those exact bytes and verifies by re-hashing them — `SHA-256` of an identical byte string is identical in every language. The server **never re-serializes** a fiscal payload.
- This is a recognized, standardized pattern — its formal expression is **RFC 7797** (JWS detached/unencoded payload: the verifier verifies against the bytes *as received* and is forbidden from re-serializing). It is the more robust choice; there is no second encoder to maintain.

**Implementation constraints (load-bearing):**
- The canonical bytes are stored in a **binary/blob column** (`BYTEA` server-side, `TEXT` in SQLite) — **never round-tripped through a JSON/JSONB column**, which would silently re-serialize and break the hash.
- The canonical bytes are **authoritative**. Any structured payload the server holds for query/render/export is **derived server-side from the verified canonical bytes by a strict parser** (rejects duplicate keys, out-of-grammar numbers, invalid Unicode) — never accepted independently from the device.
- The device's canonical serialization must still be **deterministic and JCS-conformant** (RFC 8785) and covered by **golden-vector tests**, so a future re-implementation or verifier can reproduce it.

---

## 2. The three-layer model

From owner strategy doc 2 (§§3–4, restored — v1 wrongly collapsed this to two layers). Three distinct, related concepts:

| Layer | What it is | Where it lives |
|---|---|---|
| **Business Document** | The domain object: products/services sold, quantities, prices, VAT, sale obligations, partial-payment state, customer linkage, B2B/B2C distinction. The thing workflows operate on. | Mutable/editable per business rules, in the relevant domain module. |
| **Fiscal Event** | The immutable, hash-chained record of "this happened." Authored once, when an operation is finalized/sealed. | The append-only fiscal ledger (device SQLite → server mirror). |
| **Printable Representation** | A projection of one or more fiscal events into a customer/operator-facing rendering (thermal receipt, A4 PDF, email). | Generated on demand, device or server. |

Relationships: one Business Document can produce **multiple** Fiscal Events over its life (e.g. a sale, then a later account payment against it). One Fiscal Event can produce **multiple** Printables. **Reprinting produces no new Fiscal Event** — it re-renders an existing one (and may log a `REPRINT_COPY` audit entry).

Business state (VAT obligations, partial-payment progress, charge-to-account balance) belongs in the **Business Document layer** — not stuffed into fiscal event payloads or print projections.

---

## 3. The fiscal event engine

From owner strategy doc 1.

- **Append-only typed-event ledger.** Every fiscal/monetary operation is an immutable, **typed** event appended to a hash-chained ledger. No weakly-typed generic receipts. Nothing is modified after sealing.
- **Corrections are compensating events** that reference the original — never mutation, never soft-delete.
- **Hash chain** scoped per `(tenant_id, terminal_id)`. Each event carries `sequence_number`, `previous_hash`, `current_hash` (all lowercase hex). Genesis: the terminal's genesis seed (Appendix B).
- **Sequence is continuous across fiscal years** — the fiscal year is recoverable from the event's business date. (See §6 on how this relates to closures.)
- **Sessions and closures** — `SESSION_OPEN` → operations → `SESSION_CLOSE` → `Z_REPORT`. Z-report freezes session totals. *(Session/Z-report events are later-phase scope; the engine is designed to carry them. The existing Z-report chain is independent of the receipt chain — §12.)*
- `ACCOUNT_PAYMENT` (money **received** toward a balance) and `ACCOUNT_CHARGE` (accounts-receivable **created** at a sale) are **distinct event types** — opposite balance directions.
- **Pattern-derived flags are NOT fiscal events.** The chain records facts. Judgements ("suspicious", fraud heuristics) are produced downstream of the event stream in analytics/TimescaleDB and emitted to a separate analytic store.

The full canonical event-type and printable-type taxonomy is in **Appendix A** (no "etc.").

---

## 4. Integrity & signature model

The hash chain and the signature are **two orthogonal concerns**. v1 conflated them by calling the first implementation a "signature provider" when it is not one.

### 4.1 The integrity layer — always present

`HashChainIntegrityProvider` is the first and currently-only provider. It provides **sequence integrity** — chained SHA-256 over the canonical bytes makes insertion, deletion, and reordering detectable. It does **not** prove authorship: anyone who knows the algorithm and the data can recompute a valid chain. This is honest, and it is sufficient for France (NF525 names chaining as an accepted inalterability technique — §10) and Tunisia.

### 4.2 The signature layer — designed-for, not built

A future `SignatureProviderInterface` adds **authorship proof** (e.g. the German TSE: a certified module signs each event with a protected ECDSA private key). We do **not build this now** — Germany is not a launch market and building it means procuring/certifying against a market we have not entered. But two schema decisions **cannot be retrofitted** later (a migration over immutable, append-only fiscal data) and **must be made in the Phase 1 schema**:

1. **`signature_status` lifecycle enum** on every fiscal event: `not_required | pending | signed | failed`. A cloud TSE signs **asynchronously** (buffers locally, signs on reconnect) — so "event created" and "event signed" are distinct states. Without this enum, adding a German cloud-TSE later is a migration over immutable rows.
2. **A nullable, structured signature object** on every fiscal event, separate from the hash-chain columns: `signature_algorithm`, `signature_value`, `signature_counter`, `signature_provider`, `signing_device_id`, `certificate_id`, `signed_payload_ref`, `time_source_value`, `time_format`, `provider_transaction_id` (distinct from the event id). All null for France/Tunisia today.

The `SignatureProviderInterface` must be **async-capable** — `sign()` may return `pending` and complete later — with provider **capability flags** (`requires_connectivity`, `signs_synchronously`, `assigns_transaction_id`) and a deferred re-signing path (a queue/job for `pending` events). The hash chain stays orthogonal — a future signature is *additive* alongside the chain, not a replacement.

**Explicitly: chained-SHA256 alone is a France/Tunisia inalterability primitive. It is NOT Germany/TSE-ready.** That boundary is stated, not implied.

---

## 5. Canonical serialization & cross-component integrity

Restates and tightens §1.2 into the operating rules:

- **Serialize once, on the device.** Transmit the canonical byte string verbatim. Store it in a binary column. The server re-hashes those exact bytes — nothing more.
- **The server never re-serializes a fiscal payload for hashing.** This is the rule. (D2, narrowed below.)
- **Canonical bytes are authoritative.** The structured payload the server holds for query/render/export is *derived* from the verified canonical bytes by a strict parser, never accepted independently from the device. On divergence, the canonical bytes win and a strict-parse failure is an integrity exception (§7).
- **D2 narrowed (resolves Codex MAJOR):** "the server never serializes" was too absolute. The rule is: **PHP must not re-serialize fiscal-event payloads for hash verification or replacement.** PHP **may** serialize *derived audit/export artifacts* — e.g. the NF525 JET XML — provided those reference verified event ids/hashes and never become the source of chain truth. (The existing `Nf525XmlBuilder` does exactly this and is unaffected — §12.)
- **No attested byte-identical PHP↔JS RFC-8785 library pair exists.** This is *why* the serialize-once pattern is used rather than "run a JCS library on both sides." We do not depend on two libraries agreeing.

---

## 6. Time & clock trust model

The device system clock is **untrusted** — a cashier with terminal access can roll it back. A foundational fiscal document must address this (v1 did not).

Every fiscal event carries:
- **`event_time_device`** — the device-claimed wall-clock time at sealing. Useful but untrusted.
- **`sequence_number`** — the monotonic per-terminal chain sequence. **This, not the clock, is the authoritative ordering.** Ordering integrity does not depend on the clock.
- **`last_server_time_seen`** — the most recent trusted server time the device observed, stamped into the event. Bounds how stale the device's time reference is.
- **`server_received_at`** — set by the server on ingestion. Trusted, but only an upper bound on event time.

Rules:
- **Clock-rollback detection:** if `event_time_device` moves backwards relative to a prior event on the same chain (while `sequence_number` correctly increases), the event is accepted (never block) but **flagged** as a time anomaly (§7).
- **Drift detection:** large divergence between `event_time_device` and `last_server_time_seen` / `server_received_at` raises an audit flag.
- **Closure-period assignment** uses the business date derived under documented rules (device date, cross-checked against `last_server_time_seen`), with anomalies flagged — closures are never silently mis-dated.
- Honest limitation: an offline device's clock cannot be *fully* trusted. The mitigations are detection + flagging + treating the monotonic chain sequence as the real ordering authority. This is stated, not hidden.

---

## 7. Integrity exceptions & chain recovery

**Accept-and-flag. Never block the POS.** This is not a preference — it is the regulator-sanctioned model. No regulator has a "sync mismatch must block" rule; their malfunction procedures are uniformly *continue-operating-with-annotation* (Italy's most explicit: an anomalous transmission is flagged **with a stated reason**, the business keeps trading). Blocking a synced record means rejecting a transaction that physically already happened — incoherent under device-authority.

### 7.1 Integrity exception classes

The server distinguishes anomalies by **what broke**, not by inferred intent:

| Class | Meaning | Severity | Likely cause |
|---|---|---|---|
| `signature_invalid` | a signature fails to verify against an attested key | **high** | possible tampering / key compromise |
| `canonical_hash_mismatch` | `SHA-256(canonical_bytes) ≠ current_hash` | **low** | almost always a serialization/version-drift bug |
| `sequence_gap` | a gap or break in the per-terminal sequence | **medium** | device crash, lost record |
| `time_anomaly` | clock rollback or excessive drift (§6) | **low–medium** | clock tampering or drift |

Conflating `signature_invalid` with `canonical_hash_mismatch` would make every software bug look like fraud — they are deliberately separate classes.

### 7.2 Quarantine, never discard

A flagged record is **always persisted** — into a **quarantine partition** of the mirror: stored, not trusted, **not counted in clean totals**, carrying a **mandatory structured reason**. An admin alert is raised. An operator resolves it (reclassify-with-explanation, or escalate). Clean fiscal exports are derivable *excluding* quarantine; the quarantined segment exports separately, annotated. The server may gate future sync or flag a device for repeated `signature_invalid` — but it never instructs the device to stop selling.

### 7.3 Chain recovery

When a local chain breaks (DB corruption, a bad hash, a sequence gap), the terminal **continues operating** in a recorded `degraded` mode — a hard-stop is *more* dangerous fiscally (it tempts untracked off-system sales). Recovery is **two signed events**:

- **`ChainBreakDetected`** — reason, last-good sequence + hash, the offending record.
- **`ChainRestart`** — a new genesis, **references the last-good anchor**, carries **operator authorization** (who, when, why) and a provenance link to the prior chain.

The broken segment is **never deleted** — it is quarantined, synced, and appears in fiscal exports flagged, alongside the two incident events. Because the restart is itself signed and chained, an auditor sees a deliberate, attributed incident — the opposite of a silent gap.

---

## 8. Durability & device loss

In a device-authority architecture, a terminal lost/stolen/destroyed **before sync** means the only authoritative copy of those fiscal records is gone — and fiscal law requires multi-year conservation. v1 was silent on this; it is a foundational requirement, not an implementation detail.

Mandatory controls:
- **Encrypted local backup** of the unsynced fiscal segment (on-device, using the existing AES-GCM file-key crypto).
- **Operator-visible unsynced-risk indicator** — the operator always sees how much fiscal data is unsynced and for how long.
- **Forced archive/export** when the device has been offline beyond a configured threshold (N hours/days) — produces a portable encrypted archive of the unsynced segment.
- **Maximum-unsynced threshold** — beyond it, the device escalates (operator warning; configurable harder gates).
- **Incident register** — device loss/failure is a recordable incident with a declaration/recovery procedure.

Honest note: NF525 sets no explicit maximum offline duration (Italy's explicit 12-day rule has no French analog). Build to the strict reading — seal-on-capture plus forced periodic archive — and the conservation obligation is met even when a terminal fails.

---

## 9. Multi-terminal / company-level integrity

Chains are per-`(tenant_id, terminal_id)` — a company with N terminals has N independent chains. Per-chain verification alone cannot tell an auditor whether a *terminal*, a *day*, or a *location* is missing from the company export. The foundational model must include a company-level layer:

- **Terminal registry snapshots** — the authoritative list of every terminal expected to exist for a company at a point in time.
- **Per-day closure manifests** — for each business day, every expected terminal chain head, with explicit missing-terminal / offline exceptions.
- **Company grand-total rollups** — derived from per-terminal closures.
- **Export verification** checks **both** per-chain integrity **and** fleet completeness (no terminal/day silently absent).

---

## 10. Why this is compliant — NF525 and comparable regimes

Corrected and reworded from v1 (which overstated the posture and carried a stale fact).

- **NF525 is architecture-agnostic at the legal-text level.** BOFiP (`BOI-TVA-DECLA-30-10-30`) states the law defines no technical specification or imposed solution; a system must satisfy inalterability, security, conservation, archiving (ISCA). Chaining is **explicitly named** as an acceptable reliable technique. BOFiP permits centralizer **conservation** — it does not require centralizer **authoring**. Device-authoring + server-verify-and-archive is squarely within what NF525 contemplates.
- **Posture, stated correctly (resolves Codex MAJOR):** device/local authoring is **compatible with** offline fiscalization across the relevant regimes — it is **not** accurate to call it "the dominant compliant pattern across every regime." Each jurisdiction imposes **additional** controls: France — ISCA proof; Germany — a certified TSE (security module, signature counter, time source, protected keys) which chained-SHA256 alone does **not** satisfy; Italy — RT / procedure-web transmission; Spain/Portugal/Austria — jurisdiction-specific record/signature/export rules. A per-jurisdiction provider-readiness matrix is future work; the `SignatureProviderInterface` (§4) is the abstraction that absorbs these.
- **Certification status — corrected (resolves Codex CRITICAL).** v1 stated accredited NF525 certification becomes mandatory 2026-09-01. **That is false as of 2026-05-14.** Law 2026-103 Article 125 amended CGI Article 286 to **restore the individual publisher attestation**; Service-Public confirms the planned 2026-09-01 removal of self-certification was **cancelled**. Current law: conformity may be justified by **either** an accredited certificate **or** a publisher attestation. This remains a tracked compliance watch item — French rules in this area have already moved twice.
- **Seal-on-capture** is a *safe interpretation*, not a sourced regulatory phrase. BOFiP requires original data rendered inalterable and corrections traced; it does not use "seal-on-capture" or define a maximum offline duration. We build to the strict reading (§8) because it is the defensible one.
- **Chained-SHA256 vs signatures:** a hash chain helps satisfy French inalterability (BOFiP names chaining) but does **not** prove authorship. For Germany it is not TSE-equivalent. §4 makes this boundary explicit.

---

## 11. Responsibility matrix — device vs server

| Concern | Device (Tauri / SQLite / TypeScript) | Server (Laravel / PostgreSQL / PHP) |
|---|---|---|
| Author a fiscal event | **Yes — the authority** | Never |
| Canonical serialization of a fiscal payload | **Yes — the only place** | **Never** |
| Compute the chained hash | **Yes** | Re-hash the device's exact canonical bytes — verification only |
| Assign sequence number | **Yes** | Never |
| Seal inalterably at capture | **Yes** | Stores the sealed event verbatim |
| Store the canonical bytes | Local SQLite (`TEXT`) | Verbatim mirror (`BYTEA`) |
| Verify chain integrity | Local self-check available | **Yes — re-hash stored bytes + linkage walk; read-only** |
| Parse payload for query/render/export | Yes | Yes (strict parser; parsing ≠ serializing) |
| Serialize derived export artifacts (JET XML, etc.) | Yes | **Yes** — references verified ids/hashes; never chain truth |
| Produce printable documents | Yes | Yes (projection) |
| Sign for authorship (future TSE etc.) | Yes (or local certified module) | Cloud-TSE variant only; async |
| Flag integrity exceptions | Local detection available | **Yes — quarantine + alert; never block** |
| Re-author / recompute-and-replace / mutate | Compensating events only | **Never** |

---

## 12. The clean rebuild — the existing receipt chain

The existing receipt chain server-recomputes-and-overwrites (server-authority) and throws-and-rolls-back on hash mismatch. That pattern is the source of the production chain-breaks and POS notices. It is **discarded** and the receipt chain is **rebuilt clean** on the §1 pattern. **No migration — there is no live fiscal data.** The scoping audit (2026-05-14) found the rebuild is **~65% reuse, ~25% rework, ~5% discard, ~5% new**:

- **REUSE (~65%)** — client-side authoring/sealing (already correct: `receiptService.ts`, `terminal_state`, `offline_receipts`), the sync batch orchestration + stock/voucher/payment logic in `ReceiptSyncService`, chain-state persistence, the `Compliance/FiscalHashService` chain-prefix logic, and the **entire NF525 JET export pipeline** (`ExportNf525JetCommand` / `Nf525JetExportService` / `Nf525XmlBuilder` — it reads stored hashes and serializes XML; it does not recompute; unaffected).
- **REWORK (~25%)** — `ReceiptFinalizationService` and `ReceiptHashService::verifyTerminalChain()` / `verifyHash()` move from recompute-from-structured-models to **re-hash the stored canonical bytes**. The `ReceiptSyncService` hash-mismatch branch moves from **throw + roll back** to **log + flag** (§7).
- **DISCARD (~5%)** — the server recompute-and-overwrite path for receipts; the `OfflineFiscalHashMismatchException` throw-and-rollback behavior (the exception class may stay as an audit-trail artifact but is never thrown to block).
- **NEW (~5%)** — a `canonical_bytes` column (`BYTEA` on `pos_receipts`, `TEXT` on `offline_receipts`); the client storing + transmitting its canonical bytes; the integrity-exception/quarantine path; the `signature_status` + signature object columns (§4).
- **Z-report chain** is **independent** of the receipt chain. It currently also server-recomputes and needs the same treatment — but that is a **separate later rebuild task**, not part of the receipt-chain rebuild.

The detailed touch-point map is the 2026-05-14 scoping audit; it feeds the phase plan.

---

## 13. Cross-cutting guardrails

Permanent. Any spec or plan that violates them is wrong.

1. **B2B and B2C are distinct — never conflate.** The sale application is B2C-primary; it may also serve B2B, but its flows/fiscal-treatment/data-model are distinct from the B2B web flows. The POS needs **parity** functionality for B2C client accounts — not reuse of B2B web mechanisms without verification.
2. **Web and offline-first are distinct — never conflate.** The sale app's source of truth is its local ledger; the server reconciles *to* it.
3. **Every offline-first operation carries a reconciliation classification** — `offline_authoritative` (default for fiscal events), `server_reconciles` (a downstream server projection differs but the offline record stays valid), or `block_unless_online` (too risky on stale data).
4. **Fiscal events are factual** — judgements are downstream analytics, never in the chain.
5. **No unverified codebase claims** — every spec's codebase claim traces to the codebase reality audit, this document, or is explicitly flagged "to verify."

---

## 14. Locked decisions

| # | Decision |
|---|---|
| D1 | Device-authority: the offline-first POS device is the fiscal source of truth; the server is a verify-only mirror. Paired with the §6 clock, §7 exception/recovery, §8 durability controls. |
| D2 | The server never re-serializes a fiscal-event payload **for hash verification or replacement**. PHP **may** serialize derived audit/export artifacts (JET XML, etc.) that reference verified ids/hashes and never become chain truth. |
| D3 | Canonical bytes are produced once on the device, transmitted verbatim, stored in a binary column, and are authoritative. The structured payload is derived server-side by a strict parser, never accepted independently from the device. |
| D4 | The fiscal layer is an append-only, typed-event, hash-chained ledger. Corrections are compensating events. |
| D5 | The three-layer model is canonical: Business Document / Fiscal Event / Printable Representation. |
| D6 | `ACCOUNT_PAYMENT` (money received) and `ACCOUNT_CHARGE` (AR created at sale) are distinct event types. |
| D7 | The rebuild reuses the existing client-side patterns and the conceptually-sound server orchestration; it does **not** reuse the server recompute/verify code (that is reworked) — see §12's reuse/rework/discard/new split. |
| D8 | **There is one fiscal pattern.** The existing receipt chain is rebuilt clean on it — no migration, no two-model coexistence. (Replaces v1's "deliberate divergence.") |
| D9 | Charge-to-account does not exist in the POS today and is later-phase work. |
| D10 | First go-live slice = the fiscal event engine + `ACCOUNT_PAYMENT` + minimum customer attach + server mirror + `ACCOUNT_PAYMENT_RECEIPT` printout. The foundation phase **includes** the receipt-chain clean rebuild (the engine cannot be built alongside a broken sibling on a different pattern). No `SALE_RECEIPT_BRIDGE`. |
| D11 | The §13 cross-cutting guardrails are permanent. |
| D12 | The first integrity provider is `HashChainIntegrityProvider` — honest naming; it proves sequence integrity, not authorship. The Phase 1 schema includes the non-retrofittable `signature_status` enum and nullable signature object; the `SignatureProviderInterface` is async-capable. TSE/Germany is design-for, not build-now; chained-SHA256 is explicitly "not Germany-ready." |
| D13 | Integrity anomalies are **accepted, persisted to quarantine, and flagged** — never block the POS. Exception classes are distinguished (`signature_invalid` / `canonical_hash_mismatch` / `sequence_gap` / `time_anomaly`). Chain recovery is two signed events (`ChainBreakDetected` + `ChainRestart`); the broken segment is quarantined, never deleted. |
| D14 | Partial payments use **Option B** — separate `SALE_RECEIPT` + `ACCOUNT_PAYMENT_RECEIPT` — unless a later explicit owner decision overrides it. (Owner strategy doc 2 recommendation.) |

---

## 15. Open questions / watch items

1. **NF525 certification status is volatile** — French rules moved twice already (self-certification scheduled for removal, then restored by Law 2026-103). Treat as a live watch item; re-verify before any French go-live.
2. **Z-report chain rebuild** — independent of the receipt-chain rebuild; the Z-report chain also server-recomputes today and needs the same clean-rebuild treatment as a separate later task.
3. **Existing `MultiPaymentService::createOnAccountPayment()`** — a B2B-web on-account payment path already exists. The POS B2C on-account flow is parity work, not reuse; the two should be reconciled at the data/reporting layer eventually.
4. **Per-jurisdiction provider-readiness matrix** — when markets beyond France/Tunisia approach, a matrix mapping each jurisdiction's additional controls to `SignatureProviderInterface` implementations.
5. **NF525 offline-duration gap** — no explicit French maximum; we build to seal-on-capture + forced periodic archive (§8); revisit if certification guidance clarifies.

---

## 16. Document control

- This document **supersedes** v1 (`2026-05-14-offline-first-fiscal-source-of-truth.md`) and the architectural-placement assumptions in the v1/v1.1/v2.0/Phase-1-draft specs. Those remain as audit history.
- It **complements** the codebase reality audit and the multi-phase roadmap. The roadmap's cross-cutting principles are restated and locked here in §13.
- Every future phase spec opens by referencing this document and the codebase reality audit, and must be consistent with both.
- Every future Codex review prompt must instruct the reviewer to ground findings in this document and the two owner strategy documents.

---

## Appendix A — Canonical event-type and printable-type taxonomy

From the two owner strategy documents. No "etc."

**Fiscal event types** (in-scope phase marked):
- `SALE_RECEIPT` — standard sale *(receipt-chain rebuild)*
- `SALE_VOID`, `SALE_CORRECTION` — compensations to a sale *(later)*
- `REFUND_RECEIPT`, `PARTIAL_REFUND`, `RETURN_WITHOUT_RECEIPT` — refunds *(later)*
- `ACCOUNT_PAYMENT` — money received toward a customer balance *(first slice)*
- `ACCOUNT_CHARGE` — AR created at a sale (charge-to-account) *(later phase)*
- `ACCOUNT_REFUND` — refund of an `ACCOUNT_PAYMENT` *(later)*
- `ACCOUNT_CREDIT_ISSUE`, `ACCOUNT_CREDIT_USAGE` — store credit / wallet *(later)*
- `DEPOSIT_RECEIPT` — deposit toward a specific future sale (offline-first B2C deposit — distinct from the B2B online deposit flow) *(later)*
- `OPENING_FLOAT`, `CASH_IN`, `CASH_OUT`, `SAFE_DROP`, `CASH_CORRECTION` — cash drawer *(later)*
- `SESSION_OPEN`, `SESSION_CLOSE`, `X_REPORT`, `Z_REPORT` — sessions/closures *(later)*
- `ChainBreakDetected`, `ChainRestart` — chain-recovery incidents *(first slice — §7)*
- `REPRINT_COPY` — reprint audit entry (non-fiscal-event audit log) *(later)*

**Printable types:**
- `SALE_RECEIPT`, `REFUND_RECEIPT`, `PARTIAL_REFUND`
- `ACCOUNT_PAYMENT_RECEIPT`, `ACCOUNT_REFUND_RECEIPT`
- `DEPOSIT_RECEIPT`, `STORE_CREDIT_RECEIPT`, `STORE_CREDIT_USAGE_RECEIPT`
- `CASH_IN_SLIP`, `CASH_OUT_SLIP`, `OPENING_FLOAT_SLIP`, `SAFE_DROP_SLIP`
- `X_REPORT`, `Z_REPORT`
- `REPRINT_COPY`
- **Not fiscal:** card-terminal slips (payment-processor acknowledgements), kitchen/preparation tickets (operational), quotes/estimates/pro-forma (editable, non-sequential).

---

## Appendix B — Genesis seed lifecycle

The terminal genesis seed is a fiscal-lifecycle concern, not an implementation detail.

- **Generation** — high-entropy random, generated server-side at terminal provisioning, issued once to the device.
- **Storage** — held by the device (encrypted at rest, existing AES-GCM file-key crypto) and mirrored server-side.
- **Use** — the `previous_hash` of the first fiscal event on the terminal.
- **Replacement / terminal change** — a hardware or software change that resets counters requires a documented new genesis; the **old chain head and counters must be archived and secured** before the new chain begins (BOFiP requires this).
- **Decommissioning** — the terminal's chain is sealed and archived; the genesis and final chain head are retained for the conservation period.
- **Compromise** — a suspected seed/key compromise is an incident: the chain is closed with a recorded incident event, a new genesis is issued, and the two chains are forensically linked (provenance — same as §7's `ChainRestart`).
- **No silent reset** — a new genesis is always an attributed, recorded event, never a silent config change.

---

**End of source-of-truth document v2.**
