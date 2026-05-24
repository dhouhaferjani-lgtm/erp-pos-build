# POS Phase 4 — Account Status, Overrides, Cash-Out Controls, Approval Primitive (v3)

**Date:** 2026-05-22
**Branch:** `feat/fiscal-phase-4-account-status-overrides-approval`
**Status:** Revised after Codex self-adversarial review and independent second-pass adversarial review.
**Roadmap:** `docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap-v2.md` §Phase 4.
**Handover:** `docs/superpowers/coordination/2026-05-22-codex-handover-phase-4-and-z-report.md` §5.

## 1. Grounding

This spec inherits the locked offline-first fiscal architecture:

- Source-of-truth v3: device authors and seals fiscal events; server verifies verbatim and mirrors; canonical bytes are serialized once on the device. Cited as `[SoT §N]`.
- Codebase reality audit: current customer/account/PIN/cash-drawer facts. Cited as `[reality §N]`.
- Phase 1 spec v7: fiscal engine, D16 bounded-module seam, no server-side `SALE_RECEIPT` authoring, and the implemented projector registry. Cited as `[Phase1 §N]`.
- Phase 2 + 3 implementation state from roadmap v2: `ACCOUNT_PAYMENT` and `ACCOUNT_CHARGE` are live device-authored events; account-charge credit rules currently fail closed. Cited as `[roadmap v2]`.
- Owner fiscal-chain strategy: supervisor approvals and cash drawer movements must be explicit, immutable, and auditable.

Every implementation claim below was checked against current code in this worktree on 2026-05-22:

- `FiscalEventType` already reserves `OPENING_FLOAT`, `CASH_IN`, `CASH_OUT`, `SAFE_DROP`, `CASH_CORRECTION`, `SESSION_OPEN`, `SESSION_CLOSE`, `X_REPORT`, `Z_REPORT`, but `isImplemented()` returns true only for `SALE_RECEIPT`, `CHAIN_BREAK_DETECTED`, `CHAIN_RESTART`, `TERMINAL_REGISTRY_SNAPSHOT`, `ACCOUNT_PAYMENT`, and `ACCOUNT_CHARGE`.
- The POS customer mirror exists in SQLite `customers` migration v39 and Phase 3 credit-control columns in v41; it has `is_active` and charge fields but no `account_status`.
- The server customer sync resource reads `Partner` and emits `is_active`, `credit_limit`, `payment_terms_days`, and `charge_account_enabled`; it does not emit an account-status lifecycle field.
- `Partner` has balance and credit-limit fields but no account-status enum.
- User POS PINs already exist as `users.pos_pin`, bcrypt-hashed via the model cast; admin set/clear flows and local `operator_pins` sync exist.
- `ManagerPinController` validates manager PINs online and audits `ManagerOverrideAuthorized`, but its authorization vocabulary is shift-variance-specific and `PinVerifier` resolves by bare user id.
- Existing cash drawer operations are mutable-business projections in `pos_cash_drawer_operations` with operation types `OPENING`, `SALE`, `REFUND`, `DEPOSIT`, `PAYOUT`, `CLOSING`; offline POS still stores `offline_cash_drawer_ops` with `deposit|payout` and syncs to legacy cash-drawer endpoints.

## 1.1 v1 -> v3 Changelog

Codex self-review found three material gaps:

- **`ACCOUNT_STATUS_CHANGED` missing from implemented event list.** v1 described status-change fiscal facts but omitted the event from §5.2. v2 adds it and requires PHP/TS/DB CHECK/parser/DTO parity.
- **Web-admin status changes lacked an authoring model.** Customer account-status transitions are normally managed from the web admin, but SoT device authority forbids arbitrary server re-authoring. v2 introduces a Phase 4 virtual admin terminal, matching the Phase 1 future-work note, for administrative fiscal events only.
- **Cash drawer deferral wording was too vague.** v2 keeps Phase 4 cash-out control work, but states no cash drawer movement fiscal event types are implemented in Phase 4 unless the Z-report spec later rejects its own recommendation.

