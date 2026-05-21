# POS Customer Accounts + Fiscal Event Engine — Phased Plan (Roadmap v2)

**Created:** 2026-05-14
**Status:** Active. Phase 1 foundation implementation is complete through Task 33 full-flow verification (2026-05-20); Phase 1.5 cleanup remains before any Phase 2 customer-facing deployment. Supersedes roadmap v1 (`2026-05-14-pos-customer-accounts-roadmap.md`). Codex assessment 2026-05-14: MAJOR-REVISION-NEEDED (0 CRITICAL / 3 MAJOR / 3 MINOR); all 6 findings are corrected in this revision — off-device durability given a Phase 1 home, `Payment.origin`/`fiscal_event_id` moved to Phase 1, Phase 2 balance-snapshot scope added, and the 3 MINOR wording/naming fixes applied.
**Grounding (all locked / done):**
- Source-of-truth v3 — `2026-05-14-offline-first-fiscal-source-of-truth-v3.md` (Codex: SOUND-WITH-CORRECTIONS, corrections applied, LOCKED)
- Codebase reality audit — `2026-05-14-pos-fiscal-codebase-reality.md`
- Receipt-chain clean-rebuild scoping map — 2026-05-14 scoping audit (with the `Nf525DataProvider` correction recorded in source-of-truth v3 §12)
- Five primary-source research reports (NF525, German TSE, integrity/recovery, Tunisia, original B2B/POS investigation)

**What changed from roadmap v1:** the architecture is now device-authority (was mis-placed server-side); the receipt-chain clean rebuild is folded **into the foundation phase** (you cannot build the engine alongside a broken-pattern sibling); the first customer-facing slice is `ACCOUNT_PAYMENT`; all phases are now grounded in the locked source-of-truth and the scoping audit's reuse/rework/discard/new split.

---

## Locked inputs every phase inherits

- The single fiscal pattern (source-of-truth §1): device authors + seals locally; server verifies-verbatim and mirrors; canonical bytes serialized once on the device, stored in a binary column, never re-serialized server-side.
- The 16 locked decisions (source-of-truth §14).
- The cross-cutting guardrails (source-of-truth §13): B2B/B2C never conflated; web/offline never conflated; every offline operation carries a reconciliation classification; fiscal events are factual; no unverified codebase/operational claims; **bounded modules compose — the POS fiscal engine has zero hard dependency on any other module, it publishes events and Treasury/accounting + the B2B sales module consume via pluggable bridges bound only when active (§13.6, D16)**.
- The Tunisia scope boundary (D15): general B2C retail is served; central-integration-regime segments (Tunisian on-site food service) are not.
- The bounded-modules boundary (D16): POS-only is a supported standalone deployment. The dependency is **asymmetric** — *inbound* setup/reference data (payment methods, tenders, repositories, products) is defined in web setup, mirrored to the POS, and may be referenced (permitted); *outbound*, the fiscal engine + ingestion path depend on no other module's operations (forbidden). Every Treasury/accounting **operational** touchpoint in the phases below (Treasury `Payment` rows, GL, allocation) is **module-integration work** — a pluggable bridge on the projector seam, gated by the `ModuleActivationResolver`, delivered for deployments that compose Treasury in — never a hard dependency of the POS fiscal engine.

---

## Phase 1 — Foundation: Fiscal Event Engine + Receipt-Chain Clean Rebuild

The foundation. No customer-facing feature. Establishes the one pattern and rebuilds the receipt chain on it.

**Implementation status (2026-05-20):** Complete through Task 33. The Phase 1 closure test verifies a Tauri-style device-authored `SALE_RECEIPT` sync through `/api/v1/pos/sync/fiscal-events`, server ingest and parse, POS + Treasury projections, NF525 canonical export fields, and byte-equivalence across the device-sealed canonical bytes, `fiscal_events.canonical_bytes`, and `pos_receipts.canonical_bytes`.

