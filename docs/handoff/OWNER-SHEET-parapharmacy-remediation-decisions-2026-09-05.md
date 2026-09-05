# Owner sheet — parapharmacy remediation decisions (spec v4, gate r4 ACCEPT-FOR-OWNER-REVIEW)

Date: 2026-09-05. Spec: `docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md` §2.3 is the authoritative register; this sheet is the one-pass ruling form. Fill the **Ruling** column; Fable writes briefs from it. "Rec." = the spec's recommended default. Nothing here is implementation or launch approval.

## Rulings that unblock the first brief (W-LOT)

| ID | Question | Rec. | Consequence of the alternative | Ruling |
|---|---|---|---|---|
| RD2 | Does the seeded **manager** role keep company-wide `batches.recall`? | Restrict recall to a designated central inventory authority | Keeping it means a branch manager can recall across all branches; either way recall gets an action check | |
| D9 | Is BatchExpiry **deactivatable** for a parapharmacy tenant, or always-on for that vertical? | Always-on for parapharmacy (matches current defaults); R2 fix still applies to workers and other verticals | Deactivatable requires an effective-module exclusion model, server/device cache invalidation and a historical-lot cohort policy | |
| D3 | Is **captured-lot checkout** (iteration 2) a launch requirement, or is iteration 1's read-only FEFO suggestion plus shelf procedure enough for launch? | Iteration 1 for launch; iteration 2 when physical traceability is required | If capture is required at launch, that use case waits for iteration 2 | |
| D4 | Offline **freshness policy** for the lot cache | Refresh before opening a session; cached guidance during the session with visible age; refresh before next session | Stricter cutoff or longer tolerance, each with explicit stale/unavailable behaviour | |

## Rulings that unblock W2 (tenders)

| ID | Question | Rec. | Consequence of the alternative | Ruling |
|---|---|---|---|---|
| D2 | Which **sales surfaces and tenders** does the first client use at launch? (POS yes/no, B2B yes/no, cash, card, cheque, voucher, loyalty, customer account) | Validate both surfaces; enable only the selected list | Unused paths are excluded explicitly, not marked tested | |
| RD3 | Reverse the day-one rule that an **unmapped electronic tender falls to the drawer**? | Reverse: electronic unavailable until a settlement destination is configured | Keeping the fallback leaves CARD→drawer as a marked limitation; electronic tenders then stay excluded for this client | |
| D8 | **Tender-binding transport**: seal the resolved destination in the next SALE_RECEIPT version, or keep an unsealed authored snapshot plus durable server binding? | Sealed, validated reference in the single future SALE_RECEIPT version | Unsealed snapshot avoids sealing treasury policy but needs authenticated linkage and server revisions; not fiscal evidence on its own | |

## Rulings that unblock W1, W7, W6 iteration 2

| ID | Question | Rec. | Consequence of the alternative | Ruling |
|---|---|---|---|---|
| D1 | Branches are **locations of one legal company**? | Yes | Separate legal entities need an intercompany design; cross-company custody is not a branch transfer | |
| D5 | **Staff treasury reach**: branch managers see and move only their branch custody, central treasury has explicit all-location authority? | Yes | Company-wide manager custody only as an explicit policy | |
| D6 | **Cross-branch returns** at launch? | Excluded | Enabling needs receiving-branch stock/lot disposition, original provenance, payout custody and two-branch acceptance | |
| RD4 | Will Treasury **book opening float and drawer drops** before W7 compares repository balances? | Deliver the fiscal-derived close reconciliation first; repository comparison later | Funding float/drop coverage first enables the repository check sooner | |
| D7 | Iteration 2 **lot evidence transport**: line-level lot fields in SALE_RECEIPT vN+1, or a separate evidence stream? | Line-level fields, same version roadmap as D8 | Separate stream preserves fiscal shape but adds linkage, ordering and conflict handling | |

## After rulings

Fable writes the W-LOT brief first (L1–L3, L9, L4–L7 in that order; iteration 1 read-only display; module-gated), then W0 guards, W2, W1, W4, W7. Each brief carries the r4 synthesis "carry into the execution plans" items for its package. Codex implements; Fable gates and merges.
