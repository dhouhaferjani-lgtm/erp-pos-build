# Offline-First POS Fiscal Architecture — Source of Truth (v3)

**Created:** 2026-05-14
**Status:** Authoritative — **LOCKED**. Codex v3 assessment: SOUND-WITH-CORRECTIONS (0 CRITICAL / 0 MAJOR / 3 MINOR); the 3 minor wording corrections from that assessment are applied in this revision. The single grounding reference for the offline-first POS fiscal architecture and NF525 posture. Every spec, plan, and Codex review prompt must be consistent with it. Where any prior spec conflicts, **this document wins.**
**Supersedes:** v1 (`2026-05-14-offline-first-fiscal-source-of-truth.md`) and v2 (`...-v2.md`). v1 → Codex UNSOUND (4 CRITICAL/8 MAJOR/3 MINOR). v2 → Codex MAJOR-REVISION-NEEDED (0 CRITICAL/7 MAJOR/2 MINOR), all 4 CRITICALs resolved, compliance facts independently re-verified. v3 resolves the v2 MAJORs/MINORs and incorporates primary-source Tunisian research. v1/v2 + their assessments remain as audit history.
**Amendment 2026-05-14:** §13 guardrail 6 and D16 added — the bounded-modules / composable-activation guardrail, carried in roadmap v1 and lost in the architecture pivot, is restored, and refined the same day with the inbound-reference-data / outbound-operational-dependency distinction. Additive; reopens no locked decision.

**Inputs consolidated here:** the two owner strategy docs; the codebase reality audit; the receipt-chain client/server trace; the receipt-chain clean-rebuild scoping map; the NF525 + comparable-regime research; the German-TSE signature research; the integrity-mismatch / cross-component-hash / chain-recovery research; the Tunisian fiscal-software research; the Codex v1 and v2 assessments; locked owner decisions through 2026-05-14.

---

## 1. Core architectural principle

**There is one fiscal pattern. The offline-first POS device is the fiscal source of truth. The server is a verify-only mirror. The server never re-authors, never re-serializes a fiscal payload for hashing, never recomputes-and-replaces.**

Locked. Required by the use case (African markets, flaky internet — the device must operate for days without the server) and matching dominant compliant practice (§10).

**Single pattern, no exceptions.** The existing receipt chain — currently server-recompute (server-authority), the source of the production chain-breaks — is **rebuilt clean** on this same pattern (§12). No two-model coexistence.

**No migration — owner decision, to be confirmed by a preflight gate.** The owner has decided this is a clean rebuild with no data migration, on the basis that no live fiscal data exists. *That "no live fiscal data" condition is an operational fact, not a codebase-verified one.* Before any destructive rebuild action, a **mandatory preflight verification gate** applies: query every staging/production tenant database for `pos_receipts`, `offline_receipts`, `z_reports`, terminal chain state, and receipt-print records; require written owner sign-off that the environments are empty; if any fiscal data is found, fall back to an archival/export path before proceeding. The clean-rebuild *decision* is locked; the *precondition* is verified, not assumed.

### 1.1 The device authors; the server verifies

- The **device** (Tauri/SQLite): authors every fiscal event, canonically serializes the payload to an exact byte string, hashes it, chains it, assigns the gap-free sequence, and **seals it inalterably at the moment of capture**. The local seal is the authoritative fiscal act.
- The **server** (Laravel/PostgreSQL): receives the device's sealed events verbatim — including the canonical byte string — verifies integrity (re-hash the device's exact bytes; walk chain linkage), stores everything verbatim as a durable mirror, provides the audit/export surface. On anomaly it **flags per anomaly class** (§7) — it does not silently re-author.

### 1.2 The canonical-bytes-verbatim rule

The cross-language hashing risk (PHP and Node.js serializing the same object to different bytes) is eliminated **by construction**:

- The device serializes **once**, in TypeScript, and transmits the canonical byte string **verbatim**.
- The server stores those exact bytes and verifies by re-hashing them — `SHA-256` of an identical byte string is identical in every language. The server **never re-serializes** a fiscal payload.
- This is **analogous to the detached/unencoded-payload *principle* of RFC 7797** ("verify the bytes as received; do not re-serialize"). It is an analogy to that principle only — **Phase 1 is not JWS and inherits no authorship/signature guarantees from it.** Authorship guarantees require an actual signature provider (§4.2).

