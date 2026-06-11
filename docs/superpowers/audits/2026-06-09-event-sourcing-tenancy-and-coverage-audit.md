# Event Sourcing — Tenancy Landing + Fraud/Anomaly Coverage Audit

**Date:** 2026-06-09
**Trigger:** Post db-per-tenant migration. Two questions: (1) does event-sourcing data still land where it should? (2) what events are missing for fraud / anomaly / anti-fraud / process coverage?
**Method:** 1 orientation sweep + 6 parallel domain audit agents, then key claims hand-verified against source — **and critically, re-verified against `origin/dev`** after discovering the working branch was stale.

> ⚠️ **Read this first.** The audit was initially run against the checked-out branch `feat/t11-impl-specs`, which **predates the T6 database-per-tenant flip** (PR #148 is *not* in its ancestry). All tenancy conclusions were therefore re-verified against `origin/dev` (the real flipped mainline). Counts below were verified directly, not taken from agent summaries.

---

## PART 1 — Does event-sourcing data still land correctly?

### 1.1 On mainline (`origin/dev`): YES — landing is correct. No routing bug.

`origin/dev` is genuinely **database-per-tenant**:
- `config/tenancy.php` has a **single** `'pgsql' => PostgreSQLDatabaseManager::class` (L71). The old duplicate `PostgreSQLSchemaManager` entry was explicitly removed in T6 Phase 0b — there's a comment at L80-82 documenting the removal.
- `database/migrations/tenant/` exists with **387** tenant migrations; `database/migrations/` (central) has **24**.
- **All event/audit/fiscal tables live in the tenant migration set** (`database/migrations/tenant/`): `audit_events` (+`add_company_id`), `stored_events`, `fiscal_events` (+immutability, projections, quarantine, chain-context), `inventory_counting_events`, `pos_grandtotal_events`, fiscal metadata. Only `admin_audit_logs` is central (correct — super-admin audit is central).
- `AuditEvent` / `FiscalEvent` models do **not** pin `$connection`, so they inherit the per-request tenant connection swapped in by Stancl's `DatabaseTenancyBootstrapper`.
- ⇒ `tenants:migrate` creates these tables in **each tenant database**, and tenant-context writes route to the tenant DB. The fiscal hash chain, projection state machine, and audit tier are correctly isolated per-tenant. **This is working as intended.**

### 1.2 ⚠️ CRITICAL: the working branch `feat/t11-impl-specs` is STALE w.r.t. the tenancy flip

`feat/t11-impl-specs` (HEAD `b40f22d4a`) branched from a pre-flip `dev` and its "Merge origin/dev" predates T6. On this branch's tree:
- `config/tenancy.php` still has the **duplicate `'pgsql'` key** → `PostgreSQLSchemaManager` (the *last* duplicate) silently wins → effectively schema-per-tenant.
- **No `database/migrations/tenant/` directory**; event/audit/fiscal tables still sit in the pre-flip central `database/migrations/`.
- `config/tenancy_resolver.php`, `RollingTenantMigrationCommand`, `TenantDeprovisioningService`, `TenantBackupService` are **absent** (they exist only on dev and in sibling worktrees).

**Implication:** any event-sourcing, migration, or tenancy work done *on this branch* would be built against the pre-flip model and **regress the database-per-tenant flip** when merged. **Rebase `feat/t11-impl-specs` onto current `dev` before doing event/migration work.** (The earlier-drafted "duplicate-key / dangling migrations-tenant path / routing bug" findings were artifacts of this stale tree and do **not** apply to mainline.)

### 1.3 The "ClickHouse-like time-series DB" does **not exist yet** (holds on dev)

TimescaleDB is referenced only in **code comments** as out-of-scope (`Treasury/.../InvoiceClosedWithTolerance.php`, `Vehicle/.../RecordVehicleOwnerChangedAuditEvent.php`). The audit tier (individual SHA-256 hashes, **not** chained) currently lands in an ordinary Postgres `audit_events` table inside each tenant database. If a time-series/OLAP audit stream is part of the target architecture, it is **unbuilt** — track it as a workstream and decide build-vs-strike-from-narrative.

### 1.4 Two-tier hash chain status

- **Fiscal tier** (`fiscal_events`): SHA-256 **chained**, device-authored, immutable via DB triggers, with a projection state machine. Covers the NF525-critical POS money operations: `SALE_RECEIPT`, `SALE_VOID`, `SALE_CORRECTION`, `REFUND_RECEIPT`, `PARTIAL_REFUND`, `RETURN_WITHOUT_RECEIPT`, `DEPOSIT_RECEIPT`, `OPENING_FLOAT`, `SESSION_OPEN/CLOSE`, `Z_REPORT`, `ACCOUNT_PAYMENT(_RECONCILED)`, `ACCOUNT_REFUND`. **Healthy.**
- **Audit tier** (`audit_events`): individual hashes, fed by `DomainEventSubscriber → AuditService::record()`. **This is where the coverage gaps are** (Part 2).

---

## PART 2 — Coverage audit: missing / unlanded events

> Verified on **both** `feat/t11-impl-specs` and `origin/dev`. Numbers below are from `origin/dev` (the larger, current set). The qualitative gaps are identical on both.

### 2.1 Headline structural finding (verified on dev)

| Metric | Count (origin/dev) | Note |
|---|---|---|
| Event classes defined (`*/Domain/Events/*.php`) | **157** | |
| Extend `DomainEvent` (can reach audit pipeline) | **97** | the other **60 cannot reach `audit_events` at all** |
| Mapped in `DomainEventSubscriber` → `audit_events` | **36** | verified from the subscribe() map |
| ⇒ Share of defined events reaching the immutable audit store | **~23%** | |

Two distinct failure modes:

- **Structural (~60 events):** classes use only `Dispatchable`, not `extends DomainEvent`, so they physically cannot flow into `audit_events` even if mapped. Whole modules affected: **Workshop/WorkOrder, Workshop/Technician, Scheduling, Voucher, Taxation, Vehicle, Import, POS order-flow (`OrderClosed`, `OrderSentToKitchen`, `ReceiptCompleted`, `OrderLineStatusChanged`, `OrderReady`, `TerminalActivated`), Product/`EnrichmentWebhookReceived`**. Fix = change base class + define a canonical audit payload.
- **Wiring (~60 events):** extend `DomainEvent` and are dispatched, but are **not in the subscriber map**, so they silently never land. These are cheap fixes (add a handler + one map line). Verified `extends DomainEvent`: `JournalEntryPosted`, `JournalEntryCreated`, `AccountCreated/Updated`, `PaymentAllocated`, `RepositoryBalanceChanged`, `InstrumentDeposited/Cleared/Bounced/Transferred`, `ReconciliationCompleted`, `ProductCreated/Updated/Deleted`, `ProductCostPriceUpdated`, `StockMovementRecorded`, `InventoryCountingCompleted`, `PartnerCreated/Updated/Deleted`, `CompanyCreated`, `FirstTransactionPosted`, `FiscalYearValidated`, plus most Loyalty events.

### 2.2 Orphaned events (defined but NEVER dispatched)

`PointsExpired`, `PointsRedeemed`, `ProgramCreated`, `StampCardCompleted` (Loyalty); `VehicleRegistered`, `VehicleAttributesUpdated` (Vehicle); `TechnicianProfileCreated` (Workshop). Wire to their creation/completion services or delete. Add a CI test asserting every event class has ≥1 dispatch site.

### 2.3 Highest-value missing anti-fraud signals (by domain)

**Identity / Auth — the biggest blind spot (almost nothing is audited):**
- No events for: **failed login** (rate-limited but unlogged), login success, logout / logout-all, **password reset**, **token created/revoked**, email verification, **manager-PIN verification FAILURE**, device-trust changes, **user re-enable** (classic insider pattern), role *updated* (permission change — only assign/remove are covered).
- **Two-tier integrity issue:** `UserController` writes user create/update/deactivate/activate/pin to `audit_events` **directly** as mutable rows, bypassing the hashed `DomainEvent` pipeline. Migrate to domain events for tamper-evidence.

**POS / cash (fiscal receipts are covered; surrounding controls are not):**
- `CashCountRecorded` is **emitted but unmapped** → cash-variance + manager override on Z-report never audited (HIGH).
- No events for: cash **deposit/payout** (drawer→safe / refunds), **line/transaction discount applied** (below override threshold = invisible), **price override** (below cost), receipt **return/refund destination** (refund routed to a different payment method).

**Accounting / Treasury / Tax (money & ledger integrity):**
- Unmapped: `JournalEntryPosted/Created`, `AccountCreated/Updated`, `PaymentAllocated`, `RepositoryBalanceChanged`, `ReconciliationCompleted`, instrument lifecycle, withholding-cert issue/void, VAT period close/file.
- **Not even emitted:** **VAT-period REOPEN**, **GL/fiscal-period reopen**, **account deactivation**, customer-account status transitions — all high-risk "open the window, change the numbers, close it" vectors.

**Inventory / costing (shrinkage & margin manipulation):**
- `StockMovementRecorded` and `InventoryCountingCompleted` extend `DomainEvent` but are **unmapped** → manual stock adjustments, write-offs, and count-variance approvals are not in the audit trail.
- `ProductCostPriceUpdated` is **broadcast-only** (real-time UI), never persisted → cost/margin changes invisible.
- No events for: **count-variance approval** above threshold, **batch write-off** (expiry/damage/recall), landed-cost allocation, manual cost/price override below cost.

### 2.4 Prioritized remediation

**Tier 0 — branch hygiene + landing (do first):**
1. **Rebase `feat/t11-impl-specs` onto current `dev`** so all subsequent event/migration work targets the flipped database-per-tenant model.
2. Decide TimescaleDB: build the time-series audit stream or strike it from the architecture narrative.

**Tier 1 — cheap, high-value wiring (events already extend `DomainEvent`, just map them):**
`JournalEntryPosted`, `JournalEntryCreated`, `AccountCreated/Updated`, `PaymentAllocated`, `RepositoryBalanceChanged`, `ReconciliationCompleted`, instrument lifecycle (4), `ProductCreated/Updated/Deleted`, `ProductCostPriceUpdated` (stop relying on broadcast-only), `StockMovementRecorded`, `InventoryCountingCompleted`, Loyalty lifecycle, `CashCountRecorded`.

**Tier 2 — new events for blind operations:**
Auth: `UserLoginFailed`, `TokenCreated/Revoked`, `PasswordReset`, `UserActivated`, `ManagerPinVerificationFailed`, `RoleUpdated`. Finance: `VatPeriodReopened`, `FiscalPeriodReopened`, `AccountDeactivated`. POS: `DiscountApplied`, `PriceOverrideApplied`, `CashDeposit/PayoutRecorded`, `ReceiptReturnInitiated`. Inventory: `InventoryVarianceApproved`, `BatchWriteOffRecorded`.

**Tier 3 — structural base-class migration:**
Convert the ~60 `Dispatchable`-only events (Workshop, Voucher, Taxation, Scheduling, Vehicle, Import, POS order-flow) to `extends DomainEvent` with canonical audit payloads, then map the audit-relevant ones.

**Tier 4 — hygiene:**
Wire or delete the 7 orphaned events; add a CI guard that every event class has a dispatch site and (for audit-relevant `DomainEvent` subclasses) a subscriber mapping.

---

## Validation note (dev re-run)

Part 2 was re-run against a clean worktree of `origin/dev` (`.worktrees/dev-event-audit`, HEAD `df30e653b`) with file:line citations from that tree. Two agent discrepancies were hand-resolved against source:
- **Subscriber map = exactly 36 events** (one agent's "42" was a miscount; verified by enumerating the `=> 'handle…'` map).
- The high-value Accounting/Treasury events (`JournalEntryPosted`, `PaymentAllocated`, `RepositoryBalanceChanged`, `AccountCreated`, `ReconciliationCompleted`) are **dispatched but unmapped** — i.e. Tier-1 cheap wins — **not orphaned** (one agent's "84 orphaned" pass over-counted; dispatch sites confirmed in `GeneralLedgerService`, `PaymentAllocationService`, `PaymentController`, `AccountController`, `BankReconciliationService`).

True orphans are a smaller set (Loyalty `PointsExpired`/`PointsRedeemed`/`ProgramCreated`/`StampCardCompleted`, Vehicle `Registered`/`AttributesUpdated`, `TechnicianProfileCreated`). The `Channel` module (~6 `Dispatchable`-only events) also belongs in the structural bucket on dev.

## Caveats

- Aggregate counts (157 / 97 / 36) and all Part 1 tenancy/landing findings were hand-verified against `origin/dev`. Individual file:line citations in the per-domain gap tables should still be confirmed at implementation time.
- No code was changed. This is an audit only. The `.worktrees/dev-event-audit` worktree can be removed with `git worktree remove`.
