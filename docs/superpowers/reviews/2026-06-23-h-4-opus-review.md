# H-4 — Reject Unbalanced Journal Posting — Opus Adversarial Review

Date: 2026-06-23
Reviewer: Opus 4.8 (adversarial, cross-model second pass)
Commit: `c54a9d6da` — "Phase 0.1.15: Reject unbalanced journal posting"
Item: H-4 (work-list `docs/superpowers/audits/2026-06-22-balance-conventions-audit/work-list.md:162`)
Claim under test: `GeneralLedgerService::postEntry()` rejects unbalanced draft entries BEFORE mutating status/hash or dispatching `JournalEntryPosted`.

## Summary

The core claim holds. The balance check is placed before any status/hash/posted/actor mutation and before the `JournalEntryPosted` dispatch, and the guard lives in the shared private `postEntryWithOptionalActor()` so all production posting paths (via `postEntryAndDispatchPostedEvent` / `postSystemGeneratedEntryAndDispatchPostedEvent` / `postEntryAndDispatchPostedEventAfterCommit`) inherit it. I verified red-first independently: removing the guard makes `test_posting_unbalanced_journal_entry_is_rejected_without_mutation` fail at the `$this->fail(...)` line (the entry posts). No float touches money — `bcadd`/`bccomp` on `numeric-string` columns only.