**Implementation constraints (load-bearing):**
- Canonical bytes are stored in a **binary/blob column** (`BYTEA` server-side, `TEXT` in SQLite) — **never round-tripped through a JSON/JSONB column**, which would silently re-serialize and break the hash.
- Canonical bytes are **authoritative**. Any structured payload the server holds for query/render/export is **derived server-side from the verified canonical bytes by a strict parser** (rejects duplicate keys, out-of-grammar numbers, invalid Unicode) — never accepted independently from the device.
- The device's canonical serialization must be **deterministic and JCS-conformant** (RFC 8785) and covered by **golden-vector tests**.

---

## 2. The three-layer model

From owner strategy doc 2. Three distinct, related concepts:

| Layer | What it is | Where it lives |
|---|---|---|
| **Business Document** | The domain object: products/services, quantities, prices, VAT, sale obligations, partial-payment state, customer linkage, B2B/B2C distinction. | Mutable per business rules, in the relevant domain module. |
| **Fiscal Event** | The immutable, hash-chained record of "this happened." Authored once, when an operation is finalized/sealed. | The append-only fiscal ledger (device SQLite → server mirror). |
| **Printable Representation** | A projection of one or more fiscal events into a rendering (thermal receipt, A4 PDF, email). | Generated on demand. |

One Business Document can produce multiple Fiscal Events over its life; one Fiscal Event can produce multiple Printables. **Reprinting produces no new Fiscal Event.** Business state belongs in the Business Document layer — not in fiscal event payloads or print projections.

---

## 3. The fiscal event engine

From owner strategy doc 1.

- **Append-only typed-event ledger.** Every fiscal/monetary operation is an immutable, typed event. No weakly-typed generic receipts. Nothing is modified after sealing.
- **Corrections are compensating events** referencing the original — never mutation, never soft-delete.
- **Hash chain** scoped per `(tenant_id, terminal_id)`. Each event carries `sequence_number`, `previous_hash`, `current_hash` (lowercase hex). Genesis: the terminal's genesis seed (Appendix B).
- **Sequence is continuous across fiscal years** — the year is recoverable from the business date; closure-period assignment rules are in §6.
- **Sessions and closures** — `SESSION_OPEN` → operations → `SESSION_CLOSE` → `Z_REPORT`. *(Later-phase scope; the engine carries them. The existing Z-report chain is independent of the receipt chain — §12.)*
- The **rebuilt receipt chain makes `SALE_RECEIPT` a first-class fiscal event** in the new chain — which is why no `SALE_RECEIPT_BRIDGE` is needed (D10).
- `ACCOUNT_PAYMENT` (money **received**) and `ACCOUNT_CHARGE` (AR **created** at a sale) are **distinct event types**.
- **Pattern-derived flags are NOT fiscal events** — judgements are downstream analytics, never in the chain.

Full taxonomy in **Appendix A**.

---

## 4. Integrity & signature model

The hash chain and the signature are **two orthogonal concerns.**

### 4.1 The integrity layer — always present

`HashChainIntegrityProvider` is the first and currently-only provider. It provides **sequence integrity** — chained SHA-256 over the canonical bytes makes insertion/deletion/reordering detectable. It does **not** prove authorship. This is honest, and it is adequate for the launch markets per §10 (France: BOFiP names chaining as an accepted inalterability technique; Tunisia general retail: see §10).

### 4.2 The signature layer — designed-for, not built

A future `SignatureProviderInterface` adds **authorship proof** (e.g. the German TSE: a certified module signs each event with a protected ECDSA private key). **Not built now** — Germany is not a launch market. But two schema decisions **cannot be retrofitted** later (a migration over immutable, append-only fiscal data) and **must be in the Phase 1 schema**:

1. **`signature_status` lifecycle enum** on every fiscal event: `not_required | pending | signed | failed`. A cloud TSE signs **asynchronously** — so "event created" and "event signed" are distinct states.
2. **A nullable, structured signature object**, separate from the hash-chain columns: `signature_algorithm`, `signature_value`, `signature_counter`, `signature_provider`, `signing_device_id`, `certificate_id`, `signed_payload_ref`, `time_source_value`, `time_format`, `provider_transaction_id`. All null for France/Tunisia today.

The `SignatureProviderInterface` must be **async-capable** (`sign()` may return `pending`) with provider **capability flags** (`requires_connectivity`, `signs_synchronously`, `assigns_transaction_id`) and a deferred re-signing path. The hash chain stays orthogonal — a future signature is *additive*.

