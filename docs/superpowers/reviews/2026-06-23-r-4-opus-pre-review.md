# R-4 Opus Pre-Review

Opus was invoked headlessly as an adversarial pre-review for the voucher
`findOrFail` remediation, but both attempts failed to return review content:

1. `claude --safe-mode --model opus -p ...` was stopped after hanging with no
   output.
2. `perl -e 'alarm shift; exec @ARGV' 90 claude --safe-mode --model opus -p ...`
   exited with code 142 after the 90 second alarm, also with no output.

Local pre-review conclusion:

- `voucher_ledger.user_id` is required but has no foreign key to `users`.
- `createVoucherLedgerEntry()` currently aborts voucher GL creation by using
  `User::query()->findOrFail($ledgerRow->user_id)`.
- The service already has a system-generated posting path:
  `postSystemGeneratedEntryAndDispatchPostedEventAfterCommit()`.
- The focused remediation should resolve the actor defensively before the
  journal transaction and post with `posted_by = null` when no matching user is
  found.
- Invoice/media attachment wiring remains out of scope while unified media
  management is in transition.
