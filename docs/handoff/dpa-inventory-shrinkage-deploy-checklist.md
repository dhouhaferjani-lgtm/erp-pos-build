# Inventory shrinkage purpose deploy checklist

This is the operator gate for Wave 3D M4's automatic repair of existing tenant charts. It does not
discharge S-16; the per-tenant duplicate-count query remains a parent-side pre-promotion gate.

## Automatic `tenants:migrate` gate

For every tenant expected in the deployment, require exactly one warning-level line containing:

```text
INVENTORY-SHRINKAGE-PURPOSE BACKFILL MIGRATION: tenant=<tenant> status=ok exit=0
```

Any missing line, `status=FAILED`, or `exit=exception` blocks promotion. Do not infer success from an
empty log: production drops info-level records.

## Repair a failed tenant

Run the idempotent command through the tenant runner and require its final line to be:

```text
INVENTORY-SHRINKAGE-PURPOSE BACKFILL FAILURES: 0
```

The command reports the company and exact refusal. Repair the chart deliberately: map the approved
purpose to an existing valid account, free a claimed `6586`/`7586`, or add the missing approved parent.
Then rerun the command and the detector before promotion. A non-TN/FR custom PCG-shaped template is
intentionally fail-loud if it does not contain the Generic `6000`/`7000` parents; do not silently infer
a country map from visual chart shape.

## Scope boundaries

- Do not edit or re-pin the three frozen legacy seeders.
- A company provisioned later through the legacy-seeder fallback can still lack the purposes; the writer
  warns and creates no variance entry until this same idempotent command is run.
- Expert-comptable ratification under OQ-12/H-5 is still required before M5 makes count-correction
  posting live.
