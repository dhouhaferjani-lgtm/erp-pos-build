# Phase 2 Spec v1 — Opus Second-Pass Review

**Date:** 2026-05-21  
**Reviewed artifact:** `docs/superpowers/specs/2026-05-21-pos-customer-accounts-phase2-spec-v1.md`  
**Prior review:** `docs/superpowers/reviews/2026-05-21-pos-customer-accounts-phase2-spec-v1-codex-review.md`

## BLOCKER

None.

## REQUEST-CHANGES

None.

## MINOR

None.

## CLEAN

- The spec matches Roadmap v2 Phase 2 scope: `ACCOUNT_PAYMENT`, POS customer mirror, customer search/create/attach, POS-core printable receipt, and Treasury bridge/FIFO. It keeps Phase 1.5 tax-number validation as a visible pre-deployment gate.
- D16 is preserved. POS fiscal engine, strict ingest/parser, POS-core projection, and printable receipt do not hard-depend on Treasury, Accounting, B2B, Customer, or Partner operations. Treasury behavior is projector-gated through module activation.
- Reconciliation authority is correct: `ACCOUNT_PAYMENT` authoring and printing are `offline_authoritative`; Treasury `Payment` creation and FIFO allocation are `server_reconciles`.
- Codebase reality is addressed: the missing POS customer mirror is explicitly added, existing `Partner` balance fields are reused as snapshots, offline POS does not write Treasury `Payment`, and `PaymentAllocationService` must be refactored to an explicit actor/context command API.
- The pending-customer path is safe: sealed fiscal events can ingest before canonical Partner resolution, while the Treasury bridge waits or dead-letters instead of creating cross-company or unresolved payments.
- Phase 1 precedent is carried forward: TS/PHP byte parity, no `app()` / `App::make()` / `resolve()`, cross-tenant FK scoping, fail-loud dead letters, dead-path rebuild, and exhaustive union tests are all specified.
- Printable receipt reads sealed payload/projection data rather than live Partner balance, preserving offline evidence and stale-balance disclosure.

## Final Verdict

APPROVE. No blocking architecture or safety issue found in the bounded second-pass review.