**Scope:**
- **`fiscal_events`** table — device SQLite + server PostgreSQL mirror. Schema includes (non-retrofittable, source-of-truth §4.2): `signature_status` lifecycle enum, the nullable structured signature object, the `canonical_bytes` binary column.
- **Device-side fiscal event engine** — authoring, canonical serialization (deterministic, JCS-conformant, golden-vector tested), `HashChainIntegrityProvider`, chain-head management, atomic seal.
- **Server-side verify-only mirror** — a **new typed fiscal-event outbox endpoint and `OutboxIngestor`, distinct from `/pos/receipts/sync`** (the audit confirmed no generic fiscal-event endpoint exists today); ingest sealed events verbatim, re-hash the device's exact bytes + walk chain linkage, store verbatim, the strict parser that derives the structured payload. Receipt sync is either rebuilt on top of this transport or explicitly bridged to it — it never becomes the source of truth.
- **`SignatureProviderInterface`** — async-capable, capability flags; only `HashChainIntegrityProvider` implemented; the interface shape designed so a future `TseSignatureProvider` is additive.
- **Per-anomaly-class integrity-exception path** (source-of-truth §7.1) — `canonical_hash_mismatch`, `canonical_parse_failure`, `time_anomaly`, `sequence_gap`; the quarantine partition; reconciliation-in-exports (not silent exclusion).
- **Chain-recovery events** — `CHAIN_BREAK_DETECTED` + `CHAIN_RESTART` (chained incident events with operator authorization evidence).
- **Clock/time model** (source-of-truth §6) — the four timestamp fields, rollback/drift detection, the normative closure-period rule.
- **The §1 preflight verification gate** — query staging/prod tenant DBs for fiscal data; written owner sign-off; archival fallback if any data is found. Executed before any destructive rebuild step.
- **Receipt-chain clean rebuild** — the existing receipt chain rebuilt on the one pattern. Per the scoping audit:
  - *Reuse:* client-side authoring/sealing (`receiptService.ts`, `terminal_state`, `offline_receipts`), sync batch orchestration + stock/voucher/payment logic, `Compliance/FiscalHashService` chain-prefix logic, `Nf525XmlBuilder`.
  - *Rework:* `ReceiptFinalizationService` + `ReceiptHashService` verify paths → re-hash stored canonical bytes; `ReceiptSyncService` mismatch branch → per-class accept/flag; `Nf525DataProvider` + JET verification path → read verified canonical bytes + quarantine state.
  - *Discard:* server recompute-and-overwrite; `OfflineFiscalHashMismatchException` throw-and-rollback.
  - *New:* `canonical_bytes` columns (server `BYTEA` / SQLite `TEXT`); client stores + transmits canonical bytes; JET export sections for quarantine/incidents/manifests.
- **Company-level integrity scaffolding** — `TERMINAL_REGISTRY_SNAPSHOT` / `COMPANY_DAY_CLOSURE_MANIFEST` as immutable chained record *types* (the structures exist in the engine; populated as terminals/closures come online).
- **Off-device durability controls** (source-of-truth §8) — at least one off-device durability path (encrypted removable archive / LAN peer / NAS / cloud-sync), key custody **outside** the terminal disk, an operator-visible unsynced age/count indicator, a forced archive/export threshold, maximum-unsynced escalation, and a device-loss incident register. This is a **Phase 1 gate before any Phase 2 customer-facing deployment** — device authority (D1) is not survivable when a terminal is lost/stolen/destroyed before sync without it.
- **`Payment.origin` / `Payment.fiscal_event_id`** — **(Treasury-module integration, D16.)** the migration adding both columns + the `PaymentOrigin` enum + updates to **every existing Treasury `Payment` writer** (complete inventory in Phase 1 spec v3 §13). The `payments` table is Treasury-owned and the new `fiscal_event_id` FK runs `payments → fiscal_events` — the correct direction (module depends on engine). Delivered in Phase 1 *for deployments that compose Treasury in*; consumed by the `TreasuryReceiptBridge` (the Phase 1 pluggable bridge). A POS-only deployment neither has nor needs it. Phase 2's `ACCOUNT_PAYMENT` projection *consumes* these fields — it does not introduce them.

**Why the receipt rebuild is in the foundation, not later:** the engine and the receipt chain must run the same pattern; building the engine beside a server-recompute receipt chain is incoherent, and the server-recompute pattern is the active source of the production chain-breaks.

**Out of scope for Phase 1:** anything customer-facing; the Z-report chain rebuild (independent — see below); session events; the actual TSE provider.

---

## Phase 1.5 — Post-Pass-2 cleanup tasks (added 2026-05-20 per synthesis v5)

