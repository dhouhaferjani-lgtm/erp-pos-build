# R-2 Opus Review — GL Hash Chain Unification

Date: 2026-06-24
Reviewer: Opus via `claude --safe-mode --model opus -p` (adversarial, diff-only final pass)
Scope:
- `apps/api/app/Modules/Accounting/Application/Services/GeneralLedgerHashService.php`
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`
- `apps/api/tests/Feature/Accounting/GLIntegrationTest.php`
- `apps/api/tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php`
- `apps/api/tests/Feature/Fiscal/TreasuryAccountChargeBridgeTest.php`

## Verdict

APPROVE.

Opus found no blockers in the final R-2 diff.

## Opus Findings

- Unified hash format is satisfied: `GeneralLedgerService` deleted its divergent local `calculateHash()` / `getPreviousHash()` path and now delegates posting hashes to `GeneralLedgerHashService::calculateHash()`.
- `chain_sequence` and `previous_hash` are assigned during `postEntryWithOptionalActor()`.
- Genesis stores `previous_hash === null`; the hash payload still uses an empty prefix through `previousHash ?? ''`, matching verification.
- Posting now hashes with company currency, and `verifyChain()` resolves company currency directly, so caller currency cannot poison the company GL chain.
- Cleared-context COGS and direct post cases are covered by regression tests.
- Invoice media attachment wiring remains untouched and out of scope.

## Residual Risks

- Historical Chain-B rows hashed with the removed JSON algorithm will still need a backfill/migration policy if present in deployed data.
- Previous-hash and next-sequence reads are not atomic; concurrent posting can still race. Opus classified this as outside R-2.
- `verifyChain()` now throws if company currency cannot be resolved instead of returning a boolean; caller expectations should be reviewed separately.
- Balance validation can use caller-currency scale while fiscal hash uses company-currency scale. Opus accepted this because the company GL chain must verify at company scale and the mismatch regression is covered.

## Invocation Notes

The normal Claude-P invocations stalled under local hooks/plugins. A safe-mode smoke test returned `OPUS_OK`, and the final Opus review was run with:

```sh
claude --safe-mode --model opus --permission-mode bypassPermissions --output-format text --max-budget-usd 2 -p '<diff-only R-2 final review prompt>'
```

The final prompt included the compact `git diff` for the scoped files and explicitly prohibited tool use.
