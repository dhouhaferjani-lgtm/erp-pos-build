# R-6 Opus Review

Opus was invoked headlessly as an adversarial post-review for the implemented
R-6 diff:

```sh
perl -e 'alarm shift; exec @ARGV' 120 claude --safe-mode --model opus -p ...
```

The process exited with code 142 after the 120 second alarm and produced no
review output.

Local adversarial review:

- The GL persistence transaction still covers journal entry creation, journal
  line creation, fiscal hash assignment, and `JournalEntryCreated` dispatch.
- Partner balance cache refresh now runs only after the GL transaction returns
  its entry id, so a refresh failure cannot roll back GL for an already sealed
  invoice or credit note.
- The refresh helper catches `Throwable` intentionally at the cache-refresh
  boundary, logs company, partner, journal entry, and exception class, and does
  not affect the persisted journal.
- Regression tests cover invoice and credit note refresh failures, proving the
  journal entry and lines persist and a later real `PartnerBalanceService`
  refresh can update the cached partner balance.
- Invoice/media attachment wiring is untouched.

No issues found in the local review.
