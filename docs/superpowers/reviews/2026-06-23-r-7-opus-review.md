# R-7 Opus Review

Opus returned a post-review for the R-7 GL balance precision change.

Verdict:

- The storage-scale balance guard is correct and low risk.
- The bug is real: scale-0 currencies could truncate sub-unit differences when
  `bcadd()`/`bccomp()` used display scale.
- The hash chain is unaffected because hash calculation does not consume the
  balance-check totals.
- Blast radius is small and limited to the posting balance guard.

Opus minor findings:

1. `JournalEntryPosted` totals would serialize at storage scale after the first
   implementation (`100.000` for JPY/EUR style cases) instead of display scale.
   This was non-blocking but could affect downstream string consumers.
2. The `100.500` test value exercises the persisted storage-scale mismatch. It
   correctly pins the regression even though persisted decimal precision limits
   what can be distinguished.

Follow-up applied:

- Added `test_post_entry_event_totals_keep_currency_display_scale()`.
- Split storage-scale guard totals from event payload totals so the balance
  guard uses `max(3, currencyScale)` while `JournalEntryPosted` keeps currency
  display scale.

Invoice/media attachment wiring is untouched.
