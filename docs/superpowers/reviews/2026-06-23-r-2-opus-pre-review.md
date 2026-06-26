## Adversarial Pre-Implementation Review — R-2 (`postEntry` must set `chain_sequence`)

**Verdict: REVISE THE FIX SCOPE BEFORE IMPLEMENTING.** The diagnosis is correct, but the stated Action ("assign next `chain_sequence`, order previous-hash by `chain_sequence`") is **necessary but insufficient** — implemented literally, the acceptance test (`verifyChain()` returns true after a `postEntry` post) will still fail. There are three independent defects that all sit on the postEntry path; only one is named in the worklist.

### The headline gap the Action misses: two chains hash *differently*

Chain A and Chain B don't just differ in sequencing — they use **incompatible hash serializations**, and the verifier only speaks Chain A's dialect:

- `verifyChain()` recomputes via `GeneralLedgerHashService::calculateHash` → `serializeForHashing` = `entry_number|entry_date|company_id|total_debit|total_credit` (`GeneralLedgerHashService.php:59-77,112`).
- `postEntry` writes `fiscal_hash` via its **own private** `calculateHash` (`GeneralLedgerService.php:1735-1749`) = `sha256(prev . '|' . json_encode([entry_number, entry_date, description, lines[account_id,debit,credit]]))`.

`GeneralLedgerService` doesn't even inject `GeneralLedgerHashService` (ctor `:41-44`). So even with `chain_sequence` set perfectly, `verifyChain` fails at the **tamper check** (`:113 $calculatedHash !== $entry->fiscal_hash`), not the sequence check. An implementer who fixes only sequencing ships a still-red acceptance test.

### Required approach

Make `postEntry` byte-identical to Chain A rather than "also set a sequence":
1. **Inject `GeneralLedgerHashService`** into `GeneralLedgerService`; **delete the private `calculateHash` and `getPreviousHash`.**
2. In `postEntryWithOptionalActor`, before the `update`: `$chainSequence = JournalEntry::getNextChainSequence($entry->company_id)` and `$previousHash = JournalEntry::getLastChainHash($entry->company_id)` (the model statics already order by `chain_sequence` — `JournalEntry.php:136-158` — and return `?string` null for genesis). Set `fiscal_hash = $hashService->calculateHash($entry, $previousHash)`, plus `chain_sequence` and `previous_hash` in the existing `update([...])` (`:1278-1284`).
3. **Genesis must be `null`, not `''`.** Today `getPreviousHash` returns `''` when no prior entry exists; `verifyChain` expects `previous_hash === null` for sequence 1 (`:107`). Using `getLastChainHash()` (returns null) fixes this for free — but only if you actually drop the old `''`-returning helper. A manual entry posted as the *first* row in a company will otherwise fail `'' !== null`.

### Risks / things that will bite

- **Scale resolution throws on the COGS leg (Rule 20).** `serializeForHashing` uses no-arg `$this->scale()` (`:66-67`). COGS posts via `postSystemGeneratedEntryAndDispatchPostedEventAfterCommit` → `afterCommit`, where there may be **no `CompanyContext`** → no-arg `getScale()` throws (precision contract F-RES-1). `postEntry` currently dodges this because its private json hash never resolves scale. Adopting the shared hasher reintroduces the throw on exactly R-2's COGS acceptance path. **Thread the currency through:** extend `GeneralLedgerHashService::calculateHash`/`serializeForHashing` with a `?string $currencyCode` (non-breaking; resolving the *same* company-currency scale yields identical totals → identical hash → Chain A unaffected) and pass `postEntry`'s `$currencyCode`. The COGS test **must `app(CompanyContext::class)->clear()` before posting**, or a context bound in `setUp()` masks the worker reality.
- **Concurrency: no DB guard on sequence.** `(company_id, chain_sequence)` is a **plain index, not unique** (migration `2025_12_26_111230:43`), and `max()+1`/tip-read happen read-then-write outside any locking transaction (`postEntryWithOptionalActor` does a bare `$entry->update`, no `DB::transaction`/`lockForUpdate`). Two concurrent posts → duplicate sequence + forked `previous_hash`. **This is pre-existing in Chain A** (same unguarded read at `AccountingService.php:111-112`), so the fix doesn't regress it — but the new tests must **not** assert concurrency safety, and you should note it. Optional hardening (likely out of R-2 scope): partial unique index on `(company_id, chain_sequence) WHERE fiscal_hash IS NOT NULL` + read the tip inside the posting transaction.
- **Backfill landmine.** Any already-posted Chain-B rows (manual/COGS/voucher/advance) carry `fiscal_hash` with `chain_sequence = NULL`. The fix is forward-only → those companies' chains stay permanently broken (gap at the NULL rows) even after the fix. Latent today (no prod `verifyChain` caller), but flag that real tenants with pre-fix postEntry data need a one-off backfill before `verifyChain` is ever relied upon. Fresh `RefreshDatabase` tests won't reveal this.
- **`fiscal_hash` is globally unique** (`idx_gl_fiscal_hash_unique`) — fine (content differs per entry), but be aware a buggy hasher producing a collision surfaces as a DB unique violation, not a clean `verifyChain` false.

### Exact tests to add / modify

1. **Mixed-chain (the discriminating test, satisfies "existing Chain-A invoices still verify"):** `createInvoiceGLEntries()` (Chain A) → then `postEntry()` a manual entry in the same company → assert `verifyChain()` **true**, sequences are `1,2` gapless, and manual `previous_hash === invoice.fiscal_hash`. This is the test that catches the serialization divergence; assert the boolean, not just "no exception."
2. **Manual-only via `postEntry`** (M-1's missing assertion) → `verifyChain()` true. Replace/upgrade the existing false-confidence M-1 manual-journal test (`tests/Feature/Accounting/…` — it currently never calls `verifyChain`).
3. **COGS via `createCOGSEntry` with `CompanyContext` cleared** → `verifyChain()` true (covers H-7.3 + the scale-throw regression).
4. **Genesis via `postEntry`** (first row, no invoice) → assert `previous_hash` is `null` and `verifyChain()` true.
5. **Gapless multi-post** → post N mixed entries, assert `chain_sequence` 1..N and `verifyChain()` true.
6. **CI wiring (R-9 tie-in):** these are `tests/Feature/Accounting/*` and `verifyChain` hits the DB — confirm they land in CI's PG `--filter` allowlist (`.github/workflows/ci.yml`); otherwise they never run and you've rebuilt the false-confidence pattern R-2 is meant to kill.

### Do NOT change

- `GeneralLedgerHashService::serializeForHashing` / `calculateHash` core format / `verifyChain` logic — all existing Chain-A invoice/credit-note hashes depend on it; reconcile postEntry *to* it, never the reverse. (Adding an optional `currencyCode` param with an identical-scale default is the only safe touch.)
- `AccountingService::createInvoiceGLEntries` / `createCreditNoteGLEntries` (Chain A is the reference scheme).
- `JournalEntry::getNextChainSequence` / `getLastChainHash` — already correct; reuse, don't reimplement.
- `JournalEntryPosted` event shape (event immutability), the immutability trigger (Draft→Posted update is legal; just confirm it keys off the OLD row's status), `generateEntryNumber`, and migration history.

One-line summary for the implementer: **"set `chain_sequence`" is the small half; the load-bearing half is making `postEntry` hash through `GeneralLedgerHashService` (null genesis + currency-threaded scale) so the verifier's recomputation matches.** Without that, R-2's own acceptance test cannot go green.
