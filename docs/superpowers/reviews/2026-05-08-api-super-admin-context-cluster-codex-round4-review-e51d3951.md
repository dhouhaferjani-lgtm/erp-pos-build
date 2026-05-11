# api.super-admin-context + web.super-admin-frontend — Codex round-4 APPROVE (derivative, pinned to e51d3951)

Reviewed commit: e51d3951
Commit reviewed: e51d3951
Reviewer: codex
Date: 2026-05-08
Verdict: APPROVE

## Multi-commit lineage

This derivative review file pins the round-4 APPROVE verdict to
`e51d3951` (the per-row `fix_commit` shared by all 6 callsites in this
paired-cluster session).

The canonical Codex round-4 verdict at
`docs/superpowers/reviews/2026-05-08-api-super-admin-context-cluster-codex-round4-review.md`
reviewed cumulative tip `28b8de47`, which includes round-2 (`3c4f728e`)
and round-3 (`28b8de47`) fixes for OTHER controllers/methods that are
NOT inventoried in this cluster.

The 6 inventoried rows' actual fixes all landed at `e51d3951`:

  - `api.super-admin-context.001` — manual: arch test +
    `#[CrossTenantRoute]` attribute application on the SuperAdminController
    (13), SuperAdminAuthController (1: login), AdminBillingController
    (14), MonitoringController (12), CountryController (2),
    StripeWebhookController (1), EnrichmentWebhookController (1),
    PurchaseHubWebhookController (1), AuthController (4: pre-auth)
    surface set.
  - `api.unmapped.020` — AdminBillingController::getInvoice attribute.
  - `api.unmapped.021` — AdminBillingController::downloadInvoice attribute.
  - `api.unmapped.022` — AdminBillingController::getPayment attribute.
  - `api.unmapped.023` — AdminBillingController::refundPayment attribute.
  - `web.super-admin-frontend.001` — manual: web arch test +
    `FraudAlertActionModals` queryKey `['admin-users']` →
    `['users','admin-role']` + `getAdminUsers` →
    `getUsersWithAdminRole`.

Subsequent commits `3c4f728e` (round-2 fix: 41 new attribute applications
across 9 GROUPS for previously-deferred controllers + deferrals fixture
revision) and `28b8de47` (round-3 fix: PurchaseHubOfferController::index
reclassified, VoucherSyncController reverted from POS scope) addressed
controllers and methods that are NOT inventoried in this cluster. They
are reviewed as part of the round-4 cumulative APPROVE but they don't
back the per-row `fix_commit` for the 6 inventoried callsites.

This derivative file pins the verdict to `e51d3951` per the artisan
workflow's `--review-commit == fix_commit` invariant.

## Verdict content

Verdict content is identical to the canonical round-4 file. Refer to
`docs/superpowers/reviews/2026-05-08-api-super-admin-context-cluster-codex-round4-review.md`
for the full mutation-test trail and the BLOCKER/closure history
(round-1 → round-2 → round-3 checklist defect → round-4 APPROVE).

## Sign-off

Signed off. Locking the 6 callsites against this pinned verdict.
