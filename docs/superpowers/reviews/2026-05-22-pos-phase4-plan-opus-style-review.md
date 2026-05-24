# Opus-Style Second-Pass Adversarial Review — Phase 4 Plan v1

**Date:** 2026-05-22
**Reviewed plan:** `docs/superpowers/plans/2026-05-22-pos-phase4-account-status-overrides-approval.md`
**Spec:** `docs/superpowers/specs/2026-05-22-pos-phase4-account-status-overrides-approval-spec-v3.md`
**Reviewer:** independent Codex subagent, Opus-style adversarial pass. Actual Opus model was not available in this tool context.
**Verdict:** BLOCKED

## Findings

### F1 — BLOCKER — Fiscal event implemented status was split from parser and DB parity

The plan allowed Phase 4 event types to become implemented before DTOs, `FiscalPayloadConstraintValidator`, strict parser tests, DB CHECK migration, and drift tests existed.

**Required resolution:** collapse fiscal vocabulary, DTO/parser constraints, DB CHECK migration, and PHP/TS drift tests into one atomic task. Do not commit a state where an event returns implemented=true without a strict payload/parser/DB contract.

### F2 — BLOCKER — Server-only status-change rejection was not wired to the real ingest boundary

The plan used an incorrect endpoint/body shape and did not name `OutboxIngestor` as the enforcement point. The real endpoint is `POST /api/v1/pos/sync/fiscal-events` with `envelopes`.

**Required resolution:** add an ingest-boundary test against the real route/body and an `OutboxIngestor` guard that rejects server-only event types before any insert.

### F3 — BLOCKER — Virtual admin terminal storage and lifecycle were underspecified

The plan did not define terminal type/marker, uniqueness, provisioning, sync exclusion, or sale/cash/session restrictions. Current `TerminalType` only supports `web` and `physical`.

**Required resolution:** add a dedicated virtual-admin terminal task with database uniqueness, resolver/provisioning service, lifecycle restrictions, and grep/test gates proving it cannot author sale/account/cash/session events.

### F4 — BLOCKER — Cash drawer approval lifecycle conflicted with override-event lifecycle

The spec included cash drawer scopes in the generic `OVERRIDE_*` lifecycle, but no cash-drawer override event existed. The plan only added mutable approval columns.

**Required resolution:** decide the model. The revised spec chooses: legacy `DEPOSIT`/`PAYOUT` controls author `OPERATOR_APPROVAL_GRANTED` when required and store immutable approval evidence on the legacy row; they do not author scope-specific `OVERRIDE_*` events and do not implement cash movement events in Phase 4.

### F5 — P1 — Scoped PIN approval lacked a server API contract

The plan did not define how `pinData()` derives or returns tenant/company/terminal/scope eligibility, nor the approval verification request fields/rate limit key.

**Required resolution:** define the API response/request contract, source of eligibility, decision union, stale mirror metadata, and tests proving mismatch rejection before hash verification.

### F6 — P1 — Account-status override was incomplete

The plan added account-status rejection but not the allowed `suspended`/`disputed` override path, closed-account hard block, or `ACCOUNT_CHARGE` payload typing for `approved_with_override`.

**Required resolution:** add a concrete account-status override task.

### F7 — P1 — Discount, tender tolerance, and void/return hooks were too vague

The plan created payload DTOs but did not identify the implementation call sites or fail-loud tests.

**Required resolution:** either remove those scopes from Phase 4 or add explicit tasks naming the code paths and tests. The revised plan keeps them and adds concrete POS call-site tasks.
