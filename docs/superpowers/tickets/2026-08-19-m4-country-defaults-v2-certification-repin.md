# M4 country-defaults v2 certification fixture re-pin

**Owner:** parent orchestrator / Country Defaults

**Trigger:** Wave 3D M4 makes `InventoryShrinkageExpense` REQUIRED for newly certified templates because
Damage/Expiry/WriteOff posting is live.

## Parent-visible reconciliation

The parent-reconciled M4 fixture previously represented the frozen `*.legacy-v1` bootstrap content. M4
keeps those production bootstrap rows and all three legacy seeders byte-identical, but certification tests
must now represent the Option A v2 content (`6586` / `7586`). Consequently:

- `Tests\Support\CountryDefaults\M4Fixtures` appends the two approved rows;
- `TemplatePublishGateTest` re-pins the canonical content hash from
  `c3436e61299a8fc0a7cdee4f4eb449e54bbef9738dccee7e22f61ec3854aafc7` to
  `cffde0426400e33746f2e6a133d577ec6967368a9e806d2c835cea518be251b6`;
- `CertifiedFixtureDeltaTest` now proves the Option A v2 certification has zero implicit content delta;
- historical published-v1 rollback protection is recreated directly, because v1 cannot be newly
  certified after the REQUIRED classification.

## Promotion action

At merge review, the parent must acknowledge this test-fixture/hash re-pin as the intended v2
certification baseline. Owner-checklist G2 must be executed against the three `*.default-v2` bootstraps,
not the frozen `*.legacy-v1` bootstraps. No production seeder fingerprint is re-pinned by this ticket.
