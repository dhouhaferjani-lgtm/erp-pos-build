# api.webhooks-incoming cluster — Codex round-2 re-review

Review date: 2026-05-07
Branch tip reviewed: fa81840c
Reviewer: codex (round-2 cross-agent review of Claude's round-1 BLOCKER + NICE-TO-HAVE #2 follow-up)

Verdict: APPROVE
Commit reviewed: 00360386
Round-1 review: docs/superpowers/reviews/2026-05-07-api-webhooks-incoming-cluster-codex-review.md
Round-1 commit reviewed: 00360386
Round-2 follow-up commit (round-1 BLOCKER + NICE-TO-HAVE #2 remediation): fa81840c

Per the multi-batch fix-commit convention from the kickoff (api.scheduled-jobs
round-2 precedent): the `Commit reviewed:` line above pins the most-common
fix_commit across the cluster's callsites (api.webhooks-incoming.001, .002,
and .003 all carry fix_commit=00360386 from initial submission). The round-2
follow-up commit fa81840c hardens the WebhookControllerTenantContextTest
stub-shape guard (closes round-1 BLOCKER #1: scans all declared methods,
inspects constructor parameter types, expands forbidden-pattern regex
coverage, switches STUB trigger to explicit `STUB:` colon-prefixed marker)
and adds the test_stub_inspector_catches_known_bypass_shapes negative test
+ five WebhookFixtures classes pinning each of the round-1 bypass shapes —
without modifying the fix_commit-bearing controllers. The fix_commit on
each callsite stays at 00360386 because the controllers themselves are
unchanged from the original submission; the round-2 fix is exclusively in
the architecture test + fixtures.

## Verdict rationale

The round-2 fix closes the round-1 blocker: the PurchaseHub STUB guard now inspects all same-class methods, rejects non-allowlisted constructor dependencies, expands the forbidden call patterns to cover the previously missed Eloquent/query-builder families, and uses an explicit `STUB:` marker. Baseline PHPUnit passed with `OK (2 tests, 9 assertions)`, all four required mutations failed for the intended reasons, and inventory history remains valid.

## Round-1 BLOCKER closure

Bypass shape (a), `Model::query()->insert(...)`, is now covered by the expanded `::query` and `::insert` patterns and pinned by `StubWithModelQueryInsertFixture`.

Bypass shape (b), `::updateOrCreate(...)`, `::firstOrCreate(...)`, `::insert(...)`, and `::upsert(...)`, is now covered by explicit static-call patterns and pinned by `StubWithUpdateOrCreateFixture`.

Bypass shape (c), same-class helper writes, is now covered because `inspectStub()` walks every method declared on the controller class and includes the method name in violations; `StubWithHelperMethodWriteFixture` proves the `persist` helper is reached.

Bypass shape (d), constructor-injected service dependency, is now covered because a stub controller constructor may only use primitive types or `Illuminate\Http\Request` / `Psr\Log\LoggerInterface`; `StubWithServiceDependencyFixture` proves a non-allowlisted dependency is rejected.

The annotation trigger is now colon-anchored with `STUB:` via `/^STUB:\s/`, so the round-1 fragile `^STUB\b` behavior is fixed.

## Round-2 mutation tests

Mutation (a), removing `@cross-tenant-by-design` from `StripeWebhookController`, failed with `Found webhook controller class(es) with no @cross-tenant-by-design annotation` and named `App\Modules\Billing\Presentation\Controllers\StripeWebhookController`.

Mutation (b), adding `\App\Models\User::find(1);` to `PurchaseHubWebhookController::__invoke()`, failed with `__invoke: forbidden pattern matches /::find(?:OrFail|Many|OrNew)?\(/`.

Mutation (c), adding `$this->persist([])` plus a private `persist()` method calling `\App\Models\User::create($p)`, failed with `persist: forbidden pattern matches /::create\(/`, proving the all-method walk reaches private helpers.

Mutation (d), adding `public function __construct(private readonly \App\Models\User $u) {}`, failed with `constructor: forbidden dependency type App\Models\User on parameter $u`.

All temporary controller edits were restored; final source diffs for the mutated controllers were empty.

## NICE-TO-HAVE deferrals

NICE-TO-HAVE #1 remains reasonable to defer to the future api.billing cluster. The reviewed Stripe paths stamp invoice/payment/notification tenant context from the resolved subscription or payment row, and the proposed extra invoice-vs-subscription assertion is defense-in-depth for corrupted data rather than a current isolation blocker.

NICE-TO-HAVE #3 remains reasonable to defer with Finding F. The `billing_payments` migration currently has a non-unique `provider, provider_payment_id` index, so a duplicate-data preflight belongs with the future uniqueness migration in api.platform-integration or api.external-id-uniqueness.

NICE-TO-HAVE #4 remains reasonable to defer to api.purchase-hub. The PurchaseHub webhook route is currently a verified noop stub and inherits the API rate limiter. Spot-check note: `AppServiceProvider` currently sets the general API limiter to 100 requests/minute per user/IP, not 60, but that does not make a webhook-specific throttle/body-size policy load-bearing for this round.

## Inventory state

`php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` returned:

```text
verified 1600 event(s) across 326 callsite(s); 0 problem(s).
```

The `api.webhooks-incoming` cluster remains `status: in_progress`. The three callsite rows `.001`, `.002`, and `.003` remain `status: under_review` with `fix_commit: '00360386'`, matching the stated multi-fix-commit convention.

## BLOCKERs (if any)

None.

## NICE-TO-HAVEs (if any)

None.

## Sign-off

Approved for round 2.