The second-pass adversarial review blocked v2 until these points were explicit:

- **`ACCOUNT_STATUS_CHANGED` is a server-only administrative carve-out.** v3 applies the Phase 1 server-authoring mechanics: server-only registry classification, device append rejection, strict payload validation before persistence, row-level chain locking, and an atomic transaction tying the `Partner` status mutation to the fiscal event.
- **Supervisor approval eligibility is company/terminal scoped before PIN verification.** v3 requires `(tenant_id, company_id, terminal_id, approval_scope)` eligibility checks before `Hash::check` online and equivalent scoping in the offline PIN mirror.
- **Override lifecycle outcomes are complete and fail loud across every scope.** v3 defines the approval decision union and requires atomic local authoring for approval -> override -> constrained event where applicable.
- **Cash drawer scope is limited to existing legacy `DEPOSIT`/`PAYOUT` controls.** Safe-drop and correction movement semantics are deferred to the Z-report/session-chain decision, and legacy drawer approvals use `OPERATOR_APPROVAL_GRANTED` plus immutable approval evidence on the mutable row rather than a scope-specific `OVERRIDE_*` event.
- **Highest-risk regression gates are named.** v3 adds server-only status-change rejection, carve-out concurrency, registry drift, cash-drawer unimplemented drift, and account-charge chokepoint gates.

## 2. Decisions

### D-Q1 — Approval Primitive Identity Model

**Decision locked for v1 unless owner overrides:** use **per-supervisor PIN** stored on the supervisor user record (`users.pos_pin`), never a shared terminal PIN.

Rationale:

- Existing code already has per-user POS PIN hashes, uniqueness per tenant, local offline PIN mirror, and queued PIN updates.
- Audit evidence must name the approving supervisor, not just a terminal-level secret.
- This matches the device-loss and incident evidence pattern from Phase 1: evidence is per actor, reason, terminal, and time.
- A future push-approval channel can attach to the same supervisor user identity without changing the fiscal event payload contract.

Spec review callout: owner should confirm this identity model. If owner chooses a per-terminal shared override PIN, the plan must be rewritten because the current user-record PIN path becomes insufficient audit evidence.

## 3. Scope

Phase 4 ships four things:

1. **Customer account-status lifecycle.** Server and POS mirror support `active`, `suspended`, `closed`, and `disputed`. Status changes require actor + reason and create an `ACCOUNT_STATUS_CHANGED` fiscal event through the Phase 4 virtual admin terminal. POS charge-to-account blocks `suspended`, `closed`, and `disputed` unless an approved override is sealed.
2. **Operator approval primitive.** A reusable approval-resolution model covers `granted`, `denied`, `expired`, `rate_limited`, `unsigned`, `scope_mismatch`, `cross_tenant_rejected`, and `server_quarantined`. The primitive validates a per-supervisor PIN, records deterministic local evidence, and seals an `OPERATOR_APPROVAL_GRANTED` event before the constrained action is allowed to author its own event.
3. **Override flows.** Over-credit-limit account charge, discount beyond cashier authority, manual tender-tolerance override, and device-side void/return authorization all use the same primitive. Each successful override emits a typed fiscal override event that references the approval event and the target/constrained action.
4. **Cash-out controls.** Existing legacy cash drawer `DEPOSIT` and `PAYOUT` paths are brought under the approval primitive for configured thresholds and reason requirements. These controls author `OPERATOR_APPROVAL_GRANTED` when approval is required and store approval evidence on the legacy drawer row; they do not author a scope-specific `OVERRIDE_*` event in Phase 4. Phase 4 does **not** implement safe-drop/correction movement semantics and does **not** implement cash drawer movement fiscal events. The handover explicitly reserves cash drawer event ownership for the Z-report spec D-Q1 (§6.2), with a recommendation that Z-report/session chain owns `OPENING_FLOAT`, `CASH_IN`, `CASH_OUT`, `SAFE_DROP`, and `CASH_CORRECTION`.

Out of scope:

- Push/mobile approval.
- Z-report/session-chain rebuild.
- GL closing entries for cash drawer movements.
- Country-specific signature providers.
- AML/deposits/store credit, which remain Phase 5.
- Web POS device-authority parity.