**Explicitly: chained-SHA256 alone is a France/Tunisia-general-retail inalterability primitive. It is NOT Germany/TSE-ready.** And: **events created before a signature provider is active remain unsigned historical events — they are never retroactively "upgraded" to signed.** The word "signed" in this document is reserved for jurisdictions/providers where `SignatureProviderInterface` is actually active.

---

## 5. Canonical serialization & cross-component integrity

- **Serialize once, on the device.** Transmit verbatim. Store in a binary column. The server re-hashes those exact bytes — nothing more.
- **The server never re-serializes a fiscal payload for hashing.** (D2.)
- **Canonical bytes are authoritative.** The structured payload is *derived* server-side from verified canonical bytes by a strict parser, never accepted independently. On divergence, canonical bytes win; a strict-parse failure is an integrity exception (§7).
- **D2 narrowed:** PHP must not re-serialize fiscal-event payloads *for hash verification or replacement*. PHP **may** serialize *derived audit/export artifacts* (e.g. NF525 JET XML) provided they reference verified event ids/hashes and never become chain truth.
- **No attested byte-identical PHP↔JS RFC-8785 library pair exists** — which is *why* the serialize-once pattern is used rather than running a JCS library on both sides.

---

## 6. Time & clock trust model

The device system clock is **untrusted** (a cashier can roll it back).

Every fiscal event carries:
- **`event_time_device`** — device-claimed wall-clock at sealing. Useful but untrusted.
- **`sequence_number`** — the monotonic per-terminal chain sequence. **This, not the clock, is the authoritative ordering.**
- **`last_server_time_seen`** — the most recent trusted server time the device observed, stamped into the event; bounds staleness of the device's time reference.
- **`server_received_at`** — set by the server on ingestion; trusted, but only an upper bound on event time.

Rules:
- **Clock-rollback detection:** if `event_time_device` moves backward relative to a prior event on the same chain while `sequence_number` correctly increases, the event is accepted (never blocked) but **flagged** as a `time_anomaly` (§7).
- **Drift detection:** large divergence between `event_time_device` and `last_server_time_seen` / `server_received_at` raises an audit flag.
- **Normative closure-period rule:** an event's business day is assigned by the **terminal-configured fiscal timezone and session boundary** — not by `server_received_at` (which is an audit upper bound only) and not silently by raw device time. A clock anomaly **never moves an event between closure periods** without an explicit, recorded correction event. Closure manifests reference sequence ranges, not just dates.
- Honest limitation: an offline device's clock cannot be fully trusted. The mitigations are detection + flagging + treating the monotonic chain sequence as the real ordering authority.

---

## 7. Integrity exceptions & chain recovery

