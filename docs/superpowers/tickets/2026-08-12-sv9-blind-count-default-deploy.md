# SV-9 blind-count default deployment

SV-9 enables blind cash counting for every vertical. Promotion runs the tenant migration `2026_08_12_100000_enable_blind_cash_count_for_existing_settings.php` automatically through `tenants:migrate`.

## Unattended behavior

- The migration checks that `company_fraud_settings` and `require_blind_cash_count` exist before writing.
- It updates every persisted settings row whose value is false, then changes the database default to true. There is no provenance column that distinguishes the 2026-04-25 seed from a later administrator choice, so an explicitly disabled tenant is also re-enabled; this is the intended blast radius of the binding "ON everywhere" ruling.
- It is idempotent: the second run changes zero rows.
- It does not catch database exceptions; a genuine failure aborts migration bookkeeping instead of being mislabeled successful.
- It never touches shifts, receipts, fiscal rows, Treasury repositories, accounts, or `TREASURY_SHIFT_VARIANCE_GL_ENABLED`.

## Deployment evidence

At warning level, each tenant emits one line beginning:

```text
SV-9 BLIND COUNT BACKFILL COMPLETE:
```

The line includes `tenant=<key>`, `changed=<count>`, and `skipped=<count>`. After deployment, verify one completion line per migrated tenant and investigate any tenant without the token. The direct migration test proves a seeded false row changes to true and a second run reports `changed=0`.

## Device cache note

The shipped v22 SQLite migration remains byte-compatible with field devices and therefore retains `DEFAULT 0`. That default is currently inert: the only production upsert binds `require_blind_cash_count` explicitly, and before the first successful settings sync there is no cache row, so the end-of-day cash-count section is withheld by its null-settings gate. The active concealment contract comes from the server-synced value and the null gate, not from the SQLite column default. No follow-up schema migration is warranted until a real writer can omit that column.
