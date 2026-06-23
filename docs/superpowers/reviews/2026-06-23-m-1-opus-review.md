# M-1 — Manual Journal Audit Events — Opus Adversarial Review

Date: 2026-06-23
Reviewer: Opus 4.8 (adversarial, refutation-first)
Commit: `0b6ecfa71` ("Phase 0.1.18: Audit manual journal events")
Item: M-1 (work-list `docs/superpowers/audits/2026-06-22-balance-conventions-audit/work-list.md`)

## Summary

The change does what its title claims at the event-plumbing level: manual journal
`store()` now dispatches `JournalEntryCreated`, `post()` now delegates to
`GeneralLedgerService::postEntry()` (which dispatches `JournalEntryPosted`), and the
compliance `DomainEventSubscriber` subscribes both and writes `JournalEntry` audit rows.
Events are reused (not renamed/restructured), so event-immutability is respected.

However, the commit and both bundled reviews assert that manual posting now reuses the
"**canonical** balanced-entry validation, company-scoped **hash-chain calculation**, fiscal
hash mutation..." — and that claim is materially overstated and **unverified for the one
thing that matters fiscally: the GL hash-chain verifier**. `GeneralLedgerService::postEntry()`
sets `fiscal_hash` but does **not** set `chain_sequence`, while the canonical verifier
`GeneralLedgerHashService::verifyChain()` requires a gap-free `chain_sequence` starting at 1.
A manual entry posted through this path lands a `fiscal_hash` row with `chain_sequence = NULL`,
which makes `verifyChain()` for that whole company return `false`. The M-1 tests never call
`verifyChain()` after a manual post, so this is false-confidence coverage.

Verdict: **NEEDS-REVISION** (HIGH on the unverified/misleading hash-chain claim;
no fiscal data is mis-stored, so not a BLOCKER, but the canonical-chain claim is wrong and
the regression in `verifyChain` is real and untested).

## BLOCKER

None. No money/quantity value is stored or mutated incorrectly by this change; totals are
computed with `bcadd` at currency scale and only used for the audit payload, not for storage.

## HIGH

### H1 — `postEntry()` does NOT set `chain_sequence`; manual posts break `verifyChain()` (claim of "canonical hash-chain calculation" is false)

`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1278-1284` — the
post mutation sets `fiscal_hash`/`previous_hash`/`posted_at`/`posted_by` but **never**
`chain_sequence`:

```php
$entry->update([
    'status' => JournalEntryStatus::Posted,
    'fiscal_hash' => $hash,
    'previous_hash' => $previousHash,
    'posted_at' => $postedAt,
    'posted_by' => $user?->id,
]);
```

and `getPreviousHash()` orders by `posted_at` (`GeneralLedgerService.php:1751-1761`):

```php
->where('company_id', $companyId)->where('status', Posted)
->whereNotNull('fiscal_hash')->orderByDesc('posted_at')->first();
```

The canonical verifier orders and validates by `chain_sequence`
(`apps/api/app/Modules/Accounting/Application/Services/GeneralLedgerHashService.php:85-123`):

```php
->orderBy('chain_sequence')->get();
...
if ($entry->chain_sequence !== $expectedSequence) { return false; } // line 102
if ($entry->previous_hash !== $expectedPreviousHash) { return false; } // line 107
```

And the *other* GL author, `AccountingService` (invoice/credit-note), explicitly assigns
`chain_sequence` via `JournalEntry::getNextChainSequence()` / `getLastChainHash()`
(`apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:111-125`,
`:230-242`). There is no model `boot`/observer backfill of `chain_sequence`
(`apps/api/app/Modules/Accounting/Domain/JournalEntry.php` — only static helpers, no
`creating`/`saving` hook; `Observers/JournalEntryObserver.php` only enforces immutability).

Consequence: a manual journal entry posted via the now-canonical path is selected by
`verifyChain` (`whereNotNull('fiscal_hash')`) but has `chain_sequence = NULL`. The first
iteration `NULL !== 1` (or a NULLS-first ordering surprise) returns `false`, and its
`previous_hash` was computed off `posted_at` ordering rather than `getLastChainHash`
(chain_sequence-desc), so even the `previous_hash` linkage diverges. `verifyChain()` is a
live path — `Compliance\Commands\VerifyFiscalChainsCommand` and the NF525 verify-chains
endpoint use it. The two chain conventions (`chain_sequence`-ordered in `AccountingService`
vs `posted_at`-ordered + NULL-sequence in `postEntry`) are not reconciled.

This divergence is **pre-existing** in the service (deposits/expenses/vouchers already post
via `postEntry`), but M-1 is the item that (a) routes manual posting into it and (b) asserts
in the commit message and both bundled reviews that it reuses "canonical hash-chain
calculation." That assertion is wrong relative to the canonical verifier. M-1's tests
(`CreateJournalEntryTest`, `GLIntegrationTest` filter `posting_journal_entry_adds_hash`)
assert only that a hash string is set and an event fires; **none calls
`verifyChain($company->id)` after a manual post**, so the regression is uncovered.

Required: either set `chain_sequence` in `postEntry()` (via `getNextChainSequence` inside the
locked write) and align `getPreviousHash` with `getLastChainHash`, or explicitly scope M-1 to
"NOT chain-verifiable" and add a test asserting current behavior. At minimum add a
`verifyChain()`-after-manual-post test and reconcile the claim text.

