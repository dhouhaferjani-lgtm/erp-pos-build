# v3 receipt-hash golden fixtures

Each fixture is a `{input, expected_canonical, expected_hash}` triple driving
`CanonicalPayloadBuilderTest`. The TypeScript counterpart at
`apps/pos/src/lib/fiscal/v3/__fixtures__/v3-golden-hashes/` MUST stay
byte-identical to these files; a CI lint enforces parity.

## Rule: goldens are immutable.

If a test fails, do not "fix" the fixture. Investigate the encoder change,
then either back out the change or update the goldens deliberately and
update both PHP and TS fixtures in the same PR.

The goldens lock the v3 canonical hash format. Any drift is a fiscal-chain
bug that breaks NF525 verify-chain across PHP and TS.

## Regenerating goldens (deliberate, rare)

When intentionally changing the canonicalization algorithm:

1. Update the encoder/builder.
2. Run `cd apps/api && ./vendor/bin/phpunit --filter Canonical` — tests will fail.
3. Briefly comment out the `assertSame($fixture['expected_canonical'], ...)` and
   `assertSame($fixture['expected_hash'], ...)` lines in
   `CanonicalPayloadBuilderTest.php`.
4. Add a temporary line that `dd($actual, hash('sha256', $actual))` to print
   the new goldens.
5. Update each fixture's `expected_canonical` and `expected_hash` with the
   printed values.
6. Restore the assertions; tests should now pass.
7. Update the TS fixtures at `apps/pos/src/lib/fiscal/v3/__fixtures__/v3-golden-hashes/`
   with the same values.
8. Commit both sides in the same change with a descriptive message.

## Fixtures

- `01-cash-only-eur.json` — single cash tender, EUR
- `02-mixed-tender-tnd.json` — cash+card, TND scale-3, two VAT rates
- `03-voucher-tender-eur.json` — voucher tender with instrument_serial, redemption ledger
- `04-stacked-vouchers-eur.json` — two voucher codes + cash (secondary-sort exercise)
- `05-return-with-voucher-issuance-eur.json` — credit note with audit + voucher issuance
- `06-exchange-pair-eur.json` — exchange_group_id committed in hash
- `07-tnd-residual.json` — TND scale-3 with sub-millime voucher residual at internal precision
