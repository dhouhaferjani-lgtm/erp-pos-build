# POS Go-Live Plan v2 — Adversarial Review

Scope: `docs/superpowers/plans/2026-05-12-pos-go-live-plan-v2.md`, checked against the round-1 review and spot-checked against the current worktree on 2026-05-12.

## Round-1 Closure Check

v2 addresses the round-1 P1/P2 findings in structure: PR #1 keeps the backend remote-close route alive, adds the old-payload 422 test, names phpstan/pint/phpunit commands, and fixes Path B instead of deprecating it; PR #2 narrows scope to current receipt fields and adds empty-string guards; PR #3 adds terminal context, envelope unwrap, offline-cache resolution, stale memory-note reconciliation, and null operator-cap semantics; PR #4 adds release provenance, real app-data paths, remote-support decision record, clock/NTP, hardened chain-break triage, and support-log linkage.

Remaining gaps are below. Anchor spot-checks resolved for the main v2 paths: `ZReportSyncController.php`, `ReportGenerationService.php`, `shiftApi.ts`, `receipt_template.rs`, `voucher_ticket.rs`, `buildReceiptData.ts`, `discountApi.ts`, `DiscountController.php`, `operatorStore.ts`, `operatorPinRepository.ts`, `routes.php`, and `tauri.conf.json`-driven app identifier assumptions. The stale v1 `app/Http/Controllers/POS/...` anchor is gone.

## PR #1 — Z-report cash-count contract

- [P1] The Path A test data contradicts the corrected cash formula and will lead Codex to preserve the wrong device value. v2 says the server recompute formula is `Σ(payment.amount) - Σ(receipt.change_due)` for cash, and its Path B test correctly expects `170 - 70 = 100`. But the Path A sync-archive test still posts `expected_amount: '170.000'` / `actual_amount: '170.000'` for the same two receipts and says those values match the local formula. They do not. With no opening cash, both local drawer cash and Path B should be 100, not 170. Update the Path A payload and assertions to use 100, or explicitly add a nonzero opening-cash term if that is intended. As written, the plan can pass PR #1 while archiving an over-tender inflated cash count.

- [P2] The cash discriminator for Path B is still ambiguous. v2 says Codex may choose `payment_method.code = 'CASH'` or `payment_method.is_physical = true`, but `is_physical` covers non-cash tenders such as checks/vouchers in the seed data. Subtracting receipt-level `change_due` from every physical method would understate those methods on split tenders. Tighten the plan to cash only, preferably using the immutable `pos_receipt_payments.payment_method_code = 'CASH'` snapshot when available, with live `payment_methods.code` only as a fallback for legacy rows.

## PR #2 — Receipt legal-field conditional rendering

- [NIT] The plan is now executable. It correctly narrows to current fields and guards `Some("")`. One small precision edit: explicitly say whether empty-string guards should use `trim().is_empty()` or raw `is_empty()`. The current text says empty-string, but whitespace-only company values are also orphan-label risks if upstream validation has not normalized them.

## PR #3 — Discount permissions fail-closed

- [NIT] The implementation target is precise enough, but the plan should name the terminal-code source at call sites, likely `terminalStore` or the current terminal object already loaded during PIN verification. The API contract is now clear; this is only to reduce implementation churn.

## PR #4 — Operator runbooks

- [P2] The chain-break B1 SQL is safer than v1 but still too executable for a fiscal recovery path. `offline_receipts` does have `voided` and `void_reason`, but marking local-only receipts `voided = 1` after they advanced the local hash chain does not by itself explain how `terminal_state.last_hash/hash_sequence`, subsequent `previous_hash` values, and local Z-report totals remain coherent. Either remove the B1 SQL from the operator-facing runbook and make hash-mismatch recovery Synerivia-only, or add a precise, tested internal-only reconciliation procedure. For deploy-phase-1 docs, the safer minor edit is: backup, identify exact rows, stop, escalate.

- [NIT] `restore.md` proposes `SELECT name, (SELECT COUNT(*) FROM [name]) ...`, which is not valid SQLite as written because `[name]` is an identifier literal, not dynamic SQL over each table name. Keep it as pseudo-code or replace with a generated PowerShell/sqlite loop that runs one `SELECT COUNT(*) FROM <table>` per table.

## Execution Precision Check

v2 is much closer than v1 and is mostly precise enough for Codex to implement from the plan plus the named anchors. The exception is PR #1: the Path A expected amount must be corrected before implementation, because the current numeric example conflicts with the plan's own Path B fix and acceptance criteria. PR #4's chain-break B1 path should also be downgraded to escalation-only or expanded into a tested internal procedure before being handed to operators.

REQUEST-CHANGES