### H2 — Integration is split across two faked tests; no end-to-end "manual create/post produces an audit row" assertion

`apps/api/tests/Feature/Accounting/CreateJournalEntryTest.php:129` uses
`Event::fake([JournalEntryCreated::class])` and `:174` uses
`Event::fake([JournalEntryPosted::class])`. Faking suppresses the real subscriber, so these
two tests prove only "controller dispatches event," never "the subscriber persisted an
`audit_events` row for a real HTTP create/post." The subscriber half is tested in
`DomainEventSubscriberTest` by hand-firing synthetic events
(`tests/Feature/Compliance/DomainEventSubscriberTest.php:245`, `:289`). The two halves never
meet, so a wiring regression (e.g., subscriber not registered for the controller's runtime
context) would pass green. The acceptance criterion "Compliance subscriber coverage is
explicit" is met structurally but not exercised end-to-end. Add one test that does a real
`postJson` create/post **without** `Event::fake` and asserts an `AuditEvent` row exists with
`event_type` `journal_entry.created` / `accounting.journal_entry.posted`.

## MEDIUM

### M1 — Unannounced scope expansion: ALL `JournalEntryCreated` now audited, not just manual

The subscriber registration (`DomainEventSubscriber.php:1015`) is event-type-global, so the
pre-existing `AccountingService::dispatchJournalEntryCreatedEvent()` emissions for `invoice`
and `credit_note` GL postings
(`apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:202`, `:321`)
now also write `journal_entry.created` audit rows. The work-list "Scope boundary: Manual
journal endpoints only" is violated in practice. This is arguably *desirable* (those GL
entries should be audited) but it is a behavior change to existing invoice/credit-note flows
that is neither called out nor covered by a test, and it increases audit-row volume on hot
paths. Decide explicitly and document; consider whether invoice GL audit duplicates the
existing `InvoicePosted` audit semantics.

### M2 — Manual post now triggers `RefreshPartnerBalanceOnJournalEntryPosted` (new side effect)

`apps/api/app/Modules/Accounting/Listeners/RefreshPartnerBalanceOnJournalEntryPosted.php:17`
listens to `JournalEntryPosted`. Pre-M-1 the controller bypassed the service, so manual posts
never refreshed partner balances; now they do. For partner-tagged manual lines this is
correct and beneficial, but it is an unflagged behavior change, and the post test fakes
`JournalEntryPosted`, so this listener is never exercised for the manual path. Add coverage
for a manual entry with a partner-tagged line.

### M3 — `journalTotals()` uses no-arg `scaleResolver->getScale()`

`apps/api/app/Modules/Accounting/Presentation/Controllers/JournalEntryController.php:222`
calls `$this->scaleResolver->getScale()` with no currency argument. In the HTTP `store()`
context CompanyContext is bound so it resolves and will not throw
(`app/Shared/Infrastructure/CurrencyScaleResolver.php:36-52`), but `store()` already has
`$company` in scope and the CLAUDE.md precision contract prefers passing the entity currency
explicitly (`getScale($company->currency)`). Low fiscal risk (the value feeds only the audit
payload, not storage), but it is the discouraged pattern and is fragile if this helper is ever
reused off-request. Pass `$company->currency` explicitly.

### M4 — Audit payload `chain_sequence`/`fiscal_hash` are meaningless for manual creates

The `JournalEntryCreated` dispatched at create-time reports
`chainSequence: $entry->chain_sequence ?? 0` and `fiscalHash: $entry->fiscal_hash ?? ''`
(`JournalEntryController.php:207-208`). A freshly created draft has neither, so every manual
`journal_entry.created` audit row records `chain_sequence: 0` and empty `fiscal_hash`. That is
internally consistent with `AccountingService` (which also passes `?? 0`), but combined with
H1 (manual posts never get a real `chain_sequence`) the audit trail carries a permanently
`0`/empty chain identity for manual entries. Acceptable only if H1 is resolved or the field
is documented as not-applicable for manual entries.

## LOW

### L1 — Redundant second write at create

`JournalEntryController.php:102` issues `$entry->update(['source_id' => $entry->id])`
immediately after `JournalEntry::create([... 'source_type' => 'manual'])`. `source_id` is a
nullable plain `uuid` with no FK (`migrations/tenant/2025_11_30_100000_create_journal_entries_table.php:23`),
so self-referencing is harmless, but a single `create([... 'source_id' => null ...])` plus one
post-insert update could instead set both columns in one statement, or set `source_id` after
obtaining the id without a separate model round-trip. Cosmetic.

### L2 — Bundled "Opus fallback review" is not an independent model

`docs/superpowers/reviews/2026-06-22-m1-manual-journal-audit-events-opus-fallback-review.md`
is authored by "Codex, second independent pass." Both bundled reviews returned "No
BLOCKER/HIGH" and neither caught H1/H2. This confirms the value of the cross-model gate; the
progress log correctly carries `opus-review: PENDING`.

## Verdict

**NEEDS-REVISION** — Event plumbing and immutability are sound, and no money value is
mis-stored, but the headline "canonical hash-chain calculation" claim is false against the
`chain_sequence`-based `verifyChain()` verifier, manual posts produce NULL-`chain_sequence`
fiscal rows that fail company chain verification, and the supplied tests never verify a chain
or an end-to-end audit row. Resolve H1 (set/reconcile `chain_sequence` or explicitly scope and
test the limitation) and H2 (one un-faked end-to-end audit-row test) before treating M-1 as
DONE.
