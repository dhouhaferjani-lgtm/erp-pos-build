# H-6 Partner Money Precision — Opus Adversarial Review

Date: 2026-06-23
Reviewer: Opus 4.8 (1M), adversarial cross-model pass
Commit: `cfc8e95c5` — "Phase 0.1.17: Align partner money precision"
Item: H-6 (work-list `docs/superpowers/audits/2026-06-22-balance-conventions-audit/work-list.md`)
Claim under test: partners money columns altered to `NUMERIC(15,3)` via tenant migration; casts scale 3; credit-limit validation rejects 4dp.

## Summary

The implementation matches its claim and survives the strongest refutation lenses
(money loss, sign convention, fiscal hash-chain, migration ordering). The core
correctness argument holds because `journal_lines.debit/credit` are
`decimal(15,2)` (`database/migrations/tenant/2025_11_30_100000_create_journal_entries_table.php:51-52`),
so narrowing the *denormalized cache* from scale 4 to scale 3 cannot drop any
real money — the GL source already carries only 2 decimals, and the cache is
recomputed from GL by `refreshPartnerBalance()`. Fiscal canonical payloads are
validated at the *payload's* dynamic `currency_scale` (allowlist `{0,2,3}`), not
a hardcoded scale 4, so the cast change cannot break the fiscal-hash contract.
No leftover scale-4 casts/regex remain for partner money fields.

The residual concerns are real but sub-blocking: an offline-POS device wire
contract drift that is unverified in this repo, a CI gap where the PG schema
assertion is skipped and the SQLite cast test would pass regardless of column
type, and a silent data-rounding of pre-existing rows with no in-migration
note. I do not find a fiscal/money/data-integrity defect.

## BLOCKER

None.

Refutations attempted and defeated:
- **Money loss on narrowing scale.** Defeated: GL source columns
  `journal_lines.debit/credit` are `decimal(15,2)`; the cache (`partners.*_balance`)
  is recomputed from those sums in `PartnerBalanceService::getPartnerBalance()`
  (`PartnerBalanceService.php:53-63`). Scale 3 strictly exceeds the source's scale 2.
- **Fiscal hash-chain break from cast change.** Defeated: canonical money fields are
  validated by `FiscalPayloadConstraintValidator::moneyRegex($scale)`
  (`FiscalPayloadConstraintValidator.php:2612-2619`) where `$scale` comes from the
  payload's `currency_scale` (allowlist `{0,2,3}`, lines 172-180), not a fixed 4.
  Balance-snapshot DTOs (`AccountChargeBalanceSnapshotDTO`,
  `AccountChargeCreditDecisionDTO`) treat balances as opaque strings. The
  `credit_balance_before`/`projected_net_balance_after` payload is authored on the
  POS device (no server-side builder exists under `app/`), so the cache cast does
  not feed a server-authored fiscal event at scale 4.
- **Sign convention.** Defeated: `liabilityMagnitude()` still stores non-negative
  magnitudes (`credit - debit`, clamp-to-zero) and `netBalance()` is
  `receivable - credit - payable`; both now consistently at scale 3
  (`PartnerBalanceService.php:383-408`, `Partner.php:309-326`).
- **Migration ordering / fresh tenant.** Defeated: original creators
  (`2025_12_06_100001` scale 4, `2026_03_11_600000` credit_limit scale 4) run before
  H-6 `2026_06_22_130000`, and the non-negative-constraint migration
  `2026_06_22_140000` runs after — ordering is monotonic and safe.

## HIGH

None.

## MEDIUM

1. **Offline-POS device sync contract drift is unverified.**
   `PosCustomerMirrorResource.php:37-39` passes the raw cast values
   (`$this->receivable_balance`, `credit_balance`, `credit_limit`) into the device
   sync payload, so a deployed device now receives `"500.000"` where it previously
   received `"500.0000"`. The matching server test was updated
   (`PosCustomerSyncControllerTest` lines 244-263, 276-287). However, the POS device
   parser is not in this repo path (`apps/api/app/`), so the impact on already-deployed
   IziPOS/Otospex terminals that hold an older sync snapshot or do scale-pinned string
   handling is unverified. This should be confirmed against the device-side money
   parser before relying on it in production. [HYPOTHESIS — device code out of scope of this checkout]

