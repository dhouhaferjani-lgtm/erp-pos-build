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

The campaign registers the **Parapharmacy** vertical (batch tracking on by default, which the expiry leg needs) with **Tunisia** fixtures, tax number, VAT rate and GL pins; other verticals and countries are unsupported until the fixtures are parameterised (`--country` is reserved). The target must also have a worker consuming `imports` and `fiscal-projections`; L1/L6/L7 time out with `projection timeout — worker running?` otherwise.

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

## Preconditions and limits (read before trusting a red or a green)

- **Queue worker.** Imports and fiscal projections are queued (`imports`, `fiscal-projections`). A target without a running worker fails L1/L6/L7 with `projection timeout — worker running?` — that is the campaign telling you the worker is down, not a product bug.
- **Registration budget.** The target's `POST /auth/register` is throttled (5 per window per IP) and provisions ~576 tenant migrations synchronously in-request (20–60 s; staging caps requests at 60 s until the P0-2 hotfix raises it). One run = one registration; iterate with reuse mode (below) rather than burning the window.
- **Country.** The fixtures, tax number, VAT rate (19.00), timezone (`Africa/Tunis`) and GL pins are **Tunisia-only tonight**; `--country` is reserved and any other value is unsupported until the fixtures are parameterised.
- **Push trigger stays INERT.** Do not set the repository variable `ONBOARDING_CAMPAIGN_ON_PUSH=true` until three items close: fixtures parameterised per country, reuse mode hardened for post-L4 tenants, and a tenant teardown for the target. Until then the campaign is run on demand (local or `workflow_dispatch`).
- **Tenant retention.** There is **no teardown**: every run leaves its `tenant_<uuid>` database behind (db-per-tenant). `CAMPAIGN_KEEP_TENANT=1` only *prints* the credentials for manual follow-up; cleanup of accumulated campaign tenants is an operator task (`tenants:list` → delete the `campaign+…@test.otospex.dev` tenants). Do not arm the push→dev trigger on a target you cannot clean.
- **Artifacts.** Traces are retained on failure only; the fixed campaign password appears in the ledger only with `CAMPAIGN_KEEP_TENANT=1`. Treat uploaded artifacts as internal.

## Reuse mode (debugging / staging triage)

`CAMPAIGN_REUSE_EMAIL=… CAMPAIGN_REUSE_PASSWORD=… scripts/campaign-onboarding.sh` logs into an existing campaign tenant instead of registering: L0 skips the second-company census, and the journey runs on the tenant's original company. Caveats: a tenant that already ran L1 will record the G-14 finding on the next parties import (balances after a posted batch are skipped by design) and a tenant past L5 (locked) cannot re-run L1–L4 meaningfully — reuse mode is for iterating on a single leg, not for a green run.

## Known red (as of 2026-08-29)

The findings gate (L10) is red while any product finding is recorded. Findings the campaign records on the current tree and who owns them:

| Finding | Leg | Owner |
|---|---|---|
| Registration body prefixed by migration echo (P0) | L0 | Session J lane `migration-echo-p0` — fixed on dev `5656c9899` |
| Registration exceeds the request time limit under load (P0-2) — recorded when the server answers the time-limit 500; the client waits up to 300 s to match the hotfix seam | L0 | Session J lane `registration-timeout-p0` |
| `GET /payment-repositories` is tenant-scoped (company B sees company A's drawers) | L0 | Treasury — owner routing owed |
| Products import never writes `unit_id` (I2-F2) | L2 | Session G (unit resolver, G-13/G-4) |
| Second parties-with-balances import silently skips balances (G-14) | L1 (reuse mode / second file) | Session G |

L9 (cash count + Z) is `NOT_SCRIPTABLE` by declaration until lane I-3 vendors the Z-session authoring.
