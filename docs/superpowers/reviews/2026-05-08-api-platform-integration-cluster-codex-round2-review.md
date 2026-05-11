# api.platform-integration cluster - Codex round-2 review

Branch tip reviewed: 9921b617
Commit reviewed: 38a89b0c1c506a9dbb45dfadc497ec13cf08a119
Round-2 amendment commit: 9921b6178c46e6a8aaccaf4b4b2bb8c6e87c1f95
Reviewer: codex
Date: 2026-05-08
Verdict: APPROVE

(Codex's round-2 eyes were on HEAD `9921b617` — the AMENDMENT commit
that closed the round-1 BLOCK-NOVEL on `.006`. The inventory recorded
`.006`'s fix_commit at Step 7 submit time as `38a89b0c` (the original
Step 5 fix that round-1 BLOCKed); the round-2 amendment at `9921b617`
extended the `.006` fix to two more callsites that Step 5 missed
(handleInvoicePaid + handleInvoicePaymentFailed updateOrCreate). For
inventory commit-linkage, this file's "Commit reviewed:" pins to
`.006`'s recorded fix_commit `38a89b0c`, with the round-2 amendment
commit explicitly named on its own line above for traceability.

Sibling files for the other two fix-commit groups:
- 2026-05-08-api-platform-integration-cluster-codex-round2-review-001-002.md
  pins `bc7b445d` (Step 4 fix for `.001` PlatformHttpClient + `.002`
  CheckPendingEnrichmentsCommand)
- 2026-05-08-api-platform-integration-cluster-codex-round2-review-003-005.md
  pins `38a89b0c` (Step 5 fix for `.003` products UNIQUE migration +
  `.004` billing UNIQUE migration + `.005` listener `->sole()`)

Both siblings carry the same APPROVE verdict + per-callsite table
content as this file. Past clusters had a single fix commit, this
one's fixes spread across 3 commits as the natural Step 4 / Step 5 /
round-2 amendment sequencing.)

## Verdict
APPROVE. The round-2 fix closes the round-1 BLOCK-NOVEL finding for `api.platform-integration.006`. Both invoice webhook reconciliation paths now use `(provider, provider_payment_id)` as the `Payment::updateOrCreate()` lookup key, matching the database uniqueness contract and the read-side `resolveStripePayment()` helper. The redundant create/update-side `provider` assignment was removed, and all Stripe predicates use the persisted scalar `PaymentProviderCode::Stripe->value`.

Verification run:
- `cd apps/api && vendor/bin/phpunit tests/Feature/PlatformIntegration/` passed: 36 tests, 89 assertions, 3 skipped, 8 PHPUnit deprecations.
- `cd apps/api && vendor/bin/phpstan analyse --no-progress --memory-limit=2G` passed with `[OK] No errors`.
- `cd apps/api && ./vendor/bin/pint --test app/Modules/Billing/Presentation/Controllers/StripeWebhookController.php tests/Feature/PlatformIntegration/ExternalIdUniquenessTest.php` passed.
- `git diff --stat 58cb06fa..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` was empty.

## Per-callsite Verdict Table
| ID | Verdict | Evidence |
|---|---|---|
| `api.platform-integration.001` | APPROVE, inherited from round-1 | No round-2 changes touched `PlatformHttpClient`; the round-2 diff is limited to `StripeWebhookController.php` and `ExternalIdUniquenessTest.php`. The outbound tenant-header callsite remains approved from round-1. |
| `api.platform-integration.002` | APPROVE, inherited from round-1 | No round-2 changes touched `CheckPendingEnrichmentsCommand` or `PendingEnrichmentDTO`; the per-record company binding verdict remains unchanged. |
| `api.platform-integration.003` | APPROVE, inherited from round-1 | No round-2 changes touched the products `platform_submission_id` migration. The existing post-migration constraint coverage remains sufficient for this review scope; migration-failure fixture tests remain NICE-TO-HAVE. |
| `api.platform-integration.004` | APPROVE, inherited from round-1 | No round-2 changes touched the billing `(provider, provider_payment_id)` migration. The schema invariant still matches the controller fix. |
| `api.platform-integration.005` | APPROVE, inherited from round-1 | No round-2 changes touched `ProcessEnrichmentEventListener`; the `->sole()` zero-row/multiple-row handling remains approved. |
| `api.platform-integration.006` | APPROVE | `handleInvoicePaid()` now calls `Payment::updateOrCreate()` with lookup attributes `provider => PaymentProviderCode::Stripe->value` and `provider_payment_id => $paymentIntentId` at `apps/api/app/Modules/Billing/Presentation/Controllers/StripeWebhookController.php:288-304`. `handleInvoicePaymentFailed()` mirrors the same provider-pinned lookup at `:371-387`. `resolveStripePayment()` still filters by Stripe scalar and uses `->sole()` at `:664-681`. |