One real correctness gap survives refutation: the guard compares totals at the **currency display scale**, not the **storage scale** of the `decimal(15,3)` `debit`/`credit` columns. For supported scale-0 currencies (XOF/XAF/… — relevant to this product's North Africa markets), the guard truncates fractional line amounts before comparing and can declare a genuinely unbalanced entry "balanced." Plus a secondary concern: H-4 turns the `total == subtotal + tax_amount` invariant in `createFromInvoice()` into a hard post-time failure with no rounding leg, and the regression was "fixed" by editing the fixture rather than the create path.

## BLOCKER

None. The guard does fail-closed before any irreversible mutation, so no hash-chain position is consumed and no event leaks on the rejection path. Not a data-corruption or unsafe-migration change (no migration at all).

## HIGH

### H1 — Balance guard compares at currency *display* scale, masking sub-unit imbalances for scale-0 currencies

`GeneralLedgerService.php:1258-1271`:

```php
$scale = $currencyCode !== null
    ? $this->scaleResolver->getScale($currencyCode)
    : $this->scale();

foreach ($entry->lines as $line) {
    $totalDebit = bcadd($totalDebit, $line->debit, $scale);
    $totalCredit = bcadd($totalCredit, $line->credit, $scale);
}

if (bccomp($totalDebit, $totalCredit, $scale) !== 0) { throw ... }
```

`journal_lines.debit`/`credit` are stored `decimal(15,3)` (`database/migrations/tenant/2025_11_30_100000_create_journal_entries_table.php:51-52` widened by `2026_03_11_200000_widen_monetary_columns_to_scale_3.php`) and cast `decimal:3` (`JournalLine.php:54-55`). The guard, however, sums and compares at `$this->scaleResolver->getScale($currency)`, which is **0** for XOF/XAF/JPY/etc. (`CurrencyScale` map). With scale 0, `bcadd("100.500", "0", 0) = "100"`, so an entry with `Dr 100.500 / Cr 100.000` (a 0.500 imbalance that storage can hold) passes `bccomp(...,0) === 0` and posts as balanced. I reproduced this directly:

```
scale=0: totalDebit=100 totalCredit=100 bccomp=0  (treated as balanced)
scale=3: totalDebit=100.500 totalCredit=100.000 bccomp=1
```

Because the columns can physically store scale-3 values regardless of the entry's currency, an integrity guard must compare at the **storage scale (3)** or at the max precision present — not at the currency display scale. Comparing at the display scale defeats the guard's entire purpose for a supported currency class in the target market. Recommend: compare at `max(3, currencyScale)` (or unconditionally at the line storage scale 3) for the balance check, while keeping currency scale only for any display/event rounding. The current test (TND, scale 3) cannot catch this; add a scale-0-currency regression.

## MEDIUM

### M1 — `createFromInvoice()` has no rounding leg; H-4 converts a silent imbalance into a hard post failure, and the test "fix" hid it rather than addressed it

`createFromInvoice()` debits AR by `invoice->total` (`:147`) and credits Revenue by `invoice->subtotal` (`:158`) + VAT by `tax_amount` (`:170`). The entry is balanced only if `total == subtotal + tax_amount`. The original `test_posting_journal_entry_adds_hash` set `total=120` while `subtotal=100, tax=0` (a genuine 20.00 imbalance); the commit "fixed" the regression by changing the fixture to `total=100.00` (diff + `GLIntegrationTest.php:320`). That hides, not resolves, a real production failure mode: any invoice where `total` is rounded independently of `subtotal + tax` (line-rounding residue, total-level discount, multi-line VAT rounding) will now build an unbalanced entry and the guard will THROW at post time — blocking invoice GL posting that previously posted (incorrectly, but without failing). Rejecting bad data is the right direction, but there is no balancing/rounding line added in the create path, so legitimate rounding-mismatch invoices become un-postable. This is arguably outside the stated "`postEntry()` validation only" scope boundary, but it is a direct, foreseeable consequence of this change and should be tracked as a follow-up (rounding leg in `createFromInvoice` and the other create-then-post writers) rather than left as a latent post-time exception.

### M2 — Regression test exercises only the public `postEntry()` path, not the production after-commit path

`test_posting_unbalanced_journal_entry_is_rejected_without_mutation` calls `postEntry()` directly. Production GL posts run through `postEntryAndDispatchPostedEventAfterCommit()`, where the throw fires inside a `DB::afterCommit()` callback after the line-creating transaction has already committed (`:84-92`). The guard structurally covers that path (shared private method), so this is not a correctness hole, but there is no test asserting the after-commit-path behavior (entry already committed as Draft, exception propagation out of the afterCommit callback). Add coverage if the after-commit failure semantics matter for any caller that posts an externally-constructed (possibly unbalanced) entry. Low practical risk because all create paths build balanced lines by construction.

## LOW

### L1 — Empty / all-zero entries pass the guard

An entry with zero lines, or only `0/0` lines, has equal totals and posts. Both prior reviews noted this; it is acceptable for the H-4 scope (balance equality only), but a "must have ≥2 non-zero lines" journal-validity rule remains unwritten. Track separately.

### L2 — Exception message interpolates raw totals

`"...total debit {$totalDebit} does not equal total credit {$totalCredit}."` is fine for an internal `InvalidArgumentException`; just ensure it is not surfaced verbatim to end users (it is a 422/500-class internal invariant, not a user-facing validation message).

## Verification performed

- `git show c54a9d6da` — read full diff (code + tests + docs).
- Read surrounding `postEntry`/`postEntryWithOptionalActor`/`postEntryAndDispatchPostedEvent*`/`scale()`/resolver/`CurrencyScale` map and migrations.
- `php artisan test --filter 'test_posting_unbalanced_journal_entry_is_rejected_without_mutation|test_posting_journal_entry_adds_hash'` → 2 passed, 11 assertions.
- Red-first refutation: removed the guard, reran the unbalanced test → 1 failed at `$this->fail(...)` (entry posted). Restored the guard, confirmed file intact.
- Reproduced the scale-0 masking with a standalone `bcadd`/`bccomp` script (scale 0 vs scale 3) — confirmed H1.
- Confirmed `JournalLine` casts `decimal:3` and storage `decimal(15,3)`.

## Verdict

APPROVE-WITH-MINOR-EDITS for the merged slice (the core claim is correct and well-tested), but the implementation is **not** a complete balance-integrity guard: H1 (scale-0 currency masking) is a genuine correctness gap that should be fixed before relying on this guard in any XOF/XAF tenant, and M1 (no rounding leg / fixture-masked regression) should be tracked as a follow-up. Neither was caught by the Codex or fallback reviews, both of which rated precision behavior "ACCEPTED."
