# Treasury Phase 3 — Gate 2 rc1 Adversarial Review

## Scope

Waves B+C, reviewed as the authored range `phase3-gate-1..HEAD` against the Rev 2 specification, plan interfaces, and reconciled adversarial findings.

## Findings

1. **LOW / process — binding progress backfill.** Tasks B3 through C2 had detailed reports in `.superpowers/sdd/` but were missing task summaries in `docs/handoff/treasury-phase3-progress.md`. No hidden deviation was found. Corrected before the final Gate 2 tag.
2. **INFO — direction totals.** Applying the direction filter also narrows the report totals block. This is intended and pinned by tests.
3. **INFO — notification mutation responses.** `markRead` and `readAll` return `{message: "ok"}` rather than a `{data: ...}` payload. The endpoint contract does not specify a data resource for these mutations; this is harmless.

## Verified contracts

- Mandatory port guard passed: the `TreasuryMovementService` diff is empty.
- Spatie team context uses the tenant ID, never company ID. The resolver restores the prior team in `finally`.
- Recipient resolution filters active company membership and flushes the permission registrar per tenant. A real PostgreSQL two-tenant test pins cache isolation and deny direction.
- Maturity audits remain unconditional while notifications are gated on a positive combined due count; the zero-count anti-spam test is green.
- Cross-company notification leakage is prevented at send time by active membership scoping.
- Cash-movement report totals group by currency and direction, use exact SQL numeric aggregation, and format at each currency's scale.
- Cash-position flows include only active cash repositories in the company currency and use `occurred_at`.
- No float touches money; net uses `bcsub` and boundary formatting uses `bcformatStrict`.
- Notifications table/module registration, UUID route constraint, stable `databaseType`, ownership-scoped API, and additive report/flow contracts match the spec.
- No money-path uncertainty or deviation exists in Waves B+C; Fable escalation was not triggered.

VERDICT: APPROVE