These tasks land **after Phase 1 Pass 2A + 2B merge to dev** and **before any Phase 2 customer-facing deployment**. They retire transitional code/columns introduced or retained by the Phase 1 receipt-chain clean rebuild so go-live ships no dead code (per owner D5).

- **Phase-2 mirror-column audit + drop** — audit every SQL/code consumer of `pos_receipts.{fiscal_hash, previous_hash, chain_sequence, vat_breakdown_hash, payment_methods_hash}` (server-side mirror columns Task 21 R2 populated for read-compat through Phase 1) and `terminal_state.{last_hash, hash_sequence}` (device-side legacy chain columns retained briefly post-Pass-2B). Drop columns with no remaining consumer via a new migration. Remove projector mirror writes for any dropped column. Goal: "no dead code at go-live" per owner directive 2026-05-20.

- **Per-country tax-number strict validation** — Pass 2A ships universal `seller.tax_number` validation (`^[A-Za-z0-9 \-/.]{4,40}$`) to avoid blocking sellers on a wrong country-specific regex. After accountant confirmation per country (immediate: TN matricule fiscal + FR SIRET; later: SA VAT 15-digit, DE USt-IdNr, IT P.IVA), land the per-country regex table + per-country positive/negative test fixtures + a validator branch keyed on `seller.tax_jurisdiction_country_code`. **Pre-Tunisia-launch gate.**

- **ParseFailureResolution operator UX — pre-fill from best-effort parse** — Pass 2A's `ParseFailureResolutionService::resolve()` requires operators to hand-craft a full 27-key corrected payload, which is brutal UX. Build an admin tool that reads `fiscal_event_quarantine.raw_envelope`, attempts best-effort parse, pre-fills the structurally-valid 27 keys, lets the operator amend only broken fields, and submits via the existing resolve service. Phase-2-prep work.

---

## Phase 2 — On-Account Payment + Customer Attach (first customer-facing slice)

The first slice that delivers customer value — the para-pharmacy use case.

**Scope:**
- **POS customer mirror** — a local SQLite customer table (the codebase audit confirmed the POS has **no** customer mirror today). Synced from the server `Partner` model. The mirror **must include the balance projection the `ACCOUNT_PAYMENT_RECEIPT` requires**: `receivable_balance`, `credit_balance` (or an equivalent account-balance snapshot), `balance_updated_at`, plus local receipt fields for previous / projected-remaining balance at seal time and a staleness marker. Open-document detail is not mirrored — FIFO allocation happens server-side after sync, and the printed remaining balance is the local snapshot, later reconciled (`server_reconciles`).
- **Customer search / create / attach** in the POS — by phone/name/account; minimum-field create; attach to a transaction. No **offline Tauri** customer search/create/attach flow exists today; server-side POS receipt/order paths already carry `partner_id` and should be reused or adapted where appropriate — the offline flow is net new.
- **`ACCOUNT_PAYMENT`** fiscal event — money received toward a customer balance, authored + sealed on the device through the Phase 1 engine. **POS-core**: the device records the sealed event + local cash-drawer state; this runs in any deployment.
- **Server projection** — **(Treasury-module integration, D16 — a pluggable bridge on the Phase 1 projector seam, not POS-core.)** when the Treasury module is active, the synced `ACCOUNT_PAYMENT` event projects to a Treasury `Payment` row (`origin='pos'`, `fiscal_event_id` set — both columns delivered in Phase 1, see above); allocation against open balances (FIFO) reusing `PaymentAllocationService` *with* the command-DTO refactor it needs for non-`Auth::user()` context. A POS-only deployment records the `ACCOUNT_PAYMENT` event and prints the receipt without this projection.
- **`ACCOUNT_PAYMENT_RECEIPT`** printable — projection of the event. POS-core (the printed remaining balance is the local balance snapshot).

**Reuse vs new:** the Phase 1 engine is reused wholesale; the `Partner` model + `CustomerCategory` exist server-side; `PaymentAllocationService` is reused with a refactor. New: the POS customer mirror, the customer-attach UI, the `ACCOUNT_PAYMENT` event type + payload DTO, the printable.

**Reconciliation classification:** `ACCOUNT_PAYMENT` is `offline_authoritative`; the server-side allocation against open balances is `server_reconciles`.

