# POS Customer Accounts + Fiscal Event Engine — Multi-Session Roadmap

**Created:** 2026-05-14
**Purpose:** This work spans multiple sessions and multiple specs. This roadmap is the durable index so no session loses context. Read this first when picking the work back up.

---

## 1. Where we are

The original ask: bring B2C customer-account flows (search, create, on-account payment, charge-to-account, balances) to the offline-first POS desktop app, the way the B2B web flow already supports company accounts.

History:
- **v1** spec — receipt-centric design → Codex round 1: **BLOCK** (3 BLOCKER, architectural).
- **v1.1** spec — receipt-variant simplification → Codex round 2: **BLOCK** (4 BLOCKER, simplifications fight the schema).
- **v2.0** spec — pivot to event-sourced fiscal ledger (per the two architecture strategy docs) → Codex round 3: **BLOCK** (4 BLOCKER) — but the architecture is now **accepted**; 7 of 13 prior findings resolved; the BLOCKERs are concrete codebase conflicts, not architectural.
- **Decision (2026-05-14):** stop spec-iterating the full scope. (a) Do a codebase reality audit to break the recurring knowledge-gap cycle. (b) Narrow each spec to one phase. (c) Re-spec phase by phase, grounded in verified facts.

---

## 2. Cross-cutting principles (permanent guardrails)

These apply to **every** phase. Any spec or plan that violates them is wrong.

### 2.1 B2B and B2C are distinct — never conflate

- The **sale application** (Tauri POS) is **B2C-primary**. It may also serve B2B customers, but its flows, fiscal treatment, and data model are distinct from the **B2B web flows**.
- B2B web flows already exist (Treasury `Payment`, `Document` invoices, `PaymentAllocationService`, smart allocation). Do **not** assume a B2B web mechanism is reusable for the POS B2C path without verifying it.
- Concrete example: there is a **deposit** in the sale application that is **offline-first**; this is a different feature from the **online deposit flow**, which is mainly a B2B flow. Same word, different feature, different fiscal handling, different store.
- When a flow "also serves B2B" inside the sale app (e.g., a B2B customer paying on account at the till), it is still a **POS flow**, not the web B2B flow — it uses the POS fiscal pipeline.

### 2.2 Web and offline-first are distinct — never conflate

- Web flows assume connectivity and a live server as source of truth.
- Offline-first flows (the sale app) assume the **local SQLite ledger may be the source of truth**, with the server reconciling to it — not the other way around.
- Do not specify a flow that silently assumes server availability inside the offline-first app.

### 2.3 Every offline-first operation carries a reconciliation / risk classification

For each operation in the sale app, the spec must state its offline classification:

- **`block_unless_online`** — the operation is too risky to perform on stale data; the POS refuses it offline and tells the operator to reconnect. (Examples likely: refund-customer-credit-as-cash, AML-hard-cap cash acceptance.)
- **`offline_authoritative`** — for the single-tenant / single-terminal / single-company deployment with flaky internet, the offline app **is the source of truth** for this operation; when connectivity returns, **online reconciles to offline**, not the reverse. This is the target model for African flaky-internet markets where the POS is the primary place of work. (Examples likely: sale receipts, on-account payments, cash drawer movements.)
- **`server_reconciles`** — the operation proceeds offline against last-known state; on sync, the server recomputes the authoritative result and emits a reconciliation event; the offline record stays valid but a server-side projection may differ. (Examples likely: on-account payment allocation across invoices.)

The `offline_authoritative` model is **introduced later**, not in the first phases — but the architecture must not foreclose it. The target use case: African countries with flaky internet, where the offline-first sale app is the merchant's main workplace and must operate regardless of connectivity.

### 2.4 Fiscal events are factual; pattern-derived flags are downstream

The fiscal event chain records what happened. It does NOT record judgements ("suspicious"). Pattern detection (fraud heuristics, anomaly flags) is ML/analytics work downstream of the event stream in TimescaleDB, emitting to a separate analytic store. Never wire a heuristic into the fiscal chain.

### 2.5 No conflated claims about the codebase

Three Codex rounds blocked partly because specs claimed things about the codebase that weren't true ("ReceiptPaymentService is unchanged", "use V3", "atomic with Treasury.Payment write offline", "port TaxIdValidationService"). Every implementation claim in a spec must be traceable to the codebase reality doc (§5 artifact index) or explicitly flagged as "to verify."

---

## 3. Phase breakdown

Two main phases, two-to-three more. Phases 3+ will be refined after Phase 1-2 ship and teach us the codebase truth.

### Phase 1 — Fiscal Event Engine (foundation)

**Scope:** the `fiscal_events` table, `FiscalEventEngine` service (emit + verify + project), the signature provider abstraction, the bridge mechanism for legacy POS receipts, the chain verifier command, the typed event-payload DTO layer, the canonical-serialization contract, the sync transport for fiscal events.

