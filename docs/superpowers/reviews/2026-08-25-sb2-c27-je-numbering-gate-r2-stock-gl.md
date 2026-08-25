# Gate r2 — Session B2 lane B2-1 / LEDGER C-27 (JE + income numbering tenant scope), fix round r1

**VERDICT: ACCEPT-WITH-CONDITIONS — merge-blocking NO.**
Lens: **stock↔GL interaction**. Branch `fix/sb2-c27-je-numbering-tenant-scope`, new commit **`47bf340c4`** on `eaccb7323`
(base dev `834c8c017`). r1 findings **F-1 and F-2 are CLOSED, verified by execution** (not by reading the report).
One new finding (F-8, Important, documentation/latent-trap) and rulings on the two residuals.
Everything below ran on PostgreSQL `127.0.0.1:5433`, throwaway DB `autoerp_stockglgate_test`, in an isolated detached
worktree copy (`scratchpad/c27probe2`, real `vendor/` copied). The shared lane worktree was never written to; nothing
pushed, nothing merged.

---

## 1. r1 F-1 — CLOSED

`sealAndPersistEntry` now calls `takeTenantNumberingLock($entry->tenant_id)` (`GeneralLedgerService.php:3784`)
immediately before the per-company chain key (`:3796`), and `generateEntryNumber` calls the same helper (`:5674`)
before the company key (`:5679`). The helper (`:5618-5628`) is the only place in the service holding the key literal.

**(1) The AB-BA no longer deadlocks — two-arm simulation with the exact key expressions, one PG connection per arm:**

```
ARM1  A:[C,T]  B:[T,C]  (the r1 code)
  B: SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected
  A: acquired T (no deadlock)
ARM2  A:[T,C]  B:[T,C]  (the r2 code)
  A: committed, released T+C
  B: acquired T then C after A committed
```

ARM1 is the sensitivity control: the harness is still capable of producing `40P01`, so ARM2's clean serialisation is a
result and not an absent test.

**(2) The new pin is genuinely red without the fix.** `tests/Feature/Accounting/InventoryGlNumberingTenantScopeTest.php`
(`connectionsToTransact() = []`, PG-only, real `GeneralLedgerService`, two real transactions — mint the Draft in T1,
replay+post in T2):

| Service file at | Result |
|---|---|
| `47bf340c4` (fix) | **OK (2 tests, 12 assertions)** |
| `eaccb7323` (r1) — restored with `git checkout … -- <file>`, no stash | **Tests: 2, Assertions: 11, Failures: 1** — `test_posting_a_pre_numbered_entry_takes_the_tenant_key_before_the_company_key` … *"Failed asserting that null is not null"* (`:143`): the sealing transaction took the company key and no tenant key. Exactly the F-1 shape. |
| `834c8c017` (dev base) | **Tests: 2, Errors: 1, Failures: 1** — the F-2 test errors with `SQLSTATE[23505] … journal_entries_tenant_id_entry_number_unique`, the F-1 pin fails as above. |

The probe file was restored to `47bf340c4` afterwards (`git status --short` clean in the copy; the branch worktree was
never touched).

**(4) Key literal byte-for-byte.** `GeneralLedgerService.php:5624-5627` and `JournalEntryController.php:206-209` are the
same two lines, character for character:

```php
DB::statement(
    'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
    ["journal_entry_number:{$tenantId}"],
);
```

Same SQL string, same interpolation, same parameter name. One sequence, one serialisation point. (See F-8: they are
still two copies.)

## 2. r1 F-2 — CLOSED, and it exercises the money-losing path

`test_inventory_gl_mints_tenant_unique_numbers_for_a_second_company_with_no_company_context` drives the **production**
`InventoryGlPostingBuffer` (`enqueue` + `flushIfOutermost()` inside a real root transaction), with
`app(CompanyContext::class)->clear()` before the flush (rule 20 worker reality), against two companies of one tenant,
and asserts BOTH sides of the seam: the `stock_movements` rows still exist AND each has its journal entry, with
`JE-YYYY-000001` / `…000002` and `chain_sequence` 1 and 1. On `834c8c017` it errors with the real 23505 (above) — i.e.
the test reproduces precisely the pre-fix scenario where `23505` is not retryable (`ConcurrencyFault.php:56`), the
contained POS flush discards the batch (`PosCoreReceiptProjection.php:506-517`) and the receipt commits with stock moved
and no COGS.

It lives in `tests/Feature/Accounting/` → the `treasury-spine-pgsql/feature-accounting` lane, which is **live**, not
parked, so these PG-only assertions actually execute in CI rather than skipping (unlike the Income twin, parked behind
`SELF_HOSTED_RUNNER_READY`).

## 3. (3) Inventory lock-order baselines — EXACT, all four

| File | r2 result |
|---|---|
| `InventoryGlVoucherLockOrderTraceTest` | **OK (2 tests, 30 assertions)** |
| `InventoryGlLockOrderContentionTest` | **OK (15 tests, 174 assertions)** |
| `InventoryGlPostingSeamTest` | **OK (27 tests, 112 assertions)** |
| `GoodsReceiptGlPostingOrderTest` | **OK (12 tests, 42 assertions)** |

Identical to the r1 numbers. Plus, on this branch:
`JournalEntryNumberingTenantScopeTest` **OK (5 tests, 26 assertions)** (was 5/24 — the reworked T3 adds the
free-before / held-during probe, and it is a real assertion now: it drives an actual post and probes from a second PDO
connection while the transaction is open).

