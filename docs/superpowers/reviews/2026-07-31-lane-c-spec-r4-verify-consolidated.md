# Lane C spec revision-4 scoped verification — consolidated record

**Target:** rev 4 @ `b99b7beb3`. Verdicts: fiscal **APPROVE (code phase may be planned)** + errata;
Codex items 1-6 LANDED, item 7 PARTIAL (3 mechanical); treasury **APPROVE-WITH-FIXES** (1 hard
blocker + bounded boundary contracts). All four orchestrator rulings implemented unsoftened.
Codex detail: `2026-07-31-codex-refund-chain-spec-r4-verify.md`.

## ERRATA ROUND (single fold, rev 4.1 — no further full review; treasury micro-verify on T1-T3 only)
**T1 [BLOCKER]** SalesReturn purpose NOT seeded in FR/TN charts (only Generic:196; TN:277/FR:282
create 709 with NO system_purpose — verified) → spec's "already seeded in all three" premise FALSE;
valid_unbooked class = 500 for tenant #1. Fix: seed FR+TN 709 rows; BackfillRefundWriteOffAccountCommand
covers BOTH purposes; §9 preflight + write-off precheck hasAccountForPurpose for both.
**T2 [IMPORTANT]** offline_receipts refund row contract incomplete: (a) status lifecycle — sync
completion flips status only for source_event_class='offline_receipts' keyed on row id; refund events
carry 'refund_intents' → row stuck 'pending' forever (sync badge grows, purge never collects). Rule
initial status + who flips it. (b) full column sign/source contract: payments_json (positive —
state it), change_due, tendered_amount, cash_rounding_adjustment, tolerance_shortfall, discount
columns; NOT NULL sources: fiscal_hash, previous_hash, **hash_sequence (Z query is
hash_sequence-windowed — unspecified value silently drops refunds from every Z)**, receipt_number,
operator_id/name, payment_method_id, payment_repository_id.
**T3 [IMPORTANT]** endOfDayPreview.ts needs its OWN explicit refund branch (selects
change_due/rounding/tolerance and computes sums §7.3 never addresses).
**T4 [IMPORTANT]** training-original refusal: name the device enforcement site + a device test;
fix the resolveOriginalFiscalEventLocally training_flag attribution.
**T5** MovementIntent idempotency = derived triple (sourceType:sourceId:idempotencyLeg), not a
settable string; state non-collision with bridge fiscal_event:{id}:payment:{i} keys.
**T6** approval_scope new literal → the 4 validator assertion sites in manifest scope.
**T7 [MINOR]** discount-refusal test at the lookup level; server-side discounted-original flag via
refund_policy_alerts; productSalesAggregateRepository net-units ruling + test; explicit scale on
EVERY bcabs/bcadd (device defaults scale 3 — rule 19).
**F1** delete the permission-set alert source (workers bind no Spatie team) — timestamp alerts only.
**F2** test paths: refundZAccounting.test.ts is under __tests__/; localRefundRecordRepository.test.ts
DOES NOT EXIST (drop); ADD zReportService.test.ts + endOfDayPreview.test.ts (both reference
local_refund_records).
**F3/X2** TerminalController cites against dev: :120/:400/:465 (my r3 fold list propagated the
stale worktree numbers — orchestrator error, corrected here).
**F4** §9.6 gains the D1 blast-radius sentence (EVERY terminal created post-D1 defaults v3).
**F7** §2 version-resolution rule stated: argument absent → 3 (back-compat); present with no/unknown
invoice_type_code → throw; M-6 error-type overload noted.
**X1** exact API migration filenames (no generic descriptions).
**X3** §9.6 tenant-specific v3-from-birth claim → deployment-check wording, not repository fact.