## Round-1 Closure Assessment
The BLOCK-NOVEL fix is correct and complete.

Round-1 identified that `handleInvoicePaid()` and `handleInvoicePaymentFailed()` used provider-blind `updateOrCreate()` lookup attributes. In round-2, the lookup side, not merely the write side, pins `provider = stripe`, so a PayPal/Klarna/etc. row sharing the same `pi_*` value cannot be selected and overwritten by a Stripe webhook. Because Laravel seeds new models from the first `updateOrCreate()` attributes, removing `provider` from the second array does not drop provider persistence for newly-created Stripe rows.

The scalar consistency NICE-TO-HAVE is also closed for these two paths: both use `PaymentProviderCode::Stripe->value`, matching `resolveStripePayment()`. The read-side helper remains load-bearing for the three payment-intent/refund callsites and was not regressed.

No regression was found in the round-1-approved callsites. `git diff --name-status a7a109df..9921b617` shows only the Stripe webhook controller and the platform-integration uniqueness test changed. The required POS surface diff is empty.

## Test Non-vacuity
The two new regression tests are non-vacuous and would fail red on the pre-fix code path.

`test_stripe_invoice_paid_handler_filters_by_provider_in_lookup` seeds a PayPal-provider `Payment` with the shared `provider_payment_id`, wires a real `TenantSubscription` via `stripe_subscription_id`, creates an `Invoice` via `stripe_invoice_id`, then invokes `handleInvoicePaid()` by reflection with `subscription`, `payment_intent`, `amount_paid`, and `currency`. On the pre-fix lookup `['provider_payment_id' => $paymentIntentId]`, `updateOrCreate()` would match the PayPal row first and overwrite its provider/status/amount. The test asserts the foreign row remains PayPal, Pending, and amount `777.000`, and separately asserts a distinct Stripe Succeeded row exists.

`test_stripe_invoice_payment_failed_handler_filters_by_provider_in_lookup` exercises the same lookup surface for `handleInvoicePaymentFailed()`: it seeds a PayPal row, wires subscription + invoice + `payment_intent`, invokes the private handler, asserts the foreign row remains PayPal/Succeeded, and asserts a distinct Stripe Failed row with `Card declined` was created. The failed-payment test does not explicitly assert the foreign amount remains `555.000`; that is a small symmetry gap, but not a blocker because the provider/status assertions and distinct Stripe-row assertion would still fail on the old provider-blind `updateOrCreate()` path.

## MUST-FIX
None.

## NICE-TO-HAVE
1. Add migration-failure tests that instantiate/run the two new migrations against duplicate fixture data and assert the intended `RuntimeException` text before schema mutation. This is not load-bearing enough to require round-3 at the current scope because the approved migrations already perform pre-schema duplicate checks, the post-migration constraints are covered, and the round-1 runtime data-corruption path now has direct red/green regression coverage.
2. Add an explicit failed-payment assertion that the foreign PayPal row amount remains `555.000`, matching the paid-path unchanged-state coverage. This would improve test symmetry but is not required for approval because the existing failed-path assertions are already red on the pre-fix bug.
