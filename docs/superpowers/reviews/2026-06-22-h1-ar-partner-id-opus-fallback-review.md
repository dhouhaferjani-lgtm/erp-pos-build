# H-1 Second Adversarial Review — Opus-Unavailable Fallback

Item: H-1 — production invoice/credit-note AR lines omit `partner_id`.

Review package: `.superpowers/review-packages/h1-ar-partner-id.diff`.

`opus-review: PENDING` — a true Opus reviewer was not reachable from this runtime. This is the required independent fallback pass with a different lens.

## Findings

No findings.

## Review Notes

- Production invoice AR line is tagged in `AccountingService`.
- Production credit-note AR reversal line is tagged in `AccountingService`.
- Tests exercise `DocumentPostingService::post()` rather than calling `AccountingService` directly.
- The path remains `DocumentPostingService` after-commit `InvoicePosted` dispatch, `EventServiceProvider` listener registration, and `InvoicePostedListener` route to the patched methods.
- No immutable event shape changed.
- No migration/backfill is introduced; `journal_lines.partner_id` is already nullable/fillable.

Independent reviewer ran a two-test targeted PHPUnit subset and reported `OK (2 tests, 36 assertions)`.

