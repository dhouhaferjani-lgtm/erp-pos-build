# Onboarding campaign

This serial Playwright journey is the fresh-tenant promotion gate.

Run locally from the repository root with `scripts/campaign-onboarding.sh`.

Override targets with `--web URL`, `--api URL`, and `--country TN`.

The equivalent environment variables are `CAMPAIGN_WEB_URL`, `CAMPAIGN_API_URL`, and `CAMPAIGN_COUNTRY`.

Set `CAMPAIGN_KEEP_TENANT=1` to retain and print the generated login for manual follow-up.

Every run creates a unique tenant and never reads or mutates an existing one.

The ledger is `apps/web/test-results/campaign-<runId>/ledger.json`.

The HTML report is `apps/web/playwright-report/index.html`.

To add a leg, append it in serial order, add its ledger definition in `journey.ts`, and keep every UI entry locator in `selectors.ts`.
Do not mock requests or use database access: campaign assertions must travel through the public UI/API contract.

Reuse mode: `CAMPAIGN_REUSE_EMAIL` + `CAMPAIGN_REUSE_PASSWORD` log into an existing tenant (no registration, no second-company census); see `docs/qa/ONBOARDING-CAMPAIGN.md` for its caveats. No teardown exists — campaign tenants accumulate on the target.