## 4. Account Status Lifecycle

### 4.1 Server Model

Add `CustomerAccountStatus` enum:

- `active`
- `suspended`
- `closed`
- `disputed`

Add `partners.account_status` defaulting to `active`. `is_active` remains for generic partner enablement and legacy filters; account status is the customer-account workflow state and must not be collapsed into `is_active`.

Status transitions:

- `active -> suspended`
- `active -> disputed`
- `suspended -> active`
- `suspended -> closed`
- `disputed -> active`
- `disputed -> closed`
- `closed` is terminal except a privileged reopen to `active` with explicit reason and approval evidence.

Every transition writes:

- `account_status`
- `account_status_changed_at`
- `account_status_changed_by`
- `account_status_reason`
- `account_status_version`

The POS customer sync resource emits those fields into the local mirror.

### 4.1.1 Virtual Admin Terminal for Status Changes

Most account-status transitions are administrative actions from the web, not Tauri checkout actions. Phase 4 therefore adds a **virtual admin terminal** for administrative fiscal events:

- one terminal per `(tenant_id, company_id)` with `TerminalType::VirtualAdmin` or an equivalent persisted `virtual_admin` marker;
- a database-level uniqueness rule prevents more than one virtual admin terminal for the same tenant/company;
- virtual admin terminals are excluded from terminal activation, terminal claim/sync, shift/session, cash drawer, sale, and account payment/charge flows;
- no sale receipt authoring and no cash/session chain participation;
- only allowed event type in this phase: `ACCOUNT_STATUS_CHANGED`;
- sequence/hash chain is still in `fiscal_events`, so the event is immutable and verifiable;
- every event records the web admin actor, reason, old status, new status, partner id, and partner identity snapshot.

This is a narrow, explicit server-only administrative carve-out for status facts and must not reopen server-side `SALE_RECEIPT` authoring. It must follow the Phase 1 company-integrity event mechanics:

- `ACCOUNT_STATUS_CHANGED` is classified as server-only in both PHP and TS registries.
- POS/device append APIs must reject `ACCOUNT_STATUS_CHANGED`; local device authoring cannot produce it.
- Server payload validation must run before any fiscal-event row is persisted.
- Chain append uses row-level locking for the virtual admin terminal/company chain.
- The `Partner` status mutation and fiscal-event append run in one database transaction. Either both commit or neither commits.
- Replay/idempotency uses a deterministic status-transition reference so a retry cannot create two status-change events for one committed transition.
- A concurrency test must prove two simultaneous status changes for the same partner serialize and produce a single valid account-status version progression.

A grep gate must assert the virtual-admin emitter cannot author `SALE_RECEIPT`, `ACCOUNT_PAYMENT`, or `ACCOUNT_CHARGE`.

### 4.2 POS Mirror

Add SQLite migration v42 to `customers`:

- `account_status TEXT NOT NULL DEFAULT 'active' CHECK(account_status IN ('active','suspended','closed','disputed'))`
- `account_status_changed_at TEXT`
- `account_status_reason TEXT`
- `account_status_version TEXT`

Repository upsert preserves tenant + company scoping and fails closed on cross-company drift, same as the existing customer mirror.

### 4.3 Charge Rules

`evaluateAccountChargeCreditDecision()` gains rejection codes:

- `account_suspended`
- `account_closed`
- `account_disputed`

For `active`, existing Phase 3 rules run unchanged. For non-active statuses:

- no account charge is allowed without a valid approval evidence object;
- approval does not mutate the status;
- the canonical `ACCOUNT_CHARGE` payload records both the account status snapshot and the approval reference when present.

## 5. Approval Primitive

### 5.1 Data Model

Add a POS-local table and server mirror/projection table:

`operator_approvals`:

- `id`
- `tenant_id`
- `company_id`
- `terminal_id`
- `cashier_user_id`
- `supervisor_user_id`
- `approval_scope`
- `target_event_type`
- `target_reference_id`
- `decision` (`granted`, `denied`, `expired`, `rate_limited`, `unsigned`, `scope_mismatch`, `cross_tenant_rejected`, `server_quarantined`)
- `reason_code`
- `reason_text`
- `requested_at_device`
- `resolved_at_device`
- `expires_at_server_seen`
- `fiscal_event_id`
- `sync_status`

The table is not chain truth. Chain truth is the `OPERATOR_APPROVAL_GRANTED` fiscal event. The table exists for UI state, idempotency, and sync diagnostics.

`approval_scope` is a closed enum in Phase 4:

- `credit_limit`
- `account_status`
- `discount_limit`
- `tender_tolerance`
- `void_return`
- `cash_drawer_payout`
- `cash_drawer_deposit`

### 5.2 Fiscal Event Types

Add and implement:

- `ACCOUNT_STATUS_CHANGED`
- `OPERATOR_APPROVAL_GRANTED`
- `OVERRIDE_CREDIT_LIMIT`
- `OVERRIDE_ACCOUNT_STATUS`
- `OVERRIDE_DISCOUNT_LIMIT`
- `OVERRIDE_TENDER_TOLERANCE`
- `OVERRIDE_VOID_OR_RETURN`

`FiscalEventType` currently has no cases for these override-specific names, so implementation must update PHP and TS registries, DB CHECK constraints, `StrictCanonicalParser`, `FiscalPayloadConstraintValidator`, payload DTO registry, and drift tests in the same atomic task. No Phase 4 event type may return implemented=true until those pieces pass together. Event version is `1`.

Payloads use the Phase 1/2/3 canonical style:

- tenant/company/terminal/operator identity snapshots;
- supervisor identity snapshot;
- approval scope and reason;
- target constrained action (`event_type`, local reference, and eventual fiscal event id when known);
- policy version;
- decision timestamps;
- training flag;
- regime extensions.

No payload may query another module during projection. Any required customer/payment/discount context must be sealed into the canonical payload at authoring time or read through `App\Shared\Contracts\...` interfaces.

Authoring authority:

- `ACCOUNT_STATUS_CHANGED` is server-only through the virtual admin terminal and must be rejected by POS/device append paths.
- `OPERATOR_APPROVAL_GRANTED` and all `OVERRIDE_*` events are device-authored in POS-core unless a later spec explicitly adds a server-only flow.
- Server ingestion at `POST /api/v1/pos/sync/fiscal-events` must reject server-only event types before any `fiscal_events` insert; a rejected server-only envelope creates no fiscal-event row.
- Cash drawer movement event types stay reserved and unimplemented in Phase 4.

### 5.3 PIN Verification

Online verification reuses `users.pos_pin` but must be generalized from the current manager-variance endpoint:

- tenant, company, terminal, and approval-scope eligibility must be checked before `Hash::check`;
- a supervisor from the same tenant but a different company/terminal scope must fail as `scope_mismatch`, not merely fail a permission check after PIN validation;
- company/terminal context must be supplied by the caller and recorded;
- permission must be scope-specific, e.g. `pos.override.credit_limit`, `pos.override.discount_limit`, `pos.override.cash_drawer`, `pos.override.void_return`;
- rate limits are per `(tenant_id, terminal_id, supervisor_user_id, approval_scope)`, not just IP + user id.

Offline verification uses the existing `operator_pins` mirror:

- synced rows must carry `tenant_id`, allowed `company_ids` or exact `company_id`, allowed `terminal_ids` where terminal scoping applies, and approval-scope permissions;
- supervisor row must carry the needed permission in its synced `permissions` array;
- local verification must reject tenant/company/terminal/scope mismatches before bcrypt comparison;
- PIN hash remains bcrypt from `users.pos_pin`;
- local failed-attempt counters use `terminal_state.manager_pin_failed_attempts` and `manager_pin_throttle_until`, but TTL decisions must not trust the local clock as an authority. Local TTL is a UX throttle only; the sealed approval payload records server-time staleness from the last sync and the server can quarantine stale/expired approvals on ingest.

## 6. Override Flows

