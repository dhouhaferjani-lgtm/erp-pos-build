# Gate r4 synthesis — parapharmacy remediation spec v4

Date: 2026-09-05. Orchestrator: Claude Fable 5.1. Input: spec v4 (Codex fix round 3, narrow) at HEAD `d56d62535` (cited source unchanged since `b9a5565aa`). Two Opus lenses, read-only, scoped to r3 blockers R3-B1 / R3-B2 and the r3 majors, plus regression check of r2 closures.

| Lens | File | Verdict |
|---|---|---|
| Treasury / GL | [gate-r4-treasury](2026-09-05-parapharmacy-remediation-gate-r4-treasury.md) | ACCEPT-FOR-OWNER-REVIEW |
| Tenancy / authz | [gate-r4-authz](2026-09-05-parapharmacy-remediation-gate-r4-authz.md) | ACCEPT-FOR-OWNER-REVIEW |

Fiscal and inventory lenses were not re-run: inventory accepted v3 and v4 did not change its sections beyond the L4 positive-arm and L6 test-obligation majors (verified textually by the orchestrator); the fiscal r3 items (NEW-2 drawer gating, NEW-3 drain clause, NEW-4 pointer) were verified closed by the authz and treasury lenses respectively.

**Gate r4 verdict: ACCEPT-FOR-OWNER-REVIEW.** Neither ACCEPT is launch or implementation approval. The spec is ready for owner rulings; briefs follow the rulings.

## Ladder

| Round | Input | Verdict | Blockers |
|---|---|---|---|
| r1 (Fable) | v1 | CHANGES-REQUIRED | F-A, F-B (+ F-C..F-F) |
| r2 (4 lenses) | v2 | CHANGES-REQUIRED | B1–B6 (incl. correction of r1 F-A voucher claim) |
| r3 (4 lenses) | v3 | CHANGES-REQUIRED, converging | R3-B1 non-money GL bypass, R3-B2 terminal authority |
| r4 (2 lenses) | v4 | **ACCEPT-FOR-OWNER-REVIEW** | none |

## Closed in v4 (verified)

- R3-B1: non-money class keeps the shared POS payment entry, debit-only override, movement suppression; `GeneralLedgerService.php:3947-3965` named sole revenue+VAT writer; voucher clearing retained; acceptance rows assert the W4-9 identity, not a balanced trial balance.
- R3-B2: terminal authority stated as new W2 enforcement work over the existing claim flow; server-side read of persisted terminal `location_id`; forged / foreign / A→B / stale acceptance rows; W1↔W2 circularity resolved with W2 as owner.
- All r3 majors and minors, in both lenses; no r2 closure regressed; all twelve decision rows OPEN; no new false claim about code.

## Carry into the execution plans (non-blocking, must appear in the briefs)

| Package | Item | Evidence |
|---|---|---|
| W2 | **Refund twin lacks the debit-override parameter**: `GeneralLedgerService.php:4175-4234` writes the same revenue/VAT lines but has no `cashAccountOverrideId`; a voucher/loyalty refund would credit the repository cash GL with no movement, or throw at `:4181` with no repository. The W2 brief must extend the refund entry symmetrically. | treasury MAJOR |
| W2 | Blocked non-money leg leaves a one-sided PosTenderClearing/liability suspense because POS-core redemption commits regardless. Name the suspense and its resolution path in the W2 brief. | treasury IMPORTANT |
| W2 | **Enrollment authority must cover all three terminal-creation routes**: `claim()` (`TerminalController.php:372`) is gated, but `requestTerminal()` (`:686`, `POS/routes.php:58`) and `getOrCreateWebTerminal()` (`:753-801`, `:60`) let any `pos.operate_terminal` holder, cashier included, obtain a terminal at any company location and legitimately acquire another branch's bindings. Add acceptance rows. | authz N-r4-1 |
| W4 | `ProjectionStatus.php:9-12` has no `blocked` case; the blocked-row writer needs the enum value (enum rule 9). | authz minor |
| W2 | `ProvisioningRequiredPurposesV1.php:57` is REQUIRED, not CONDITIONAL, for the cited purpose; MEAL_VOUCHER physical-custody wording tension. | treasury minors |
| W-LOT | inventory r3 N-1 (positive-arm DEFAULT auto-credit at `StockAdjustmentService.php:1446-1447` vs L9 ordering) and N-2 (L6 PG-only query has no dedicated tests) — v4 addressed both textually; the W-LOT brief must carry the tests. | inventory r3 |

## Owner decisions now required

Sheet: `docs/handoff/OWNER-SHEET-parapharmacy-remediation-decisions-2026-09-05.md`. Rows D1–D9, RD2–RD4. Rulings unblock briefs in this order: W-LOT (needs RD2, D9, D3/D4 for L7), W2 (needs D2, RD3, D8), W1 (needs D5), W7 (needs RD4), W6 iteration 2 (needs D3, D7, D6).

VERDICT: ACCEPT-FOR-OWNER-REVIEW