**Does NOT include:** any customer-facing feature. This is pure foundation.

**Must address (from Codex round 3):**
- B2 — rename the new signature provider so it does not collide with the existing receipt V3 (`FiscalEventV1SignatureProvider`, not "V3").
- P1.1 — bridge append must run inside `ReceiptFinalizationService`'s existing transaction, after hash computation, before terminal save; separate chain columns for receipt vs fiscal-event; rollback test.
- P1.2 — immutability trigger must also block TRUNCATE; revoke TRUNCATE from app roles; break-glass procedure.
- P1.6 — declare `fiscal_events` the source of truth for in-scope fiscal facts; Spatie `stored_events` + `audit_events` stay domain/audit only; synchronous bridge contract with a no-duplicate constraint.
- P1.7 — add nullable `Payment.origin` + `Payment.fiscal_event_id` columns + `PaymentOrigin` enum; update every Payment writer in the same PR.
- P2.1 — typed `FiscalEventPayload` DTOs per event type; PHP enums for event_type etc.; generated TypeScript.
- P2.2 — define the canonical payload value grammar (no floats, integer cents, UTC string timestamps, normalized free text, sorted arrays); adopt or extend a JCS implementation; cross-language golden vectors.
- P2.3 — name `OutboxIngestor` as a concrete new class + route; state its relationship to the existing `/pos/receipts/sync` + `ReceiptSyncService`.
- P3.1 — rename the sale-side AR-creation event so it is not confused with money-received (`ACCOUNT_CHARGE` / `AR_CHARGE_CREATED` vs `ACCOUNT_PAYMENT`).

### Phase 2 — Customer Management + On-Account Payment (B2C, offline-first)

**Scope:** Customers tab in the POS (search, create, edit), the offline-first `ACCOUNT_PAYMENT` event (money received toward existing balance, no goods), projection to the printable `ACCOUNT_PAYMENT_RECEIPT`, the customer mirror sync, the identity-map basics.

**Must address (from Codex round 3):**
- B3 — offline `ACCOUNT_PAYMENT` is a sealed local fiscal event + cash-drawer local state only; the server creates the Treasury `Payment` + allocations as an idempotent projection on ingestion; local `account_payment_projection` is UI-estimate-only, non-authoritative. Remove "atomic with Treasury.Payment write" from offline steps.
- P1.8 — split `NationalIdentityValidationService` from `TaxIdValidationService`; tax-ID validation is B2B-only; B2C national-ID (CIN-TN, CNI-FR) validation is its own thing (or AML for B2C is deferred to a later phase if national-ID validation is not feasible offline).
- P2.5 — projection versioning: store `first_printed_projection_version` + `active_projection_version`; reprints of historical documents use the original payload with "copie" metadata.
- P2.6 — `partner_alias_closure` table with cycle check; balance aggregation follows the closure, not a naive chain.
- Offline classification (per §2.3) for each operation: customer search = read-only; customer create = `server_reconciles` (dedup on sync); on-account payment = `server_reconciles` (allocation) with the sealed local event being durable.

### Phase 3 — Charge-to-Account (settlement vs payment refactor, B2B Facture routing)

**Scope:** the at-till charge-to-account flow — sale settled partly or wholly by debit to the customer's account.

**Must address (from Codex rounds 2-3):**
- B1 / R1 — split receipt settlement from tender payments. `pos_receipt_payments` keeps real inflows only; a typed `pos_receipt_settlements` (or equivalent) carries `on_account`. One composite GL posting service: Cash debit + AR debit + Revenue/VAT credit, posted once. Do not route the AR draw through `ReceiptPaymentService` (which creates a Treasury Payment + direct-to-revenue GL per row).
- B4 / R7 — explicit branch before fiscal seal: if `partner_kind = Business` (or a tax ID is present/requested in jurisdictions like Tunisia), route through the B2B `Facture` document flow, not the POS ticket path. POS ticket path is for anonymous/ordinary B2C unless legal counsel signs off.
- Rules engine (tier-3: tenant default → per-partner override → per-transaction override), min-deposit %, credit-limit hard cap.

### Phase 4 — Account Status Workflow + Overrides + Cash-Out + Approval Primitive

**Scope:** `account_status` lifecycle (Active/Frozen/Closed/PendingKyc), the `OVERRIDE_AUDITED` and `CASH_OUT_EXECUTED` events, the approval primitive (LocalPin + RemotePush stub), the `cash_out_policy` table, the virtual admin terminal.