### 6.1 Over-Credit-Limit Charge

Current Phase 3 credit rules reject `credit_limit_exceeded`. Phase 4 adds an explicit override path:

1. Credit decision rejects with `credit_limit_exceeded`.
2. POS opens approval flow with scope `credit_limit`.
3. Supervisor PIN grants approval.
4. POS authors `OPERATOR_APPROVAL_GRANTED`.
5. POS authors `OVERRIDE_CREDIT_LIMIT`, referencing the approval event.
6. POS authors `ACCOUNT_CHARGE` with `credit_decision.decision='approved_with_override'`, `limit_exceeded=true`, and the override reference.

If steps 4 or 5 fail, step 6 must fail loudly. No fallback silently authors an ordinary approved charge.

### 6.1.1 Atomic Authoring Contract

Every device-authored override scope follows the same lifecycle:

1. approval request is evaluated for exact tenant/company/terminal/scope/target context;
2. `OPERATOR_APPROVAL_GRANTED` is authored only for a granted approval;
3. a scope-specific `OVERRIDE_*` event is authored and references the approval event;
4. the constrained action is authored only when the override event is present and matches the exact target amount, policy version, customer/account status, and local reference.

Where the constrained action is device-authored, these writes must happen in one local transaction. If the approval or override event exists but the target event fails, the local queue must mark the approval/override as orphaned and block completion of the constrained workflow until the operator cancels or retries. It must not silently continue with an unconstrained target event.

Fail-loud tests are required for device-authored override scopes: `credit_limit`, `account_status`, `discount_limit`, `tender_tolerance`, and `void_return`.

Legacy cash drawer `cash_drawer_payout` and `cash_drawer_deposit` are approval-control scopes, not `OVERRIDE_*` scopes in Phase 4. If approval is required, they author `OPERATOR_APPROVAL_GRANTED`; the constrained legacy operation references that approval row/event and fails loudly if the evidence is missing, expired, scope-mismatched, or tenant/company/terminal-mismatched.

### 6.2 Account-Status Override

Non-active customer account states block charge-to-account. A supervisor may override only for `suspended` and `disputed` if policy permits. `closed` requires a status transition first; no POS override may charge a closed account.

### 6.3 Discount and Tender Tolerance

Existing discount and tolerance guardrails keep their fail-closed behavior. Phase 4 adds approval evidence as an explicit input. The constrained operation must not infer approval from a previously verified PIN alone; it must reference an approval row/event id scoped to the exact action, amount, and policy version.

### 6.4 Void / Return

Server-side void/return carve-outs remain as Phase 1 retained paths. Phase 4 adds the device-side authorization event and audit evidence for the POS UI path. It does not rebuild all void/return fiscal event payloads unless the plan can keep that atomic with tests; otherwise those remain explicit follow-up under the override event.

## 7. Cash-Out Controls

Existing server cash drawer endpoints and POS offline cash drawer operations are control surfaces, not fiscal chain truth. Phase 4 must:

- require reason text for legacy `DEPOSIT` and `PAYOUT` operations;
- require supervisor approval above configured thresholds;
- tenant-scope shift and terminal lookups before writing;
- prevent closed-shift writes;
- record approval evidence in the legacy `pos_cash_drawer_operations` row for transitional auditability;
- carry approval evidence through POS offline queue, sync API payload, server validation, and resource output.

Phase 4 must not introduce a mutable-only `SAFE_DROP`, `CASH_CORRECTION`, or generic correction operation. Those movement semantics belong to the Z-report/session-chain decision.

The actual movement fiscal event ownership is deferred to the Z-report spec D-Q1. If the owner follows the handover recommendation, the Z-report rebuild implements `OPENING_FLOAT`, `CASH_IN`, `CASH_OUT`, `SAFE_DROP`, and `CASH_CORRECTION` as session-chain events and migrates the legacy `deposit|payout` offline path to those events.

Phase 4 implementation must not mark `OPENING_FLOAT`, `CASH_IN`, `CASH_OUT`, `SAFE_DROP`, or `CASH_CORRECTION` as implemented in `FiscalEventType::isImplemented()` or the TS registry.

