# api.webhooks-incoming cluster — Codex round-1 review

Reviewed commit: f612a431
Reviewer: codex
Date: 2026-05-07
Verdict: BLOCK-WITH-CHANGES-REQUIRED

## Verdict rationale

The Stripe and Syneriva webhook classifications satisfy the reviewed invariant at the controller boundary, and the required architecture test plus inventory-history checks pass. I am blocking on the PurchaseHub stub guard: `PurchaseHubWebhookController` is an unauthenticated, unsigned external ingress route, and the new architecture test is explicitly load-bearing for keeping it side-effect-free, but the test only scans `__invoke()`/`handle()` for a small regex set. Common refactors can add per-tenant writes while bypassing every forbidden pattern, so the stub classification is not enforced strongly enough to approve.

## Area 1 — Stripe annotation rationale

I audited every Stripe event path in `apps/api/app/Modules/Billing/Presentation/Controllers/StripeWebhookController.php`. Signature verification runs before the event dispatch at lines 71-94 via `Stripe\Webhook::constructEvent(...)`. `handleSubscriptionCreated` resolves by `stripe_subscription_id` or `stripe_customer_id` at lines 134-137 and updates the resolved row at lines 148-157; the `orWhere` fallback remains a logic bug, but under the documented one-Stripe-customer-per-tenant model it misselects within the same tenant rather than synthesizing a tenant from request input. `handleSubscriptionUpdated` and `handleSubscriptionDeleted` resolve by `stripe_subscription_id` at lines 171 and 222, and deletion notifies using `$subscription->tenant_id` at line 239. `handleInvoicePaid` and `handleInvoicePaymentFailed` resolve invoice/subscription/payment rows by Stripe IDs at lines 254, 271-277, 325, 335-354, then stamp/notify using `$subscription->tenant_id`, not payload tenant input. `handleInvoiceFinalized` resolves the subscription by `stripe_subscription_id` at lines 391-394 and writes the invoice with `$subscription->tenant_id` at lines 405-410. The payment-intent and refund handlers resolve `Payment` by `provider_payment_id` at lines 449, 473, and 501-502, then update or notify from the resolved `$payment` row, including `$payment->tenant_id` at line 527. DB uniqueness exists for `tenant_subscriptions.stripe_subscription_id` and `billing_invoices.stripe_invoice_id`; `billing_payments.provider_payment_id` relies on Stripe's external uniqueness contract and is correctly deferred as Finding F.

## Area 2 — PurchaseHub stub-shape enforcement

This area is the blocker. The route at `apps/api/app/Modules/PurchaseHub/Presentation/routes.php:22-25` has only `api` middleware, and the controller body at `apps/api/app/Modules/PurchaseHub/Presentation/Controllers/PurchaseHubWebhookController.php:65-74` is currently a noop plus logging. That current body is safe. The enforcement is not. `WebhookControllerTenantContextTest` only scans `__invoke()` and `handle()` at lines 280-317, and its forbidden list at lines 92-105 misses realistic write forms such as `OrderReceipt::query()->insert([...])`, `OrderReceipt::query()->upsert([...])`, `firstOrCreate`, `updateOrCreate`, `forceDelete`, `increment`, `decrement`, and `Http::post(...)` to an internal mutating endpoint. A same-class helper is an even simpler bypass: `__invoke()` can call `$this->persist($payload)`, while `private function persist()` performs the DB write; the helper is not inspected. A service call such as `OrderReceiptService::createFor($payload)` also bypasses regex inspection entirely. Because the test is the stated guard that makes an unsigned stub acceptable, these bypasses are too realistic to treat as minor.

## Area 3 — Cross-cluster observations completeness

