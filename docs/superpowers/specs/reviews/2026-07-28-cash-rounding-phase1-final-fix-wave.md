# Final review fix wave — 10-item list

**Branch:** `feat/pos-cash-rounding` · **Base HEAD:** `e094ada49`
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.cash-rounding`
**Date:** 2026-07-28

A prior agent applied part of this list and died before committing. This wave
audited what was already in the working tree, completed the remainder, fixed
one broken assertion the prior agent left behind, ran the covering checks, and
landed everything as a single commit.

---

## Item-by-item status

| # | Item | Applied by |
|---|---|---|
| 1 | Migration logs the REWRITTEN rows + test asserts it | prior agent (assertion **repaired** by this wave — see below) |
| 2 | §0.4 pre-flight query, resync + re-drive actions, resolver-fallback 🎫 | prior agent |
| 3 | §4 rollback — code rewrite is a third non-additive effect | prior agent |
| 4 | §0.1 second lock window (`journal_entries` SHARE lock) | prior agent |
| 5 | §5.1 applied-with-no-row / terminal-not-found soft-return | prior agent |
| 6 | §0.3 `change_due` keys on the v3 DEVICE BUILD | prior agent |
| 7 | **§6 additions (three)** | **this wave** |
| 8 | Never flip `is_cash_tender` with unsettled projections | prior agent |
| 9 | Citation nits (§1.1 heading, `:67-72`/`:75`, resolver `:172-185`) | prior agent |
| 10 | `TreasuryReceiptBridge` netting docblock | prior agent |

Nothing pre-applied was duplicated or reverted.

---

## Applied by this wave

### Item 7 — three §6 pre-cutover additions

Added as items **9, 10, 11** under a new "Found in final review — server-side,
verify/resolve before cutover" heading in
`docs/handoff/cash-rounding-phase1-deploy-checklist.md`. Appended rather than
interleaved so items 1-8 keep their numbers (§7's "at minimum items 1, 2, 6, 7
and 8" reference stays valid); §7 step 2 was extended to place the three new
items on the right gate.

Every claim was verified against code before being written:

**(a) `findByPurpose` ignores `is_active`** — confirmed.
`apps/api/app/Modules/Accounting/Domain/Account.php:238-243` is
`forCompany()->withPurpose()->first()`; the `active` scope exists at `:196` and
is never applied. Both cash-rounding GL paths inherit it:
`GeneralLedgerService::hasAccountForPurpose` (`:4369-4372`, the pre-flight
probe) and `getAccountByPurpose` → `findByPurposeOrFail` (`:4355-4358`, the
posting path). An operator who retires the 658/758 tolerance account by
unticking *active* gets neither a missing-purpose alert nor a graceful skip.
Written up as pre-existing platform behaviour with an explicit
**do-not-change-`findByPurpose`-in-this-branch** warning (every GL writer reads
through it), plus a concrete pre-cutover verification.

**(b) Approval evidence not amount/target-checked** — confirmed.
`TreasuryReceiptBridge::hasTenderToleranceApproval` (`:813-821`) returns true on
mere presence of an `approval_scope === 'tender_tolerance_override'` reference;
`alertIfShortfallExceedsConfig` (`:711-713`) returns before the ceiling is
computed. The scope check itself is correct and worth keeping — the gap is that
one reference mutes the beyond-config alert for a shortfall of any magnitude.
Framed as an observability hole (the write-off still posts), unreachable in
Phase 1, cutover blocker not deploy blocker.

**(c) Parked refund-tolerance direction** — confirmed.
Refund detection at `TreasuryReceiptBridge.php:373-374`;
`postToleranceWriteoffEntry` (`:486-545`) computes a plain
`bcsub($total, $tendered)` (`:515`) with no refund branch, so a short-tendered
canonical REFUND books the same direction as a sale. The docblock at `:482-484`
already records this as a deliberate park (entry 1 has a spec-mandated
reversal, entry 2 has none). Written up as a 🔓 spec question that must be
resolved before device-side refund authoring on a v3 build — 758 vs 658, or
whether a refund may carry a tolerance gap at all.

### Repair — prior agent's negative log assertion was failing

`test_migration_does_not_log_a_rewrite_for_an_already_canonical_row` used
`Log::shouldHaveReceived('warning')->withArgs(…)->times(0)`, which does not
express "never called": Mockery verifies a spy expectation as *called at least
once* before the count is consulted, so it failed on a spy that correctly
received nothing:

```
Method warning(<Any Arguments>) from Mockery_2_Illuminate_Log_LogManager
should be called at least 1 times but called 0 times.
```

Replaced with a direct absence assertion that pins the event key exactly and
the context loosely — every `Log::warning` the migration emits passes an array
context, so this avoids the full-argument-list brittleness the prior agent's
comment was (correctly) trying to dodge:

```php
Log::shouldNotHaveReceived('warning', [
    'cash_rounding.backfill.rewritten_cash_code',
    \Mockery::type('array'),
]);
```

Also added the symmetric guard that the *skip* warning does not fire either — a
canonical row is neither rewritten nor ambiguous. No production code changed;
this was purely the assertion mechanism.

---

## Covering checks

| Check | Result |
|---|---|
| A1 test on **PostgreSQL** (`phpunit-pgsql.xml` → `autoerp_cash_rounding_test:5433`) | **17 passed, 52 assertions** |
| A1 test on **sqlite** (default `phpunit.xml`) | **17 passed, 52 assertions** |
| `pint --test` on the 3 touched PHP files | **pass** |
| `phpstan analyse` — `TreasuryReceiptBridge.php` | **No errors** |
| `phpstan analyse` — the A1 migration | **No errors** |
| Checklist code fences | **26 — even, balanced** |

PG run command (no `.env.testing` in this worktree; params passed inline):

```bash
cd apps/api && DB_HOST=127.0.0.1 DB_PORT=5433 \
  DB_DATABASE=autoerp_cash_rounding_test \
  DB_CENTRAL_DATABASE=autoerp_cash_rounding_test \
  DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret \
  php artisan test -c phpunit-pgsql.xml \
  tests/Feature/Treasury/PaymentMethodCashTenderTest.php
```

### PHPStan note — test-file errors are out of scope, not new drift

Running phpstan directly at `tests/Feature/Treasury/PaymentMethodCashTenderTest.php`
reports 21 errors, up from **16 on the `e094ada49` version of the same file**
(measured by temporarily restoring the HEAD copy). All 5 new errors are the two
patterns the file already used throughout: `stdClass|null` property access on
`DB::table(...)->first()`, and `Log::shouldHaveReceived()` /
`Log::shouldNotHaveReceived()` being absent from the `Log` facade's static
signature. `phpstan.neon` sets `paths: app/`, so **neither `tests/` nor
`database/migrations/` is analysed by the project config or CI** — the two files
that *are* in-scope-shaped (`TreasuryReceiptBridge.php` and the migration) are
both clean. Flagged here rather than silently absorbed.

---

## Files changed

- `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php` — item 10 (prior agent)
- `apps/api/database/migrations/tenant/2026_07_28_100000_add_is_cash_tender_to_payment_methods.php` — item 1 (prior agent)
- `apps/api/tests/Feature/Treasury/PaymentMethodCashTenderTest.php` — item 1 (prior agent) + assertion repair (this wave)
- `docs/handoff/cash-rounding-phase1-deploy-checklist.md` — items 2-6, 8, 9 (prior agent) + item 7 and the §7 gate line (this wave)

All landed as **one** commit.