## 8. Integrity-Exception Policies

Phase 4 adds policy rows for high-severity anomaly handling:

- `canonical_hash_mismatch`
- `canonical_parse_failure`
- `time_anomaly`
- `sequence_gap`
- future `signature_invalid`

Policy dimensions:

- tenant/company
- country code
- event type
- monetary threshold
- operator action (`allow_continue`, `require_acknowledgment`, `block_terminal_until_acknowledged`, `emergency_procedure`)

Launch-market default:

- France/Tunisia general retail: accept + quarantine remains default for hash/parse/time; sequence gaps require incident acknowledgment; signature invalid has no active provider and cannot occur.

The implementation should expose the policy service and tests but avoid a broad admin UI unless already needed by the plan. Hard-coded defaults are acceptable when backed by enum + config + tests.

## 9. D16 Boundary

POS-core approval, account status snapshots, and override fiscal event projection must not import Treasury, Accounting, Document, Sales, Customer, or Contact module internals. Direct customer state is inbound mirror/reference data only. Cross-module reads must go through `App\Shared\Contracts\...` interfaces.

Add a D16 grep guard for new Phase 4 projector/service directories, mirroring the existing `AccountPaymentD16Test` and `AccountChargeD16Test` pattern.

## 10. Verification Requirements

Required first-round test matrix:

- account status migration defaults existing customers to `active`;
- status transition requires actor + reason;
- virtual admin terminal emits only `ACCOUNT_STATUS_CHANGED` and refuses `SALE_RECEIPT`, `ACCOUNT_PAYMENT`, and `ACCOUNT_CHARGE`;
- device/POS append paths reject `ACCOUNT_STATUS_CHANGED` as server-only;
- server-authored status-change emitter validates payload before persist and serializes concurrent partner status transitions;
- POS mirror sync includes status and fails closed for unknown status;
- account charge rejects `suspended`, `closed`, `disputed`;
- credit-limit override grants only with per-supervisor PIN evidence after tenant/company/terminal/scope eligibility;
- denied / expired / rate_limited / unsigned / cross_tenant_rejected approvals fail loudly;
- scope-mismatch and stale offline approval mirrors fail loudly before bcrypt comparison where possible;
- `closed` account cannot be overridden into charge;
- approval payload strict parser rejects missing supervisor identity, extra keys, malformed timestamps, and cross-tenant target ids;
- fiscal event type registry parity PHP/TS;
- `FiscalEventType::isImplemented()` and TS drift tests prove `OPENING_FLOAT`, `CASH_IN`, `CASH_OUT`, `SAFE_DROP`, and `CASH_CORRECTION` remain unimplemented in Phase 4;
- cash drawer payout threshold requires approval and closed-shift write is blocked;
- cash drawer tests cover legacy `DEPOSIT`/`PAYOUT` only and assert no mutable correction/safe-drop path is introduced;
- D16 import guard;
- `apps/api/scripts/check-accountCharge-chokepoints.sh` passes after account-charge override integration;
- PG-only tests added to `.github/workflows/ci.yml` filter in the same commit as new PG-only classes.

End-to-end closure:

- device authors `OPERATOR_APPROVAL_GRANTED` -> `OVERRIDE_CREDIT_LIMIT` -> `ACCOUNT_CHARGE`;
- server ingests all three in order;
- POS-core account charge projection writes printable record;
- Treasury bridge still gates on `Treasury` and posts AR only when active;
- no legacy `/pos/receipts` authoring path is touched;
- canonical bytes are preserved verbatim.

## 11. Open Review Questions

1. **D-Q1 owner sign-off:** per-supervisor PIN is locked in this spec. Owner should explicitly reject it if a shared terminal override PIN is desired.
2. **Cash drawer event ownership:** this Phase 4 spec deliberately limits cash drawer work to controls and transitional audit evidence. The Z-report spec must lock whether cash drawer movement event authoring belongs to Phase 4/ad-hoc or Z-report/session chain. Recommendation from the handover is Z-report/session-chain ownership.