---

## Phase 3 — Charge-to-Account

Letting a customer leave owing — the AR-creating side.

**Scope:** `ACCOUNT_CHARGE` event type (POS-core: the device authors + seals it); the settlement-vs-payment-line split; the **AR GL posting path that does not exist today** (`createPOSPaymentEntry` is cash→revenue only — codebase audit §2.2) — **(Treasury-module integration, D16: the AR GL posting is a Treasury bridge on the projector seam, not POS-core; the `ACCOUNT_CHARGE` event is published by the engine and the Treasury bridge consumes it)**; B2B `Facture` routing (identified B2B charge-to-account routes through the invoice flow, not the POS ticket path — B2B-sales-module integration); the rules engine (credit limits, terms).

**Key constraint:** this is where the GL genuinely changes — it must be designed with the accounting model explicit, not bolted onto the cash-revenue path. And the GL change lands in the **Treasury bridge**, never in POS-core — a POS-only deployment without Treasury still authors `ACCOUNT_CHARGE` events; it just has no AR GL projection.

---

## Phase 4 — Account Status + Overrides + Cash-Out + Approval Primitive

**Scope:** the account-status lifecycle; override flows; cash-out controls; the approval primitive (PIN now, push later); the per-anomaly-class policies for high-severity classes as more jurisdictions/providers come online.

---

## Phase 5 — Deposits + Identity Reconciliation + AML + Store Credit

**Scope:** `DEPOSIT_RECEIPT` (the offline-first B2C deposit — distinct from the B2B online deposit flow); identity reconciliation; AML thresholds; `ACCOUNT_CREDIT_ISSUE` / `ACCOUNT_CREDIT_USAGE` store credit.

---

## Parallel / independent — Z-Report Chain Clean Rebuild

The Z-report chain is **independent at the chain-protocol level** from the receipt chain (source-of-truth §12) and also currently server-recomputes. It needs the same clean-rebuild treatment as its **own task** — schedulable alongside or just after Phase 1, not blocking the customer-facing phases.

**Coordination caveat:** "independent" holds for chain *semantics* only — it is **not** physically isolated. The receipt and Z-report chains share the local `terminal_state` table + its repository code and the `Nf525DataProvider` export/verification surface. A parallel Z-chain task **must coordinate** its `terminal_state` migrations and those shared repository/export touchpoints with Phase 1 to avoid racing on the same code.

---

## Per-phase process

Each phase follows the same loop:
1. Write the phase spec — opens by referencing source-of-truth v3 + the codebase reality audit; every codebase claim traceable.
2. Self-review (placeholder scan, internal consistency, scope check, ambiguity check).
3. Owner review.
4. Codex adversarial review — prompt grounded in source-of-truth v3 + the two owner strategy docs; review written to a file.
5. Triage, revise to resolution.
6. `writing-plans` → implementation plan.
7. Execute; update this roadmap's phase status.

---

## Sequencing & what is NOT in scope

- **Phase 1 is the gate** — nothing customer-facing ships until the foundation + receipt-chain rebuild are sound.
- **Out of scope entirely** (tracked, not lost): full session/Z-report event migration beyond the Z-chain rebuild; B2B document events into the engine; NF525 certification submission; country-specific signature providers; the `offline_authoritative` reconciliation model's harder cases; marketplace/delivery/mobile; body-shop fully-offline workshop; auto-freeze cron.
- **Web POS (`TerminalType::Web`) device-authority parity** — a browser-based web POS cannot device-author/seal locally. Phase 1 *dispositions* it in the preflight gate (confirm its receipt-creation path is unused, or hide/disable it so it does not coexist with the rebuilt device-authority chain — SoT D8). Bringing the web POS onto the device-authority pattern, or replacing it, is deferred parity work, owner-decided in a later phase. **Viewing** POS transactions on the web is unaffected — the existing web shop-management (POS) section already lists receipts/transactions as a read projection over the synced server mirror; that is the simplest-use-case surface and needs no Treasury module (SoT §13.6).
- **Tunisia food service** — out of product scope as architected (D15).

---

## Immediate next action

Complete the Phase 1.5 cleanup list before opening Phase 2 customer-facing work.

---

**End of phased plan (roadmap v2).**
