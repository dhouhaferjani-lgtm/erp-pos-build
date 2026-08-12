# SV-9 blind-count default deployment

SV-9 enables blind cash counting for every vertical. Promotion runs the tenant migration `2026_08_12_100000_enable_blind_cash_count_for_existing_settings.php` automatically through `tenants:migrate`.

## Unattended behavior

- The migration checks that `company_fraud_settings` and `require_blind_cash_count` exist before writing.
- It updates only persisted settings rows whose value is false, then changes the database default to true.
- It is idempotent: the second run changes zero rows.
- It does not catch database exceptions; a genuine failure aborts migration bookkeeping instead of being mislabeled successful.
- It never touches shifts, receipts, fiscal rows, Treasury repositories, accounts, or `TREASURY_SHIFT_VARIANCE_GL_ENABLED`.

## Deployment evidence

At warning level, each tenant emits one line beginning:

```text
SV-9 BLIND COUNT BACKFILL COMPLETE:
```

The line includes `tenant=<key>`, `changed=<count>`, and `skipped=<count>`. After deployment, verify one completion line per migrated tenant and investigate any tenant without the token. The direct migration test proves a seeded false row changes to true and a second run reports `changed=0`.