**Default posture: accept-and-flag, do not block the POS.** This is the regulator-sanctioned model — no regulator has a "sync mismatch must block" rule; their malfunction procedures are uniformly *continue-with-annotation*. Blocking a synced record means rejecting a transaction that physically already happened. **But "never block" is not absolute** — the policy is **per anomaly class and per jurisdiction** (v2's blanket "never block" was overbroad):

### 7.1 Per-class policy

| Class | Meaning | Severity | Handling |
|---|---|---|---|
| `canonical_hash_mismatch` | `SHA-256(canonical_bytes) ≠ current_hash` | low | almost always a serialization/version-drift bug — **accept, quarantine, annotate**; the record continues to count, flagged |
| `canonical_parse_failure` | canonical bytes hash correctly but fail the strict grammar (duplicate keys, invalid Unicode, unsupported number format, event-type schema error — §5) | low–medium | **accept, quarantine, annotate; no projection until operator/developer resolution**; the raw canonical bytes are still conserved |
| `time_anomaly` | clock rollback or excessive drift (§6) | low–medium | **accept, quarantine, annotate** |
| `sequence_gap` | gap or break in the per-terminal sequence | medium | **accept the records, but the terminal enters recorded incident mode**; the gap segment is tracked as an **explicit exception total**, not folded into clean totals silently |
| `signature_invalid` | a signature fails to verify against an attested key | high | possible tampering. In launch markets (France / Tunisia general retail) **no signature provider is active**, so this class does not arise. **In any signature-required jurisdiction, this triggers a market-specific stop / switch-to-emergency-procedure** — defined when that market is entered. |

Conflating `signature_invalid` with `canonical_hash_mismatch` would make every software bug look like fraud — they are deliberately separate classes.

### 7.2 Quarantine and exports — reconciliation, not silent exclusion

A flagged record is **always persisted** — to a **quarantine partition** of the mirror, carrying a **mandatory structured reason**, with an admin alert. **Fiscal exports must include a quarantine section and explicit reconciliation totals** — quarantined records are *real transactions* and must appear in the legal picture; they are not silently dropped from "clean totals." The export presents: clean totals, quarantine totals, and a reconciliation reconciling the two to the full set. An operator resolves each quarantined record (reclassify-with-explanation, or escalate).

### 7.3 Chain recovery

When a local chain breaks (DB corruption, a bad hash, a sequence gap), the terminal **continues operating** in a recorded `degraded` mode (a hard-stop is more dangerous fiscally — it tempts untracked off-system sales). Recovery is **two chained incident events with operator authorization evidence** — not "signed events" (no signature provider is active in the first slice; see §4.2):

- **`ChainBreakDetected`** — reason, last-good sequence + hash, the offending record.
- **`ChainRestart`** — a new genesis, references the last-good anchor, carries **operator authorization evidence** (who, when, why) and a provenance link to the prior chain.

Both events are part of the hash chain (chained, not authorship-signed). The broken segment is **never deleted** — quarantined, synced, and present in fiscal exports flagged, alongside the two incident events. When a `SignatureProviderInterface` is later active, incident events in that jurisdiction additionally carry a signature; pre-signature incident events remain unsigned historical records.

---

## 8. Durability & device loss

In a device-authority architecture, a terminal lost/stolen/destroyed **before sync** means the only authoritative copy of those records is gone — and fiscal law requires multi-year conservation (Tunisia: 10 years, §10). **An on-device backup encrypted with an on-device key is NOT a conservation control** — if the device is destroyed, backup and key are both gone; if stolen, the attacker may get both. v2's §8 was insufficient on this point.

Mandatory controls:
- **At least one off-device durability path** — an encrypted removable archive with a **separately-held recovery key**, and/or LAN peer replication / local NAS, and/or periodic cloud sync when connectivity is available. Key custody is **separate from the terminal disk**.
- The **on-device AES-GCM copy** (existing `.izipos_key` file-key crypto) is **crash-recovery only** — explicitly *not* the conservation answer.
- **Operator-visible unsynced-risk indicator** — the operator always sees how much fiscal data is unsynced and for how long.
- **Forced archive/export** when offline beyond a configured threshold — produces a portable encrypted archive of the unsynced segment to the off-device path.
- **Maximum-unsynced threshold** — beyond it, escalation (operator warning; configurable harder gates).
- **Incident register** — device loss/failure is a recordable incident with a declaration/recovery procedure.

NF525 sets no explicit maximum offline duration; we build to the strict reading — seal-on-capture + forced periodic off-device archive.

---

## 9. Multi-terminal / company-level integrity

Chains are per-`(tenant_id, terminal_id)` — a company with N terminals has N independent chains. Per-chain verification alone cannot tell an auditor whether a terminal, a day, or a location is missing from the company export. The company-level layer must therefore be **itself immutable**, not a set of mutable database reports:

- **`TERMINAL_REGISTRY_SNAPSHOT`** — an **immutable, chained** record: the authoritative list of every terminal expected for a company at a point in time. Carries a hash, links to the prior snapshot.
- **`COMPANY_DAY_CLOSURE_MANIFEST`** — an **immutable, chained** record per business day: every expected terminal chain head, explicit missing-terminal / offline exceptions, preparer/approver, the §6 timestamp model, a hash, and a link to the prior manifest.
- **Company grand-total rollups** — derived from per-terminal closures and the day manifest.
- **Export verification** checks **both** per-chain integrity **and** fleet completeness (no terminal/day silently absent) — using the immutable manifests as the completeness authority.

These are fiscal/technical events in their own right (Appendix A), or certified archive records — never plain regenerable reports.

---

## 10. Why this is compliant — NF525, Tunisia, comparable regimes

- **NF525 is architecture-agnostic at the legal-text level.** BOFiP (`BOI-TVA-DECLA-30-10-30`, current) defines no imposed technical solution; a system must satisfy inalterability, security, conservation, archiving. Chaining is **explicitly named** as an acceptable reliable technique. BOFiP permits centralizer **conservation** — not centralizer **authoring**. Device-authoring + server-verify-and-archive is within what NF525 contemplates.
- **NF525 certification status (verified, current).** Conformity may be justified by **either** an accredited certificate **or** an individual publisher attestation. Law 2026-103 Article 125 amended CGI Article 286 to restore the attestation route (effective 2026-02-21); the previously-planned 2026-09-01 removal of self-certification was **cancelled**. This area has moved twice — a live watch item.
- **Tunisia — and this is a real launch-market boundary, not a footnote.** Tunisian primary-source research (2026-05-14) establishes:
  - **General B2C retail of goods (the canonical use case — para-pharmacies, IziPOS-style shops): the regime is light.** No device-certification obligation; B2C retail goods sales are **out of scope of TTN / El Fatoora e-invoicing**. A locally-sealed chained-SHA256 journal — **plus Article 18 Code de la TVA gap-less uninterrupted receipt numbering, nominative-facture-on-request, daily global invoice from register records, and 10-year retention (CDPF)** — is **broadly adequate for general retailers today.**
  - **On-site food service (restaurants, cafés, tea-rooms): NOT served by this architecture.** Tunisia stood up a device-level fiscal-cash-register regime (*arrêté* in JORT n°125, 14 Oct 2025) requiring **homologated software from accredited vendors with real-time central-system integration** and QR-coded tickets — Phase 1 in force since 1 Nov 2025. **An offline-first, verify-only-mirror model structurally cannot satisfy a regime whose central platform is the authority.** Food-service merchants are out of scope for this product as architected.
  - **Trajectory:** the on-site-consumption cash-register regime widens **by taxpayer category** through Jul 2028 — but the later phases still refer back to the same Article 1 scope (on-site food/drink consumption-service providers). This is **progressive inclusion within food service, not a sector-agnostic expansion to general retail**; a separate future retail expansion remains possible but is **not** established by JORT n°125. Treat homologation capability as a watch item, not an established roadmap obligation for general retail.
  - **Tracked gaps:** the `cahier des charges` technical text is non-public (the exact integrity mechanism it mandates is unverified); the precise enabling Finance Law article and CDPF retention article were not isolatable from primary sources — verify with a Tunisian fiscal advisor before a Tunisian go-live.
  - **Service lines:** a tenant that also provides VAT-liable *services* **may** require TTN/El Fatoora e-invoicing for its service invoices under LF 2026 art. 53 — but **effective timing is under active legislative deferral** (the ARP Finance Committee approved a direction on 2026-04-02 to defer art. 53's effective application while preserving the e-invoicing principle). Treat as volatile; verify before any Tunisian go-live. Either way it is a separate integration from the POS fiscal chain.
- **Posture, stated correctly.** Device/local authoring is **compatible with** offline fiscalization across the relevant regimes — not "the dominant compliant pattern across every regime." Each jurisdiction imposes additional controls: France — ISCA proof; Germany — a certified TSE which chained-SHA256 alone does not satisfy; Italy — RT / web-procedure transmission; Tunisia food service — homologated software + central-system integration. A per-jurisdiction provider-readiness matrix is future work; `SignatureProviderInterface` is the abstraction that absorbs signature-based regimes.
- **Seal-on-capture** is a *safe interpretation*, not a sourced regulatory phrase — we build to the strict reading because it is defensible.

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
| Flag integrity exceptions | Local detection available | **Yes — quarantine + alert, per anomaly class (§7); never silently re-author** |
| Re-author / recompute-and-replace / mutate | Compensating events only | **Never** |

---

## 12. The clean rebuild — the existing receipt chain

The existing receipt chain server-recomputes-and-overwrites (server-authority) and throws-and-rolls-back on hash mismatch — the source of the production chain-breaks. It is **discarded** and rebuilt clean on the §1 pattern. **No migration**, subject to the §1 preflight verification gate.

The receipt-chain clean-rebuild scoping audit (2026-05-14) classified the work — *the percentages below are that audit's estimate, not a hard codebase fact, and have been adjusted for the JET correction below:*

- **REUSE** — client-side authoring/sealing (already correct: `receiptService.ts`, `terminal_state`, `offline_receipts`), the sync batch orchestration + stock/voucher/payment logic in `ReceiptSyncService`, chain-state persistence, the `Compliance/FiscalHashService` chain-prefix logic, and the **NF525 JET XML *builder*** (`Nf525XmlBuilder` — serializes DTOs to XML; that is permitted derived-artifact serialization, §5/D2).
- **REWORK** — `ReceiptFinalizationService` and `ReceiptHashService::verifyTerminalChain()` / `verifyHash()` move from recompute-from-structured-models to **re-hash the stored canonical bytes**. The `ReceiptSyncService` hash-mismatch branch moves from **throw + roll back** to **per-class accept/flag** (§7). **Correction to the prior scoping audit:** the JET *data provider* (`Nf525DataProvider`) is **rework, not reuse** — it currently reads `Receipt` models directly and verifies chains via `ReceiptHashService::calculateHash()` (server-recompute); it must be reworked to read verified canonical bytes, quarantine state, integrity exceptions, and company manifests. The JET *verification path* is rework; only the XML *builder* is reuse.
- **DISCARD** — the server recompute-and-overwrite path for receipts; the `OfflineFiscalHashMismatchException` throw-and-rollback behavior (the class may remain as an audit-trail artifact but is never thrown to block).
- **NEW** — a `canonical_bytes` column (`BYTEA` on `pos_receipts`, `TEXT` on `offline_receipts`); the client storing + transmitting its canonical bytes; the per-class integrity-exception / quarantine path; the `signature_status` + signature object columns (§4); JET export sections for quarantine records, chain-restart incidents, and company manifests.
- **Z-report chain** is **independent** of the receipt chain — it also server-recomputes today and needs the same clean-rebuild treatment as a **separate later task**, not part of the receipt-chain rebuild.

The detailed touch-point map is the 2026-05-14 scoping audit (with the `Nf525DataProvider` correction above); it feeds the phase plan.

---

## 13. Cross-cutting guardrails

Permanent. Any spec or plan that violates them is wrong.

1. **B2B and B2C are distinct — never conflate.** The sale application is B2C-primary; its flows/fiscal-treatment/data-model are distinct from the B2B web flows. The POS needs **parity** functionality for B2C client accounts — not unverified reuse of B2B web mechanisms.
2. **Web and offline-first are distinct — never conflate.** The sale app's source of truth is its local ledger; the server reconciles *to* it.
3. **Every offline-first operation carries a reconciliation classification** — `offline_authoritative` (default for fiscal events), `server_reconciles`, or `block_unless_online`.
4. **Fiscal events are factual** — judgements are downstream analytics, never in the chain.
5. **No unverified codebase or operational claims** — every spec's codebase claim traces to the codebase reality audit or this document; operational facts (e.g. "no live fiscal data") are flagged as decisions-to-be-verified, not asserted as established.
6. **Bounded modules compose; the POS fiscal engine has zero hard dependency on any other module's operational runtime.** The POS is a standalone bounded module — the fiscal event engine, its `SALE_RECEIPT` / `ACCOUNT_PAYMENT` events, the `pos_receipts` projections, the `ReceiptPayment` rows (the POS's own payment record), vouchers, stock, receipts, and the compliance/JET export all live in it. **The dependency is asymmetric, and the direction is what matters:**
   - **Inbound — setup / reference data is mirrored and may be referenced (permitted).** Payment methods, tenders, payment repositories, products, and similar reference data are defined once in the web during setup and synced down to the POS local mirror; the POS already operates offline-first against that mirror. A POS record referencing this mirrored reference data (e.g. `ReceiptPayment.payment_method_id`) is a **permitted inbound setup dependency** — it is *not* a dependency on another module's operational runtime, and it does not block a POS-only deployment (the reference data is present in the mirror regardless of which operational modules are active).
   - **Outbound — the fiscal engine depends on no other module's operations (forbidden to couple).** The fiscal event engine, the authoring / sealing / chain, and the server ingestion path must have **zero** dependency on the Treasury operational module (Treasury `Payment` rows, GL postings, `PaymentAllocationService` / FIFO allocation), on accounting, or on the B2B sales module. The engine *publishes* fiscal events; each active module *consumes / projects* them via a pluggable integration boundary — a projector registry resolved per `(tenant, company)` — the engine never imports, calls, or couples to those modules' operations. Absent an active consuming module, the published events stand as the POS's own records and local statistics.

   **A POS-only shop is a supported deployment;** its transactions are viewable through the existing web shop-management (POS) section as a read projection over the synced server mirror. **Treasury / accounting composes in for operational reasons** — supplier POs, expenses, treasury tracking, year-end accounting — which many POS-only shops need *independently of B2B*; it owns the Treasury `Payment` rows, GL postings, and FIFO allocation, captured from the published events via its bridge. **The B2B sales module composes in** for B2B + B2C unification. Concretely, the receipt business projection is a **POS-core projection that always runs** plus **pluggable module bridges** (the Treasury bridge runs only when Treasury is active). A spec that makes the fiscal engine or its ingestion path depend on Treasury `Payment` rows, GL postings, or B2B mechanisms is wrong; a spec that forbids the POS from referencing mirrored setup reference data is also wrong. *(Carried in roadmap v1, lost in the architecture pivot, restored 2026-05-14; refined 2026-05-14 with the inbound-reference-data / outbound-operational-dependency distinction.)*

---

## 14. Locked decisions

| # | Decision |
|---|---|
| D1 | Device-authority: the offline-first POS device is the fiscal source of truth; the server is a verify-only mirror. Paired with the §6 clock, §7 per-class exception/recovery, §8 durability, §9 company-integrity controls. |
| D2 | The server never re-serializes a fiscal-event payload **for hash verification or replacement**. PHP **may** serialize derived audit/export artifacts (JET XML, etc.) that reference verified ids/hashes and never become chain truth. |
| D3 | Canonical bytes are produced once on the device, transmitted verbatim, stored in a binary column, and are authoritative. The structured payload is derived server-side by a strict parser, never accepted independently from the device. |
| D4 | The fiscal layer is an append-only, typed-event, hash-chained ledger. Corrections are compensating events. |
| D5 | The three-layer model is canonical: Business Document / Fiscal Event / Printable Representation. |
| D6 | `ACCOUNT_PAYMENT` (money received) and `ACCOUNT_CHARGE` (AR created at sale) are distinct event types. |
| D7 | The rebuild reuses the client-side patterns and the conceptually-sound server orchestration; it does **not** reuse the server recompute/verify code or the JET data-provider/verification path — those are rework. See §12. |
| D8 | **There is one fiscal pattern.** The existing receipt chain is rebuilt clean on it — no two-model coexistence. No migration, subject to the §1 preflight verification gate. |
| D9 | Charge-to-account does not exist in the POS today and is later-phase work. |
| D10 | First go-live slice = the fiscal event engine + `ACCOUNT_PAYMENT` + minimum customer attach + server mirror + `ACCOUNT_PAYMENT_RECEIPT` printout. The foundation phase **includes** the receipt-chain clean rebuild. The rebuilt receipt chain makes `SALE_RECEIPT` a first-class fiscal event — so no `SALE_RECEIPT_BRIDGE` is needed. |
| D11 | The §13 cross-cutting guardrails are permanent. |
| D12 | The first integrity provider is `HashChainIntegrityProvider` — it proves sequence integrity, not authorship. The Phase 1 schema includes the non-retrofittable `signature_status` enum and nullable signature object; `SignatureProviderInterface` is async-capable. TSE/Germany is design-for, not build-now. "Signed" is reserved for jurisdictions with an active signature provider; pre-signature events are never retroactively upgraded. |
| D13 | Integrity anomalies are handled **per anomaly class** (§7.1): `canonical_hash_mismatch` and `time_anomaly` → accept-and-flag; `sequence_gap` → accept + incident mode + explicit exception totals; `signature_invalid` → market-specific stop/emergency in signature-required jurisdictions (does not arise in launch markets). Quarantined records appear in exports as a reconciliation section — never silently excluded. Chain recovery is two **chained incident events** with operator authorization evidence. |
| D14 | Partial payments use **Option B** — separate `SALE_RECEIPT` + `ACCOUNT_PAYMENT_RECEIPT` — unless a later explicit owner decision overrides it. |
| D15 | This product, as architected (offline-first, verify-only-mirror), serves **general B2C retail** in the launch markets. It does **not** serve merchants subject to a central-system-integration fiscal regime — notably Tunisian on-site food service (JORT n°125). Such segments are out of scope unless/until a central-integration capability is added. |
| D16 | **Bounded modules compose; the POS fiscal engine has zero hard dependency on any other module's operational runtime.** The dependency is asymmetric: *inbound* setup/reference data (payment methods, tenders, repositories, products) is defined in web setup, mirrored to the POS, and may be referenced — permitted; *outbound*, the fiscal engine + authoring/sealing/chain + ingestion path depend on no other module's operations (Treasury `Payment`/GL/allocation, accounting, B2B sales) — forbidden. The engine publishes fiscal events; Treasury/accounting and the B2B sales module each consume via a pluggable projector bridge resolved per `(tenant, company)` and bound only when active. The receipt business projection = a POS-core projection (always runs) + pluggable module bridges (Treasury bridge only when Treasury is active). POS-only is a supported standalone deployment. (§13.6) |

---

## 15. Open questions / watch items

1. **§1 preflight verification gate** — must be executed (query staging/prod tenant DBs; written owner sign-off) before any destructive rebuild action. Tracked as a hard gate, not a note.
2. **NF525 certification status is volatile** — French rules moved twice; re-verify before any French go-live.
3. **Tunisia `cahier des charges` text is non-public** — the exact integrity mechanism the fiscal-cash-register regime mandates is unverified; obtain from `homologation.nacef.tn` and confirm with a Tunisian fiscal advisor before any food-service or post-2027 Tunisian engagement.
4. **Z-report chain rebuild** — independent of the receipt-chain rebuild; needs the same clean-rebuild treatment as a separate later task.
5. **Existing `MultiPaymentService::createOnAccountPayment()`** — a B2B-web on-account path already exists; the POS B2C on-account flow is parity work, reconciled at the data/reporting layer eventually.
6. **Per-jurisdiction provider-readiness matrix** — when markets beyond France/Tunisia-general-retail approach.
7. **Tunisia LF 2026 art. 53 (service e-invoicing) is under active legislative deferral** — like NF525 certification status, treat as volatile; re-verify effective timing and transition rules before any Tunisian go-live involving service lines.

---

## 16. Document control

- Supersedes v1, v2, and the architectural-placement assumptions in the v1/v1.1/v2.0/Phase-1-draft specs. Those remain as audit history.
- Complements the codebase reality audit and the multi-phase roadmap; the roadmap's cross-cutting principles are restated and locked here in §13.
- Every future phase spec opens by referencing this document and the codebase reality audit, and must be consistent with both.
- Every future Codex review prompt must instruct the reviewer to ground findings in this document and the two owner strategy documents.
- **2026-05-14 amendment:** §13 guardrail 6 + D16 restore the bounded-modules / composable-activation guardrail (roadmap v1 origin, lost in the architecture pivot); refined the same day to distinguish permitted inbound reference-data dependency from forbidden outbound operational-module dependency. Additive; reopens nothing.

---

## Appendix A — Canonical event-type and printable-type taxonomy

**Fiscal event types** (in-scope phase marked):
- `SALE_RECEIPT` — standard sale; first-class fiscal event in the rebuilt chain *(receipt-chain rebuild)*
- `SALE_VOID`, `SALE_CORRECTION` — compensations to a sale *(later)*
- `REFUND_RECEIPT`, `PARTIAL_REFUND`, `RETURN_WITHOUT_RECEIPT` — refunds *(later)*
- `ACCOUNT_PAYMENT` — money received toward a customer balance *(first slice)*
- `ACCOUNT_CHARGE` — AR created at a sale (charge-to-account) *(later phase)*
- `ACCOUNT_REFUND` — refund of an `ACCOUNT_PAYMENT` *(later)*
- `ACCOUNT_CREDIT_ISSUE`, `ACCOUNT_CREDIT_USAGE` — store credit / wallet *(later)*
- `DEPOSIT_RECEIPT` — deposit toward a specific future sale (offline-first B2C deposit — distinct from the B2B online deposit flow) *(later)*
- `OPENING_FLOAT`, `CASH_IN`, `CASH_OUT`, `SAFE_DROP`, `CASH_CORRECTION` — cash drawer *(later)*
- `SESSION_OPEN`, `SESSION_CLOSE`, `X_REPORT`, `Z_REPORT` — sessions/closures *(later)*
- `CHAIN_BREAK_DETECTED`, `CHAIN_RESTART` — chained incident events *(first slice — §7.3)*
- `TERMINAL_REGISTRY_SNAPSHOT`, `COMPANY_DAY_CLOSURE_MANIFEST` — immutable company-integrity records *(§9; first slice for the engine-level structure, populated as terminals/closures come online)*
- `REPRINT_COPY` — reprint audit entry *(later)*

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

- **Generation** — high-entropy random, generated server-side at terminal provisioning, issued once to the device.
- **Storage** — held by the device (encrypted at rest) and mirrored server-side.
- **Use** — the `previous_hash` of the first fiscal event on the terminal.
- **Replacement / terminal change** — a hardware/software change that resets counters requires a documented new genesis; the **old chain head and counters must be archived and secured** before the new chain begins.
- **Decommissioning** — the terminal's chain is sealed and archived; the genesis and final chain head retained for the conservation period.
- **Compromise** — a suspected seed/key compromise is an incident: the chain is closed with a recorded incident event, a new genesis is issued, the two chains are forensically linked (provenance).
- **No silent reset** — a new genesis is always an attributed, recorded event, never a silent config change.

---

**End of source-of-truth document v3.**
