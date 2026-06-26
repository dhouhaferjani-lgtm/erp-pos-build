# R-7 Opus Pre-Review

Opus was invoked headlessly as an adversarial pre-review for the R-7 GL balance
precision remediation:

```sh
perl -e 'alarm shift; exec @ARGV' 120 claude --safe-mode --model opus -p ...
```

The process exited with code 142 after the 120 second alarm and produced no
review output.

Local pre-review conclusion:

- `GeneralLedgerService::postEntryWithOptionalActor()` currently totals and
  compares debit/credit at currency display scale.
- `journal_lines.debit` and `journal_lines.credit` are stored at scale 3, so a
  scale-0 currency must still reject sub-unit storage imbalances.
- The narrow fix is to keep currency resolution unchanged but use
  `max(3, $currencyScale)` for the journal balance guard totals and comparison.
- Regression coverage should post a scale-0-currency journal with `100.500`
  debit and `100.000` credit and assert the existing unbalanced-entry exception.
- Invoice/media attachment wiring remains out of scope while unified media
  management is in transition.
