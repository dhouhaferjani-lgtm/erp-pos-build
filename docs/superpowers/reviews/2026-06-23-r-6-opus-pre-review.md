# R-6 Opus Pre-Review

Opus was invoked headlessly as an adversarial pre-review for the R-6 sealed
invoice GL durability remediation:

```sh
perl -e 'alarm shift; exec @ARGV' 120 claude --safe-mode --model opus -p ...
```

The process exited with code 142 after the 120 second alarm and produced no
review output.

Local pre-review conclusion:

- `AccountingService::createInvoiceGLEntries()` and
  `createCreditNoteGLEntries()` currently create the journal entry, journal
  lines, fiscal hash, and partner balance refresh inside a single transaction.
- A transient `PartnerBalanceService::refreshPartnerBalance()` failure rolls
  back the persisted GL for a document that may already be fiscally sealed.
- The narrow fix is to keep GL persistence atomic, return the journal entry id
  from that transaction, then refresh the cached partner balance after the GL
  transaction in a non-fatal helper.
- Existing tests that assert rollback should be inverted to prove journal
  entries and lines persist when refresh fails, and that a later explicit
  balance refresh can reconcile the cache.
- Invoice/media attachment wiring remains out of scope while unified media
  management is in transition.
