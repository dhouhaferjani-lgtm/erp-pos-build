# Gate r3 synthesis — parapharmacy remediation spec v3

Date: 2026-09-05. Orchestrator: Claude Fable 5.1. Input: spec v3 (Codex fix round 2, working tree) at HEAD `fa000edc3` (cited source unchanged since `b9a5565aa`). Four Opus lenses, read-only, scoped to re-verifying r2 blockers B1–B6 and the majors, plus new-defect hunting in the changed text.

| Lens | File | r2 items | Verdict |
|---|---|---|---|
| Inventory / lot | [gate-r3-inventory](2026-09-05-parapharmacy-remediation-gate-r3-inventory.md) | B4, B5(inv), M07–M11 all CLOSED | ACCEPT-FOR-OWNER-REVIEW (2 plan-level majors) |
| Treasury / GL | [gate-r3-treasury](2026-09-05-parapharmacy-remediation-gate-r3-treasury.md) | B1, B2, B3, M04–M06, M13, M14, M16, M17 all CLOSED | CHANGES-REQUIRED (1 new blocker) |
| Fiscal / POS | [gate-r3-fiscal](2026-09-05-parapharmacy-remediation-gate-r3-fiscal.md) | B1, B5(fiscal), M01, M03, r2 minors CLOSED; M02 PARTIAL | CHANGES-REQUIRED (same blocker + 2 majors) |
| Tenancy / authz | [gate-r3-authz](2026-09-05-parapharmacy-remediation-gate-r3-authz.md) | B5, B6, M12–M15 all CLOSED | CHANGES-REQUIRED (1 new blocker + 4 majors) |

**Gate r3 verdict: CHANGES-REQUIRED, converging.** All six r2 blockers and all seventeen majors are closed with verified evidence; no lens found a new false claim about current code, a sealed-bytes violation, or a silently decided owner row (D1–D9, RD2–RD4 all OPEN). The two remaining blockers were introduced by v3's own corrections to B2 and B3. Round 3 is a narrow textual fix; no further owner input is needed to close it.

## Blockers (spec approval)

| # | Blocker | Lenses | Verified by orchestrator | Minimum correction |
|---|---|---|---|---|
| R3-B1 | **`non_money_destination` branch "before generic repository resolution" drops the leg's revenue and VAT GL.** The sale's proportional `Cr Revenue` + `Cr VatCollected` lines are written only inside `createPOSPaymentEntry` (`GeneralLedgerService.php:3957-3965`, reached via `TreasuryReceiptBridge.php:1515`); skipping it re-creates the W4-9 books-versus-filing mismatch (`:3885-3893`) and strands the store-voucher `Cr PosTenderClearing` (`ReceiptPaymentService.php:372-385`, warned at `PosCoreReceiptProjection.php:1616-1632`). W7 cannot detect it because both its sides are fiscal. | treasury NEW-1, fiscal NEW-1 | Yes: read `GeneralLedgerService.php:3944-3966` and the maturity override at `TreasuryReceiptBridge.php:1457-1458,1556` | Rewrite spec:165 so the non-money class **keeps the shared payment entry** and only overrides the debit account (liability / receivable / clearing) and suppresses the repository movement, exactly the shipped maturity-leg shape (`$cashAccountOverrideId` + `$shouldRecordMovement = false`). Add an acceptance row: voucher-tendered sale posts revenue + VAT + clearing debit, zero repository movement. |
| R3-B2 | **Terminal-scoped tender projection rests on a terminal identity authority that does not exist.** `terminal_id` is caller-supplied and validated only against tenant+company (`ClaimTerminalRequest.php:33-40`); the precedent policy endpoint is company-wide and ungated (`PosPaymentPolicyController.php:13-20`); there is no terminal middleware. A branch-A cashier can read branch-B bindings and bind A's tender leg to B's custody, defeating W1 via its own prerequisite. Same class as the August table-management finding ("X-Client-Type is the only device trust"). | authz B-r3-1 | Not independently re-read; lens citations are specific | W2/B3 must state the terminal authority the projection uses: the claimed terminal's persisted `location_id` (server-side, from the terminal record, never from the request), the token/claim that binds a session to that terminal, and that the projection is filtered by it. If no such binding exists today, add it to W2's seams and acceptance as a prerequisite; do not describe it as existing. |