2. **CI does not exercise the load-bearing assertion (false-confidence under SQLite).**
   `phpunit.xml:41` pins `DB_CONNECTION=sqlite`. The migration early-returns on
   sqlite (`2026_06_22_130000_..._scale_3.php:158-160`) and SQLite ignores
   `NUMERIC(15,3)` scale entirely, so `PartnerMoneyPrecisionTest::test_partner_money_columns_are_decimal_15_3_on_postgresql`
   is `markTestSkipped` under CI (test lines 685-686). The cast round-trip test
   (`test_partner_money_casts_preserve_currency_scale_3`) passes on SQLite *because
   the `decimal:3` cast formats in PHP regardless of column type* — it would pass even
   if the migration were missing. Per project memory, CI runs only the SQLite Unit
   suite + a hardcoded allowlist and never runs `tests/Feature` on PG. The real-PG
   verification (`autoerp_h6_test`, 13 assertions) was a manual local run, not a gated
   check. Net: the only test that actually proves the migration ran correctly is not
   in any automated gate. [NEEDS-REAL-PG]

3. **Existing rows are silently rounded with no in-migration note or refresh.**
   `ALTER TABLE partners ALTER COLUMN ... TYPE NUMERIC(15,3)` will round any
   pre-existing 4th-decimal value on tenant DBs. This is acknowledged only in the
   Codex review's "Residual Notes", not in the migration file itself, and there is no
   post-ALTER `refreshAllPartnerBalances` call. It is functionally safe (cache is
   GL-derivable, GL is scale 2) but the rounding-on-deploy behavior deserves a comment
   in the migration for operators reading it during a tenant rollout.

## LOW

1. **Scale-4 comparison left on a now-scale-3 value.**
   `Partner::hasOutstandingBalance()` (`Partner.php:344-350`) still does
   `bccomp($netBalance, '0', 4)`. Benign for a scale-3 string (trailing zero), but
   inconsistent with the scale-3 alignment this item claims to enforce. Likewise
   `PartnerBalanceService::reconcileSubledger()` and `getPartnerStatement()` keep
   scale-4 BCMath (`PartnerBalanceService.php:203,216,289-292`) — out of this item's
   declared scope (statement/reconciliation), so acceptable, but worth noting the
   contract is now non-uniform within the same service.

2. **Migration not explicitly idempotent.** `up()` lacks an `IF`/guard; re-running
   `ALTER COLUMN ... TYPE` is naturally idempotent on PG, so this is a nit, but other
   migrations in this batch (`..._140000` constraints) use `DROP CONSTRAINT IF EXISTS`
   guards for re-run safety and this one does not follow that convention.

3. **`down()` widens back to `NUMERIC(15,4)` but cannot restore rounded data.** Expected
   for a narrowing migration; documented here only for completeness.

## Verdict

APPROVE-WITH-MINOR-EDITS

The fiscal/money/data-integrity core is correct and well-defended: no float touches
money, BCMath scale is consistently 3 on the cache path, sign conventions preserved,
fiscal payload validation is scale-dynamic (no hash-chain break), migration ordering
is safe, and the GL source (scale 2) guarantees the narrowing loses no money. The
TDD red-first evidence and real-PG run are credibly documented. Remaining items are
verification/operability gaps, not defects: confirm the offline-POS device parser
tolerates the `"x.000"` (3-decimal) sync strings (MEDIUM-1), recognize that the
migration-correctness assertion is not in any automated gate (MEDIUM-2), and add an
operator-facing note about deploy-time rounding of existing rows (MEDIUM-3).
