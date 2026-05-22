# Codex Self-Adversarial Review — Phase 4 Implementation Plan

**Date:** 2026-05-22
**Plan:** `docs/superpowers/plans/2026-05-22-pos-phase4-account-status-overrides-approval.md`
**Spec:** `docs/superpowers/specs/2026-05-22-pos-phase4-account-status-overrides-approval-spec-v3.md`
**Verdict:** APPROVE FOR IMPLEMENTATION AFTER SECOND-PASS FIXES

## Coverage Check

- Account-status lifecycle is covered by Tasks 3, 4, and 5.
- Server-only `ACCOUNT_STATUS_CHANGED` mechanics are covered by Tasks 2 and 4.
- Per-supervisor PIN, company/terminal/scope eligibility, and offline PIN mirror scoping are covered by Task 6.
- `OPERATOR_APPROVAL_GRANTED` and `OVERRIDE_*` payloads are covered by Tasks 2, 7, and 8.
- Account-charge non-active status rejection and over-credit-limit override are covered by Tasks 5 and 8.
- Legacy cash drawer `DEPOSIT`/`PAYOUT` controls are covered by Task 9, with safe-drop/correction explicitly excluded.
- Integrity exception defaults are covered by Task 10.
- D16, registry drift, cash movement unimplemented drift, and chokepoint guards are covered by Task 11 and Task 12.

## Findings Fixed Inline

- The spec still used `pos.override.cash_out` and hyphenated `rate-limited`; corrected to `pos.override.cash_drawer` and `rate_limited` naming.
- The plan used the old `PHASE_1_IMPLEMENTED` constant name; corrected to require explicit renaming to `IMPLEMENTED_EVENT_TYPES`.
- The override DTO file list was too compressed; expanded to exact DTO paths.

## Second-Pass Fixes Incorporated

- Collapsed fiscal vocabulary, DTOs, parser constraints, DB CHECK migration, and drift tests into one atomic task.
- Added the real ingestion boundary for server-only event rejection: `POST /api/v1/pos/sync/fiscal-events` with `envelopes`, enforced in `OutboxIngestor`.
- Split virtual admin terminal storage/resolution into its own task with uniqueness, sync exclusion, and lifecycle restrictions.
- Resolved cash drawer lifecycle: legacy `DEPOSIT`/`PAYOUT` controls use `OPERATOR_APPROVAL_GRANTED` plus row evidence and do not author `OVERRIDE_*` or movement events.
- Expanded scoped PIN approval into a server/API/offline mirror contract.
- Added explicit account-status override and POS discount/tender/void/return hook tasks.

## Residual Risks For Second-Pass Review

- The plan touches many files and may need implementation batching if dependency installation or existing tests reveal drift from the handover.
- Discount, tender tolerance, and void/return hooks now name POS call sites, but implementation must still verify exact state transitions before authoring override events.
- Virtual admin terminal type changes may affect terminal lifecycle tests; Task 4 isolates that risk before account-status fiscal authoring.