## Majors (execution plan; fold into v4 or explicitly reject with citation)

- **M02 PARTIAL / fiscal NEW-2:** `ProductDetailDrawer.tsx:20,114-116,297-302` has an unconditional `stock_lots` tab gated only on Merchandising (`:84`), a second shipped Spec-2 lot surface W6 does not name. Add to W6 seams and R2 gating.
- **fiscal NEW-3:** spec:202 "drain and verify no unsettled projections incl. offline pending outboxes" is not server-observable and "or postpone" is unbounded. Bind classification changes to seal-time authored classification instead.
- **authz N-1:** W2's rebind surface needs `treasury.manage`, which manager lacks (seeder `:590` vs `:818`); W2 has no reseed clause.
- **authz N-2:** "unknown entitlement is blocked work" has no mechanism: the registry silently excludes (`FiscalEventProjectionRegistry.php:248-266`) and L6 files it non-alerting. Name the blocked-row writer.
- **authz N-3:** the mandated company-ownership read is a tenant-DB query inside a central-only, never-throws resolver (`DefaultModuleActivationResolver`). Name where the check actually lives.
- **authz N-4:** `BatchController::expiring()` passes unvalidated `location_id` to `whereRaw` (`FEFOInventoryService.php:852-856`), PG 500. L1 fix must validate.
- **inventory N-1:** W5/L4 names only the negative lot arm; `StockAdjustmentService.php:1446-1447` auto-credits DEFAULT on the positive branch (double-credit / re-inflation ordering hazard vs L9).
- **inventory N-2:** L6 schedules `--fail-on-drift` on a PG-only query with zero dedicated tests, reachable only via a parked filter lane. Name the test obligation.
- **treasury:** `PaymentInstrumentKind` (+ mandated TS mirror) is an undeclared existing classification source; LOYALTY/MEAL_VOUCHER destinations have no account purpose at HEAD (name the purposes W2 must seed); §5 rows W1↔W2 are mutually circular (verified: W1 needs W2's terminal projection, W2 needs W1's terminal projection) — pick one owner.

## Minors

fiscal NEW-4 stale pointer (`receipt_count` is `zReportService.ts:753`, not `:640`); inventory N-3..N-6 (web float consumers unanchored, `lot_identifications` unique key unstated, channel listener on flat movements, WAC lock cite is `:92-96`); authz minors on new-table unique keys and `batches.identify` rollout; treasury minors on glossary tender rows.

## Preserve

Everything in the r2 synthesis Preserve list, plus verified-correct in v3: the B2 retraction and twelve-code seeder census; the D7/D8 single roadmap (§5 spec:416); L9 ordering and acceptance; the flat-reason GL path (`directionForRow():183-192` → null posting, `InventoryGlPostingBuffer:77-80` sole dispatcher) with the no-op guard kept; the tenant-inheritance vs company-licensing distinction (`CompanyConfigService.php:18-20`); the seven-role delta matching the seeder exactly.

## Owner decisions

Unchanged: D1–D9, RD2–RD4 all OPEN. No new rows required by r3.

## Next

Codex fix round 3 → spec v4 (handover `docs/handoff/HANDOVER-parapharmacy-remediation-codex-spec-fix-round-3-2026-09-05.md`). Fable gate r4 = treasury + authz lenses only, scoped to R3-B1, R3-B2 and the majors; fiscal and inventory re-read only if v4 touches their sections beyond those items.

VERDICT: CHANGES-REQUIRED
