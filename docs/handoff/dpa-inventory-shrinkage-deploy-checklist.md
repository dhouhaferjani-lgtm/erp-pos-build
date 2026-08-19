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
a country map from visual chart shape. This strict command is the pre-G2 legacy-chart repair path. After
template provisioning is enabled, repair a cross-plan template overlay through a reviewed migration or
template correction; do not inject a country-derived root into a different chart plan.

## Scope boundaries

- Do not edit or re-pin the three frozen legacy seeders.
- Normal registration and second-company creation call `ChartOfAccountsService`, which installs both
  approved purposes atomically after the frozen legacy seeder. For an assigned template, this is an
  explicit post-template overlay: REQUIRED shrinkage already present in the template is used as is;
  otherwise it is installed beneath a same-type `65`/`6000` parent, and on a chart plan that carries
  neither of those it is grafted onto the lowest same-type root present, or installed as a root of its
  own, under the warning token
  `INVENTORY-VARIANCE-TEMPLATE-OVERLAY grafted required shrinkage onto a fallback parent` — company
  creation is never aborted for a missing parent. The SOFT gain is installed beneath a same-type
  `75`/`7000` parent when one exists; a chart plan without those revenue parents remains usable and emits
  `INVENTORY-VARIANCE-TEMPLATE-OVERLAY skipped optional gain` instead. The asymmetry is deliberate: a
  missing shrinkage account silently drops a write-off journal entry, while a missing gain fail-softs in
  `InventoryGlPostingService::postForCountCorrection`, so an unreviewed parent is worse than an absent
  optional account. Reconcile a fallback-grafted or skipped account through a reviewed template
  correction before it reaches an expert-comptable export.
  `country-defaults:verify` validates the template and assignment; per-company chart health
  (`ChartOfAccountsService::validateCompanyAccounts`, which iterates
  `SystemAccountPurpose::requiredPurposes()`) validates the REQUIRED shrinkage purpose only —
  `InventoryGainIncome` is deliberately absent from that list because it is SOFT and its only consumer
  fail-softs, so a chart legally omitting `7586` must not be reported unhealthy. The warning token above
  is the operator signal for an omitted optional gain; chart health will not report it. No production
  writer calls a frozen seeder directly. Raw calls exist only in the rollback-owned preview, golden
  exporter/tests, and historical migrations; any future direct writer must use the same guarded no-entry
  compatibility contract until the idempotent command is run.
- Historical Damage/Expiry/WriteOff entries remain in COGS (`601`/`603`); entries after this cutover land
  in shrinkage (`6586`). Period-over-period COGS, expense reports, and expert-comptable exports spanning
  the cutover therefore show a deliberate step change; do not restate hash-sealed historical journals.
- Expert-comptable ratification under OQ-12/H-5 is still required before M5 makes count-correction
  posting live.
