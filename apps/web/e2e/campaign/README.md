# Onboarding campaign

This serial Playwright journey is the fresh-tenant promotion gate.

Run locally from the repository root with `scripts/campaign-onboarding.sh`.

Override targets with `--web URL`, `--api URL`, and `--country TN`.

The equivalent environment variables are `CAMPAIGN_WEB_URL`, `CAMPAIGN_API_URL`, and `CAMPAIGN_COUNTRY`.

Set `CAMPAIGN_KEEP_TENANT=1` to print the generated login for manual follow-up (every run's tenant is retained regardless — there is no teardown).

Every run creates a unique tenant and never mutates another run's tenant (reuse mode, below, is the deliberate exception).

The ledger is `apps/web/test-results/campaign-<runId>/ledger.json`.

The HTML report is `apps/web/playwright-report/index.html`.

Serial legs: L0a golden vectors; L0 registration/census; L1 parties; L2 products; L3 lots; L4 openings; L5 lock; L5b terminal + `SESSION_OPEN`; L6 sale; L7 refund; L8 allocation; L9 `SESSION_CLOSE` + `Z_REPORT`; L10 findings gate.

L5b–L9 thread one UUID through `session_id`, `shift_id`, and both receipt payloads. The lifecycle is intentionally server-minimal: L4 supplies the opening float and no separate `OPENING_FLOAT` fiscal event is authored.

To add a leg, append it in serial order, add its ledger definition in `journey.ts`, and keep every UI entry locator in `selectors.ts`.
Do not mock requests or use database access: campaign assertions must travel through the public UI/API contract.

Reuse mode: `CAMPAIGN_REUSE_EMAIL` + `CAMPAIGN_REUSE_PASSWORD` log into an existing tenant (no registration, no second-company census); see `docs/qa/ONBOARDING-CAMPAIGN.md` for its caveats. No teardown exists — campaign tenants accumulate on the target.
