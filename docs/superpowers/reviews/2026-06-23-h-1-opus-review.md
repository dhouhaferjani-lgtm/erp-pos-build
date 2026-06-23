# H-1 Opus Adversarial Review — AR/Credit-Note Partner Tagging

- Item: H-1 — Production invoice/credit-note AR lines omit `partner_id`
- Commit: `4607895d1`
- Reviewer: Opus (cross-model adversarial pass; the merged `*-opus-fallback-review.md` was NOT a real Opus review)
- Branch checkout: `apps/erp.dev-consolidation` (dev, merged code + vendor)

## Summary

The claim holds. The two-line production change tags the customer-receivable AR
line on invoice posting (`AccountingService.php:149`) and the AR-reversal line on
credit-note posting (`AccountingService.php:268`) with the document's
`partner_id`. `PartnerBalanceService` filters subledger queries on
`journal_lines.partner_id` (`:45`, `:122`, `:126`, `:158`), so before this fix
the receivable subledger silently returned zero for posted documents even though
`refreshPartnerBalance()` was already being invoked. After the fix the AR
subledger is non-zero, as claimed.

I verified the fix end-to-end:
- Ran the integration suite: 8 passed / 85 assertions.
- Confirmed red-first: reverting only the two production lines makes the two new
  AR assertions fail with `Failed asserting that null matches expected '<uuid>'`
  — the test is meaningful, not false-confidence.
- Confirmed the change is fiscal-hash neutral (see below), so no immutability or
  hash-chain regression on already-posted entries.

No BLOCKER or HIGH findings. The implementation is correct, scoped, and well
tested. Findings below are MEDIUM/LOW.

## BLOCKER

None.

## HIGH

None.

Adversarial checks that came back clean (recorded for the record):

- **Fiscal hash immutability — SAFE.** `GeneralLedgerHashService::serializeForHashing()`
  (`:59-77`) hashes only `entry_number | entry_date | company_id | total_debit |
  total_credit`. `partner_id` is not part of the serialized payload, so adding it
  to a line does not alter any `fiscal_hash`. Existing posted entries and the
  hash chain are unaffected. No event class was renamed/restructured.
- **No migration introduced — SAFE.** `journal_lines.partner_id` already exists
  (`migrations/tenant/2025_12_06_100000_add_partner_id_to_journal_lines.php`):
  `nullable()`, FK to `partners`, indexed both `(partner_id, account_id)` and
  `(account_id, partner_id)`. The column lives under `migrations/tenant/`. No
  backfill, no column-widen, no PG-only CHECK constraint added by this commit, so
  there is nothing here needing real-PG verification.
- **Null-partner edge case — N/A.** `documents.partner_id` is NOT NULL
  (`migrations/tenant/2025_11_30_080000_create_documents_table.php:16`
  `$table->foreignUuid('partner_id')->constrained('partners')` — no
  `nullable()`). Every invoice/credit note has a partner, so the AR line is never
  tagged with null and the pre-existing `refreshPartnerBalance(... $partner_id)`
  `firstOrFail()` call was always safe.
- **Sign convention — CORRECT.** Invoice AR line is debit `$invoice->total` /
  credit `0`; credit-note AR reversal is debit `0` / credit `$creditNote->total`.
  `receivable_balance` is debit-normal and stored as the natural `debit - credit`
  magnitude (`PartnerBalanceService:353`), so a posted invoice raises the
  receivable and a credit note lowers it. No sign confusion.
- **Money precision — no new violation.** The production change only assigns a
  UUID string to `partner_id`; it does not touch money/quantity. No `(float)` cast
  or `number_format((float)…)` was added to production money handling. `debit`/
  `credit` continue to be passed as strings.
- **Other production AR/AP writers already tag partner — scope is complete.**
  `TreasuryAccountChargeBridge` (`:221`) and `TreasuryDepositBridge` (`:118`,
  `:290`) already set `partner_id` on their CustomerReceivable / CustomerAdvance
  lines. The work-list correctly DISCARDED `GeneralLedgerService::createFromInvoice()`
  as a non-production path. So the invoice/credit-note `AccountingService` lines
  were the only remaining gap, and this commit closes it.
- **Idempotency / transaction safety — unchanged and correct.** Line creation
  stays inside the existing `DB::transaction()` in both methods; the new field
  rides the same insert. Existing rollback tests
  (`invoice gl creation rolls back when partner balance refresh fails`, and the
  credit-note equivalent) pass, so a failed balance refresh still leaves no
  orphaned GL lines.

## MEDIUM

1. **Acceptance criterion "reconciliation reports no partnerless production AR
   rows" is not directly asserted.** The work-list lists four acceptance criteria;
   the 3rd (balance refresh sees posted AR) and 4th (reconciliation shows no
   partnerless production AR rows) are only *inferred* from the AR-line assertion,
   not exercised by a test. `PartnerBalanceService::reconcileSubledger()` and
   `getCustomerReceivableBalance()` are the readers that the whole H-1 motivation
   rests on, yet no test in this commit drives the posted invoice through
   `getCustomerReceivableBalance()` / `reconcileSubledger()` to assert a non-zero
   subledger and `entries_without_partner == 0`. The unit-level AR-tag assertion
   is a reasonable proxy, but a one-line follow-up assertion through the actual
   reader would make the subledger claim self-evident rather than transitive.
   (`PartnerBalanceService.php:78`, `:197-224`)

2. **The merged "opus-fallback" review is a self-attested "No findings" with no
   real second-model lens.** `docs/superpowers/reviews/2026-06-22-h1-ar-partner-id-opus-fallback-review.md`
   explicitly states `opus-review: PENDING` and records "No findings," yet it is
   filed alongside the Codex review as if it were an independent pass. It is not a
   substitute for cross-model review. This review file is the genuine Opus pass;
   the coordination log / work-list should reference it and drop reliance on the
   fallback doc as an Opus sign-off.

## LOW

1. **Float comparison on money in the touched test blocks.** Lines 321-322 and
   411-412 use `(float) $line->credit` / `(float) $line->debit` and compare to
   `1000.00`. This is a pre-existing pattern (the commit only inserted the
   adjacent `partner_id` assertions, it did not introduce the float math), and it
   is test-only, so it does not violate the money-precision contract at rest.
   Still, since the commit edits these exact blocks, a string compare
   (`$this->assertEquals('1000.000', ...)` on the summed string, or
   `bccomp`) would have been the convention-consistent choice. Non-blocking.

2. **Guard assertions iterate with `->each` and assert null per line — fine, but
   they would silently pass on an empty revenue collection.** The preceding
   `assertGreaterThan(0, $revenueLines->count())` guards against that, so the risk
   is already covered. Noted only for completeness.

## Verdict

APPROVE-WITH-MINOR-EDITS.

The fiscal/data-integrity core is correct: the AR subledger is now populated for
the canonical production invoice/credit-note path, the change is hash-chain
neutral, scoped precisely to receivable lines, and backed by a genuinely
red-first test (independently verified by reverting the production lines). The two
MEDIUM items are documentation/test-coverage hygiene, not correctness defects:
(1) add a reader-level assertion through `getCustomerReceivableBalance()` /
`reconcileSubledger()` to nail acceptance criteria 3-4 directly, and (2) stop
treating the "opus-fallback" doc as an Opus sign-off — this file is that pass.
