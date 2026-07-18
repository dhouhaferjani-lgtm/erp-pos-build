# Gate t5a-gate-3 — tenancy/authz follow-up after REJECT

You are the same adversarial **tenancy-authz-reviewer** for `/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5`, branch `feat/treasury-phase5`, current HEAD.

The first review is recorded at `.gates/gate-t5a-gate-3-verdict-tenancy-authz.md`. It found shipped behavior correct but REJECTED on one Important coverage gap and one Minor gap:

1. no cross-company 404/mutation-denial test across all four outbound endpoints;
2. manager 403 covered clear/cancel but not bounce/represent.

The test-only fix is commit `3276b1b13` after Wave 3 commit `0dc08a2fa`.

Review the fix and the original controller/routes/seeder boundary:

```bash
git diff 0dc08a2fa..HEAD -- apps/api/tests/Feature/Treasury/OutboundInstrumentEndpointsTest.php
git diff t5a-gate-2..HEAD -- \
  apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentInstrumentController.php \
  apps/api/app/Modules/Treasury/Presentation/routes.php \
  apps/api/database/seeders/RolesAndPermissionsSeeder.php \
  apps/api/tests/Feature/Treasury/OutboundInstrumentEndpointsTest.php
```

Verify with `file:line` evidence:

- the new fixture is a real second company under the tenant, with valid chart/repository/method/partner/instrument rows;
- admin scoped to the first company receives 404 from clear, bounce, represent, and cancel for the other-company instrument;
- the test proves no mutation survives;
- manager receives 403 from all four actions;
- no assertion or permission was weakened to make the test pass;
- original middleware, UUID, company-scope, and permission split remain correct.

Fresh evidence, by path only:

- SQLite `OutboundInstrumentEndpointsTest.php`: 6 passed / 23 assertions.
- PostgreSQL isolated DB, same path: 6 passed / 23 assertions.
- PHPStan level 8 on the test: no errors. Pint: pass.

Never run the full PHPUnit suite.

First line exactly `GATE VERDICT: APPROVE` or `GATE VERDICT: REJECT`. Findings ordered by severity with `file:line`. End with `VERDICT: spec ✅/❌ + quality APPROVED/CHANGES-REQUESTED` and one line stating what remains before Wave 4.