**Extra measurement I ran because r2 makes the tenant key wider in reach (it is now taken at EVERY post, not only at a
mint):** I copied `InventoryGlVoucherLockOrderTraceTest` inside my scratch copy and rewired its I-1 assertion to match
`journal_entry_number:{tenantId}` instead of the company uuid. Result **OK (2 tests, 30 assertions)** — in the real POS
projection trace the **tenant key is also terminal to the last `stock_levels`/`stock_movements` statement**. The I-1
invariant therefore still holds for the new, wider key. (Probe file deleted; it was never in the branch.)

## 4. Static gates (r2 code)

* PHPStan level 8 on `GeneralLedgerService.php`, live-PG env — **[OK] No errors**.
* `pint --test` on the service + both touched test files — `{"result":"pass"}`.
* `php tools/feature-lane-manifest-check.php` — OK, 1424 Feature classes / 74 groups; parked stays **1174**
  (correct: `Accounting` is a live lane, so its 87→88 bump is informational and does not move `gated_ceiling`).

## 5. New finding

### [IMPORTANT] F-8 — the rewritten invariant is still stated absolutely, and two live call sites outside `GeneralLedgerService` violate it literally

`GeneralLedgerService.php:5654-5657` now says: *"the invariant, enforced at BOTH sites and nowhere else: **every path
that takes the company chain key takes the tenant numbering key first**."* Two production paths take that exact key
(`pg_advisory_xact_lock(hashtextextended($companyId, 0))`, bare uuid) with no tenant key at all:

* `apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:1306` — `postCorrectingEntryGl()`, step 1
  of its transaction. **Safe today**: it mints nothing (its number is `'CORR-'.…` built inline) and never seals, and its
  only caller (`CorrectingEntryService.php:170`) does nothing else GL-shaped in that transaction. I verified both.
* `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:239-247` — `transfer()` takes the
  company key FIRST *by design* ("GLOBAL LOCK ORDER (BLOCKER-1)"), then repository row locks, and then at `:346` calls
  `postEntryNow()` — which after r2 acquires the **tenant** key. That is company→tenant inside one transaction, the very
  shape F-1 was about. **Safe today only because of an ordering established in a different class**: the sole caller
  `RepositoryTransferService.php:77-101` mints the transfer JE (taking the tenant key) BEFORE calling `transfer()`, and
  on the non-cross-GL branch no JE is minted and none is posted. I verified both branches, including the
  `$crossGl`/`$crossGlAccount` disagreement path, which throws (`TreasuryMovementService.php:264-270`) rather than
  posting.

Nothing enforces that cross-class dependency. If a later lane makes same-GL-account transfers carry an entry, or moves
the mint after `transfer()`, the AB-BA returns — with the tenant key, i.e. tenant-wide. *Ask (cheap):* qualify the
docblock to the truth (e.g. "every path that MINTS or SEALS a journal entry takes the tenant numbering key before the
company chain key; `AccountingService::postCorrectingEntryGl` and `TreasuryMovementService::transfer` take the company
key without minting or sealing — see the note there"), and add one comment line at `TreasuryMovementService.php:246`
recording that its company-first acquisition is only safe while the JE is minted before `transfer()` is called. This is
documentation + a comment; it does not block the merge, but it is the same species of claim that r1 F-1 turned out to be.

## 6. Rulings on the r1 residuals

* **F-4 (999999 wrap) → LEDGER ROW, not must-fix-now.** The failure is real and silent (`sprintf('%06d')` +
  `substr(-6)` at `GeneralLedgerService.php:5686-5692`, twin at `IncomeService.php:238-245`): past 1,000,000 entries in
  a tenant-year the generator restarts at `…000001` and every later mint 23505s for the rest of the year. But the fix is
  not a two-line throw — it is a numbering-design decision (widen the width? per-company sub-sequence? a year+company
  discriminator?) that must be answered together with the sibling minters in F-3, and the first tenant cannot approach
  the ceiling (≈2,700 JEs/day sustained). Row it with the sibling-minter row so one design answer covers all of them,
  and state the threshold (~100k JE/tenant-year) at which it must be scheduled.
* **F-5 (I-1 trace guard blind to the tenant key) → LEDGER ROW, not must-fix-now.** I measured the property this lane
  would otherwise be trusting on faith (§3): the tenant key IS terminal to inventory locks in the production POS trace
  today. So the guard's blindness is a future-regression risk, not a live defect. Row it as "extend
  `InventoryGlVoucherLockOrderTraceTest`'s I-1 assertion to `journal_entry_number:{tenantId}`" — a one-line change to
  the assertion plus a second index, worth doing in the next inventory-touching lane.

## 7. Conditions (unchanged from r1 except where noted)

1. **F-8** — tighten the invariant wording + one comment at `TreasuryMovementService.php:246`. Cheap; do it here or row
   it explicitly. Not merge-blocking.
2. **F-3** (r1) — Session W row must name `INV-OB-` (`OpeningBalancePostingService.php:290-306`) and `INV-OBR-`
   (`ResetOpeningBalanceService.php:263-272`) as stock↔GL exposures of the identical defect, alongside `OB-`/`HIST-`.
3. **F-4, F-5** — LEDGER rows per §6.
4. **F-6** (r1) — owner ack of the interleaved numbering contract (still open; unaffected by r2).
5. **F-7** (r1) — fleet census before promotion (still open; the implementer's census was local-only).

## 8. Machine notes

Throwaway DB `autoerp_stockglgate_test` and the probe copy removed after the run. Two runs early in the session errored
inside `RefreshDatabase`'s `migrate:fresh` immediately after a `git checkout` of the service file; re-running the same
file with the same environment passed, and every result quoted above is from a clean run whose full log I read. No
result in this record is inferred from a truncated tail.
