# Automated onboarding campaign

This campaign is AutoERP's fresh-tenant promotion gate. It registers a unique tenant, exercises the onboarding data path in serial order, and checks where every money-bearing action landed through public UI and API contracts. A red campaign blocks promotion; the orchestrator links this document from the applicable `docs/handoff/PROMOTION-CHECKLIST-*` row.

## Run it

Against the live local stack:

```bash
scripts/campaign-onboarding.sh
```

Against staging, dispatch `.github/workflows/onboarding-campaign.yml`, or run:

```bash
scripts/campaign-onboarding.sh \
  --web https://erp.otospex.dev \
  --api https://api.erp.otospex.dev \
  --country TN
```

The expiry leg requires the public BatchExpiry contract and default batch tracking, so a target without the Parapharmacy registration vertical fails L0 with an explicit product finding. The target must also have a worker consuming `fiscal-projections`; L6 and L7 time out with `projection timeout — worker running?` when it does not. `CAMPAIGN_KEEP_TENANT=1 scripts/campaign-onboarding.sh` retains and prints the generated credentials for a manual tester.

## Legs

| Leg | Drive | Promotion assertion |
|---|---|---|
| L0a | network-free | Vendored canonical encoder matches both frozen payload bytes and SHA-256 vectors. |
| L0 | UI, then API-contract for company 2 | Registration lands on a dashboard; both companies receive meaningful day-one census data. |
| L1 | UI | Four party balance quadrants produce the right HIST documents and balances; rerun is idempotent. |
| L2 | UI | Four products preserve unit strings and opening stock, resolve unit IDs, and rerun without doubling stock. |
| L3 | UI result, API assertion | The imported batch product's DEFAULT lot preserves the file expiry and agrees with aggregate stock. |
| L4 | API-contract | Accounting opening batch seeds drawer and bank balances, opening equity, and a balanced trial balance. |
| L5 | API-contract, then UI | The opening batch locks and a later balance import is refused. |
| L6 | API-contract (device-authored chain) | A v5 sale projects receipt, stock/DEFAULT lot decrement, revenue, VAT, and cash tender legs. |
| L7 | API-contract (device-authored chain) | A v4 refund on the same chain restores stock/lot and reverses sales, VAT, and cash. |
| L8 | API-contract | Customer payment settles the HIST invoice, partner receivable, repository, and GL together. |
| L9 | API-contract (device-authored chain) | `NOT_SCRIPTABLE`: Z-session authoring is not vendored; follow-up lane I-3 owns it. |

## Evidence and maintenance

Each run writes `apps/web/test-results/campaign-<runId>/ledger.json`; Playwright writes `apps/web/playwright-report/index.html`, traces, screenshots, video-on-failure, and `apps/web/test-results/campaign-report.json`.

To add a leg, place its `test()` in serial order, add the matching ledger definition in `journey.ts`, and add every UI entry locator to `selectors.ts`. Use role, label, or testid locators only. New assertions must use real APIs, compare money as scale-3 strings, and record product failures without weakening the expectation.
