# L-2 — Opus Adversarial Review (Liability statement running balance sign)

- Item: L-2 — Statement running balance sign for liability statements
- Commit: e0f4ececc6aa1fdd5135a5e332a3a4e2e7a736f8
- Reviewer: Opus (adversarial, cross-model second pass — the prior "opus-fallback-review" was a Codex-runtime self-review with `opus-review: PENDING`, NOT an Opus pass)
- Verdict: APPROVE-WITH-MINOR-EDITS

## Summary

The change makes `PartnerBalanceService::getPartnerStatement()` present credit-normal
positive running balances for `CustomerAdvance` and `SupplierPayable`, while leaving the
debit-normal path for receivable/general statements untouched. The implementation is
correct, minimal, and contained to statement presentation. It introduces no float/money
casts, no GL posting changes, no event restructuring, and no migration. Money math stays
on `numeric-string` + bcmath. PHPStan L8 passes; all 20 tests in
`PartnerBalanceServiceTest` pass (verified locally). The claim holds.

I could not refute the core fiscal claim. The findings below are quality/coverage gaps,
not correctness defects — hence APPROVE-WITH-MINOR-EDITS rather than APPROVE.

## BLOCKER

None.

- No money/quantity value is cast through `(float)` or `number_format` in the changed
  lines. `credit`/`debit` arrive as `(string)`, are flagged `numeric-string`, and are
  combined only via `bcsub`/`bcadd` (`PartnerBalanceService.php:280-292`).
- No fiscal/domain event is renamed, restructured, or deleted. `PartnerBalanceUpdated`
  and the GL posting path are untouched; this is a read/presentation method.
- No migration, no PG CHECK constraint, no backfill — nothing requiring real-PG
  verification.
- Double-entry / posted-vs-draft semantics are unchanged: the query still filters
  `journal_entries.status = 'posted'` (`PartnerBalanceService.php:243`).

## HIGH

None.

The red-first claim is genuine and the test is meaningful. With the pre-change formula
(`bcsub(runningBalance, credit) + debit`) a `CustomerAdvance` credit of 50 yields
`0 - 50 + 0 = -50.0000`, which the new assertion `assertSame('50.0000', ...)` rejects.
Reverting the branch would fail the test, so it is not a false-confidence test.

## MEDIUM

### M1 — SupplierPayable test exercises only a credit movement; the credit-normal `decrease` operand is never asserted for payables

`test_supplier_payable_statement_uses_credit_normal_running_balance`
(`PartnerBalanceServiceTest.php:272-289`) posts a single 80.00 credit and asserts
`80.0000`. It never posts a debit (supplier payment) against the payable subledger, so it
does not prove that a debit correctly *decreases* a credit-normal payable balance. The
shared `$isCreditNormalPurpose` branch IS exercised bidirectionally by the
`CustomerAdvance` test (credit 50 then debit 20 → 30.0000,
`PartnerBalanceServiceTest.php:256-270`), so the operand-swap logic is covered in
aggregate — but the payable-specific regression would still pass even if a future change
broke the debit direction for payables only (e.g. a per-purpose refactor). Add a payment
movement to the supplier test (e.g. credit 80, debit 30 → expect 50.0000) to close the
gap. Low real risk today because both liability purposes share one code path.

## LOW

### L1 — Untyped (no-`purpose`) statements still mix sign conventions into a debit-normal running balance

When `getPartnerStatement()` is called with `purpose = null` (the controller default when
no `?purpose=` query arg is supplied — `PartnerBalanceController.php:72-90`),
`$isCreditNormalPurpose` is `false` (`in_array(null, [...], true)`), so the running
balance stays debit-normal. A partner whose unfiltered statement contains advance/payable
lines will show those credit movements as negative magnitudes — the very behavior this
item set out to fix, just for the mixed/untyped path. This is consistent with the stated
"statement presentation only" / single-purpose scope and matches pre-existing behavior, so
it is not a regression. Worth a one-line scope note that the credit-normal presentation is
purpose-scoped and a mixed statement remains debit-normal by design.

### L2 — Hardcoded bcmath scale 4 is propagated, and diverges from the scale-3 currency cache

The new code keeps the pre-existing hardcoded scale `4` in `bcsub(..., 4)` / `bcadd(..., 4)`
(`PartnerBalanceService.php:289-291`), producing `running_balance` strings like
`50.0000`. The denormalized money cache and `getPartnerBalance` use scale 3 with a
`precision-ok` annotation (`PartnerBalanceService.php:63`,`:390`). The statement running
balance is neither currency-resolved (`CurrencyScaleResolverInterface`) nor scale-3
floored. This is pre-existing and acknowledged in the Codex fallback review's "Residual
Notes", and statement values are display-only, so it is out of L-2's sign scope — but it
means the running balance is not on the canonical money precision contract and should be
tracked (it overlaps L-3 net-balance centralization).

### L3 — Prior "opus-fallback-review.md" is mislabeled as an Opus pass

`docs/superpowers/reviews/2026-06-22-l2-...-opus-fallback-review.md` carries
`opus-review: PENDING` and states a "true Opus reviewer is not reachable", i.e. it is a
Codex-runtime self-review, not an independent Opus pass. The work-list/coordination
entries cite it alongside the Codex review as if two independent reviews occurred. This
review file satisfies the actual cross-model Opus pass; the coordination log's
`opus-review: PENDING` should be flipped to reference this file.

## Verdict

APPROVE-WITH-MINOR-EDITS.

The implementation is fiscally correct, scope-contained, free of float/money/precision
violations, and backed by a genuine red-first test. No BLOCKER or HIGH. Recommended (non-
blocking) edits: add a debit movement to the supplier-payable test (M1); note the
purpose-scoped sign behavior for untyped statements (L1); track the hardcoded scale-4 /
currency-scale gap with L-3 (L2); update the `opus-review: PENDING` marker (L3).
