# L-3 Centralize Net-Balance Formula — Opus Adversarial Review

- Item: L-3 (Centralize net-balance formula)
- Commit: 8f65792b65d9168cdf06a532a9b3f514451cd37e
- Branch reviewed: dev-consolidation checkout
- Reviewer: Opus (cross-model adversarial pass; the in-tree "opus-fallback-review" was authored by the implementing runtime and is `opus-review: PENDING`)

## Summary

The change is functionally correct and money-safe: the PHP accessor and frontend
helper both use decimal-string arithmetic (`bcsub`/`bccomp`), no float touches money
in any arithmetic path, and the backend/frontend tests pass (22 backend, 43 frontend,
verified locally). However, the headline claim "backend formula has one source of
truth" is only **partially** met: the commit leaves TWO independently hand-written
formulas on `Partner` — `netBalance()` (PHP/bcmath) and `netBalanceSqlExpression()`
(SQL CASE) — that must be kept in sync manually, and **no test asserts they agree**.
The emitted `net_balance` value always comes from the PHP accessor; the SQL expression
is used only for sort ordering, so a divergence between the two would ship silently.
Verdict: APPROVE-WITH-MINOR-EDITS.

## BLOCKER

None.

- Money/precision: clean. `Partner::netBalance()` uses `bcsub(..., 3)` throughout
  (`apps/api/app/Modules/Partner/Domain/Partner.php:315-318`), inputs are
  `decimal:3`-cast strings (`Partner.php:156-158`), and the frontend helper replaced
  `parseFloat`/subtraction with `bcsub`
  (`apps/web/src/features/partners/partnerNetBalance.ts:19-25`). No `(float)` cast or
  `number_format((float)…)` on money was introduced.
- Sign convention: net = receivable − credit − payable for `Both`, receivable − credit
  for `Customer`, payable for `Supplier` — matches the documented convention; balances
  stay stored as non-negative magnitudes (constraints in
  `migrations/tenant/2026_06_22_140000_add_partner_non_negative_balance_constraints.php`).
- No event-sourcing / GL / hash-chain surface is touched by this commit.
- No migration is added by L-3 (the scale-3 + non-negative migrations belong to sibling
  items L-2/precision work, not this diff), so there is no migration-safety exposure here.

## HIGH

None.

## MEDIUM

1. **"One source of truth" not actually achieved — two parallel formulas can diverge
   silently.** The commit removed the inline CASE from `PartnerController` but moved it
   verbatim into `Partner::netBalanceSqlExpression()`
   (`apps/api/app/Modules/Partner/Domain/Partner.php:328-339`), while the value formula
   lives separately in `Partner::netBalance()` (`Partner.php:309-326`). These are two
   distinct hand-maintained implementations. The implementer's own fallback review admits
   this ("they remain separate implementations…"). The acceptance criterion says "Backend
   formula has one source of truth"; this is one *location*, not one *formula*. Acceptable
   for a LOW-severity item, but the divergence risk is real and uncovered (see #2).

2. **No SQL-vs-PHP parity test; the value test only exercises the PHP path.** The list
   response serializes through `PartnerData::fromModel($model)` →
   `$partner->net_balance` → the PHP **accessor** (`PaginatesResults.php:71-74`,
   `PartnerData.php:84`). Laravel's accessor shadows the `selectRaw` alias of the same
   name, so the SQL `net_balance` alias is consumed **only by `applySorting`**, never
   emitted. Consequently `test_balance_fields_appear_in_list_response`
   (`tests/Feature/Partner/PartnerBalanceListTest.php:111`) and
   `PartnerEntityTest::test_both_partner_net_balance_offsets...`
   (`tests/Unit/Partner/PartnerEntityTest.php:142`) both assert the PHP path only. The
   sort tests (`PartnerBalanceListTest.php:141-199`) only assert relative ordering, never
   that the SQL-computed magnitude equals the PHP-computed magnitude for the same row. If
   someone edits one formula and not the other, the suite stays green while sorting and
   displayed value disagree. Recommend a focused test that selects the raw
   `netBalanceSqlExpression()` alias and asserts it equals `Partner::netBalance(...)` for
   Customer/Supplier/Both rows.

## LOW

1. **Sort/CASE arithmetic verified on SQLite only [NEEDS-REAL-PG].** The
   `test_sort_by_net_balance*` tests run under the SQLite test suite; the CASE expression
   `receivable_balance - credit_balance - payable_balance` is evaluated by SQLite (REAL/
   affinity) rather than PG `numeric`. Ordering is unlikely to differ, but PG-evaluated
   sort parity is not proven by CI. Low risk because the emitted value is PHP-computed and
   columns are NOT NULL (`->default(0)`, so no NULL-arithmetic NULL net_balance).

2. **Type-nullability inconsistency.** Generated DTO type is `net_balance: string`
   (non-null, `packages/shared/types/generated.d.ts`), matching the accessor which never
   returns null. But the frontend `Partner` interface and the `getNetBalance` snapshot
   interface type it as `string | null` (`PartnerListPage.tsx:34`,
   `partnerNetBalance.ts:7`). Harmless (defensive), but the API contract guarantees
   non-null, so the optional/null fallback branch in `getNetBalance` is effectively dead
   for the real list payload.

3. **Display still routes through `parseFloat`.** `displayBalance` is a decimal string,
   but `formatCurrency` (`apps/web/src/lib/formatCurrency.ts:22`) internally does
   `parseFloat(amount)` before `Intl.NumberFormat`. This is presentation-only and
   pre-existing (out of L-3 scope), so not a contract violation — noting it so it is not
   mistaken for a precision regression introduced here.

## Verdict

APPROVE-WITH-MINOR-EDITS

The implementation is money-safe and the tests genuinely pass red-first per the
coordination log; the claim "frontend prefers API string" and "list rows expose
net_balance" hold up. The only substantive gap is the unproven SQL/PHP formula parity
(MEDIUM #1 + #2): the two formulas are duplicated and the test suite cannot catch a
divergence. Recommended before closing: add a parity test asserting
`netBalanceSqlExpression()` selected alias equals `Partner::netBalance(...)` across all
three `PartnerType` cases. None of the findings are fiscal/data-integrity blockers, so
the merge is safe to keep.
