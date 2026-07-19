# Treasury Phase ⑤a live exit report

Date: 2026-07-19

Branch: `feat/treasury-phase5`

Stack: API `127.0.0.1:8010`, Vite `127.0.0.1:5173`, PostgreSQL db-per-tenant

Central database: `autoerp_treasury_phase5_e2e`

Tenant: `019f7946-812a-7297-9448-ed4bc5d7415e` (`demo-pharmacy-tn`)

## Driven path

The live Playwright smoke is `apps/web/e2e/smoke/treasury-phase5a-outbound.smoke.ts`. It uses only public HTTP endpoints for setup and lifecycle writes, then observes the resulting state through the real React application.

Final successful run:

```text
7 passed (23.4s)
```

Command:

```bash
cd apps/web
pnpm exec playwright test e2e/smoke/treasury-phase5a-outbound.smoke.ts \
  --config playwright.smoke.config.ts --project chromium
```

The run proved:

1. A supplier invoice was created from a received purchase order and posted through the procurement API.
2. Deferred supplier cheque `P5A-SUP-1784446523202-aeec96a3` (`019f794d-2134-7352-b490-f516e5c04692`, `23.800 TND`) was issued against `BANK-01`. Issue left the repository balance unchanged.
3. The browser rendered the instrument in the outbound payable schedule.
4. Clearing created the outbound repository movement and reduced the bank balance.
5. Bouncing restored the bank balance and rendered the bounced state in the browser.
6. Re-presentation returned the instrument to `cleared`; its public event history contains `re_presented`, `bounced -> cleared`, and the second clear returned the repository to the same post-clear balance.
7. Expense `EXP-2026-000013` was issued by cheque `P5A-EXP-1784446523202-aeec96a3` (`019f794d-60ec-7178-b8d1-ffe68523ef76`, `37.125 TND`). Clearing marked the expense paid and the browser rendered the paid state with `BANK-01`.
8. That second cheque was bounced and cancelled from `bounced`; the final instrument state is `cancelled`, and the expense was reopened with `is_paid=false` and no linked instrument.

Screenshots:

- `01-issued-payable-schedule.png`
- `02-supplier-cheque-cleared.png`
- `03-supplier-cheque-bounced.png`
- `04-supplier-cheque-represented.png`
- `05-expense-paid-after-clear.png`

The dedicated disposable database also contains artifacts from earlier harness-debug iterations. They are not production data and were left intact deliberately so append-only lifecycle evidence was not rewritten or deleted.

## Reconciliation

Command:

```bash
cd apps/api
php artisan treasury:reconcile --tenant=019f7946-812a-7297-9448-ed4bc5d7415e
```

Result (exit 0):

```text
treasury:reconcile — checked 7 repository(ies); froze 0 on cash drift; found 0 portfolio drift(s); 0 error(s).
```