**Must address (from Codex round 3):**
- P1.3 — fiscal-event ingestion must be a **semantic validator** for `OVERRIDE_AUDITED`, not just a chain verifier: re-run the override mapping server-side (allowed channel, offline policy, approver permission at snapshot version, signed snapshot validity).
- P1.4 — virtual admin terminal: add `TerminalType::VirtualAdmin` or a separate `fiscal_channels` table; `UNIQUE (tenant_id, company_id, type='virtual_admin')`; provisioning path; signed genesis seed at tenant provisioning.
- P1.5 — cross-chain ordering is a **reconciliation read model with uncertainty**, not a hash-chain invariant. Add `origin_emitted_at`, `server_received_at`, `server_committed_sequence`, `terminal_clock_offset_estimate`. Server-side compensating review when an offline charge syncs after a freeze commit.
- P2.4 — permission-snapshot TTL must not rely on the untrusted terminal clock: anchor to `last_server_seen_at` + monotonic tick; reject on clock-rollback; queue tamper alerts.
- The override approval matrix, per-override-type fallback policy, the `ManagerPinController` refactor.

### Phase 5 (candidate — may merge into 3/4 or stand alone) — Deposits + Identity Reconciliation + AML

**Scope:** the offline-first **deposit** in the sale app (`DEPOSIT_RECEIPT` event — explicitly the B2C offline-first deposit, NOT the B2B online deposit flow; see §2.1), full identity-map reconciliation (`IDENTITY_ALIAS_RECONCILED`, manual-review queue), the AML state machine, store-credit events (`ACCOUNT_CREDIT_ISSUE` / `ACCOUNT_CREDIT_USAGE`).

To be scoped properly after Phases 1-2. May be split or merged.

---

## 4. Deferred / out-of-scope (tracked, not lost)

- Full migration of legacy `SALE_RECEIPT` from `pos_receipts` into `fiscal_events` (bridge mechanism holds the line until then).
- Cash-drawer events as fiscal events (`OPENING_FLOAT`, `CASH_IN`, `CASH_OUT`, `SAFE_DROP`).
- Session events as fiscal events (`SESSION_OPEN`, `SESSION_CLOSE`, `X_REPORT`, `Z_REPORT`).
- B2B document events into the engine (`InvoicePosted` etc. — the 9 existing `getHashableData()` stubs).
- NF525 certification submission.
- Country-specific signature providers (NF525-LNE, ZATCA, TTN, SAF-T).
- `offline_authoritative` reconciliation model (§2.3) — architecture must allow it; implementation is later.
- ML-downstream pattern detection / fraud flags.
- Marketplace inbound orders, delivery integration, mobile owner app.
- Body-shop fully-offline workshop module.
- Layaway-style minimum-deposit fixed-amount floor (`min_deposit_amount` placeholder reserved).
- Auto-freeze cron for delinquent accounts.

---

## 5. Artifact index

| Artifact | Path |
|---|---|
| Roadmap (this doc) | `apps/erp/docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap.md` |
| v1 spec (archived) | `apps/erp/docs/superpowers/specs/2026-05-13-pos-customer-accounts-design.md` |
| v1.1 spec (archived) | `apps/erp/docs/superpowers/specs/2026-05-13-pos-customer-accounts-design-v1.1.md` |
| v2.0 spec (architecture pivot; superseded by per-phase specs) | `apps/erp/docs/superpowers/specs/2026-05-13-pos-fiscal-event-engine-and-customer-accounts.md` |
| Codex review round 1 | `apps/erp/docs/superpowers/reviews/2026-05-13-pos-customer-accounts-codex-review.md` |
| Codex review round 2 | `apps/erp/docs/superpowers/reviews/2026-05-13-pos-customer-accounts-codex-review-round2.md` |
| Codex review round 3 | `apps/erp/docs/superpowers/reviews/2026-05-13-pos-fiscal-event-engine-codex-review-round3.md` |
| Architecture strategy — fiscal chain | `~/Downloads/fiscal_chain_architecture_strategy.md` (owner-provided) |
| Architecture strategy — printable documents | `~/Downloads/pos_printable_documents_architecture.md` (owner-provided) |
| Codebase reality audit | `apps/erp/docs/superpowers/research/2026-05-14-pos-fiscal-codebase-reality.md` (in progress) |
| Phase 1 spec | `apps/erp/docs/superpowers/specs/2026-05-14-pos-fiscal-event-engine-phase1.md` (pending) |
| Phase 2 spec | (pending) |
| Phase 3+ specs | (pending) |

---

## 6. Process for each phase

1. Confirm the phase scope against this roadmap.
2. Write the phase spec, grounded in the codebase reality doc — no unverified claims.
3. Self-review (placeholder scan, internal consistency, scope check, ambiguity check).
4. Owner review.
5. Codex adversarial review (instruct Codex to write the review to a file path, not inline).
6. Triage findings, revise.
7. Implementation plan (writing-plans skill).
8. Update this roadmap's artifact index + mark the phase status.

---

**End of roadmap.**