I found no missing cross-cluster deferral in the three controllers or `VerifySynerivaWebhookSignature`. Finding D's LOW classification stands as within-tenant subscription-row correctness under the one-customer-per-tenant billing model, not a tenant-isolation issue. Finding E remains MEDIUM functional: duplicate Stripe delivery can double-send notifications and restamp timestamps, but `charge.refunded` still resolves and labels admins from the same `Payment` row. Finding F remains a LOW defense-in-depth issue; adding the recommended unique constraint should be preceded by a data cleanup/assertion migration because this review did not prove production data has no duplicates. Finding G's current-state LOW only stands if the stub guard is strengthened; the unsigned route also inherits the general API limiter (`RateLimiter::for('api')`, `AppServiceProvider.php:152-158`) but has no webhook-specific body-size or signature gate. The new `api.webhooks-incoming triage deferrals` section appears after the Workflow gap material and before Resolution/References at `docs/superpowers/audits/2026-05-07-scheduled-jobs-cross-cluster-observations.md:350-584`.

## Area 4 — Architecture test correctness

Baseline command: `cd apps/api && vendor/bin/phpunit tests/Architecture/WebhookControllerTenantContextTest.php` passed with `OK (1 test, 3 assertions)`. Mutation 1 removed the Stripe class annotation and the test failed clearly with `Found webhook controller class(es) with no @cross-tenant-by-design annotation: App\Modules\Billing\Presentation\Controllers\StripeWebhookController`. Mutation 2 added `\App\Models\User::find(1);` to `PurchaseHubWebhookController::__invoke()`, and the test failed clearly with `Found STUB-classified webhook controller(s) whose method body contains forbidden call patterns` for `::find`. I restored both temporary edits with reverse patches because `git checkout -- <path>` could not create the repo index lock in this sandbox; final diffs for both source files were empty, and the architecture test was rerun green.

## Area 5 — Inventory state integrity

`cd apps/api && php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` returned `verified 1600 event(s) across 326 callsite(s); 0 problem(s).` The cluster row at `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml:426-431` has `id: api.webhooks-incoming`, `owner: claude`, `required_owner: codex`, and `status: in_progress`. The three callsite rows `.001`, `.002`, and `.003` at lines 36216-36496 each have `status: under_review`, `owner: claude`, `fix_commit: '00360386'`, the required `WebhookControllerTenantContextTest` regression test, and at least generate, claim, start, and submit history events.

## BLOCKERs (if any)

1. `apps/api/tests/Architecture/WebhookControllerTenantContextTest.php:92-105` and `:280-317` do not provide a strong enough CI guard for the unsigned PurchaseHub stub. A developer can add tenant mutations through `Model::query()->insert(...)`, `updateOrCreate`, `firstOrCreate`, a same-class private helper, or a mutating service call without matching the current regexes. Remediation: make STUB enforcement allowlist-based instead of narrow denylist-based, inspect all methods declared on the stub controller, reject helper methods/constructor service dependencies unless explicitly allowlisted, add patterns for common Eloquent/query-builder write APIs, and add negative tests for the bypass shapes above. Alternatively, wire PurchaseHub signature verification and tenant resolution now, then remove the stub classification.

## NICE-TO-HAVEs (if any)

1. Add explicit consistency assertions in Stripe invoice/payment flows so an existing `Invoice` row's `tenant_id` must match the subscription-derived tenant before notification or payment writes proceed.
2. Replace the fragile `^STUB\b` trigger with an explicit annotation marker such as `@cross-tenant-by-design-stub` or a mandatory `STUB:` prefix, then test that malformed stub annotations fail closed.
3. Add a preflight duplicate-data assertion before the future `(provider, provider_payment_id)` unique constraint migration for Finding F.
4. Give PurchaseHub a webhook-specific throttle/body-size policy even while it remains a noop, to reduce log-amplification risk on the public route.

## Sign-off

This review approves the Stripe and Syneriva webhook rationale and the inventory state, and it confirms the required positive and mutation-test checks behave as specified. It intentionally leaves Findings D-F deferred. It does not approve the current PurchaseHub stub guard as sufficient for the unsigned route; the cluster should remain under review until the architecture test fails closed for realistic side-effect additions or the route is converted to a real signed, tenant-resolving webhook.
