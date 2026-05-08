# api.broadcast-channels cluster — Codex round-4 review

Reviewed commit: 5ce633763c584f290785cd57ba97f83134e1b3e0
Reviewer: codex
Date: 2026-05-08
Verdict: APPROVE

## Verdict rationale
Round 4 is sound after the orchestrator pivot: the static analyzer is now honestly framed as a best-effort code-review lint, and the behavioral `/broadcasting/auth` integration suite is the load-bearing check. I found no blocker-class vacuity in the endpoint tests: the custom broadcaster delegates to Laravel's real channel verification path, denial assertions fail when the relevant production helper is mutated open, cross-company inputs really target `companyA2`, and the tested cluster shape remains unchanged in production source since `dc67bcdb`. I could not complete `git pull --ff-only` in this sandbox because writing `.git/FETCH_HEAD` was denied, but the checked-out branch tip was already `5ce63376`.

## Area 1 — Static analyzer regression check
Baseline `cd apps/api && vendor/bin/phpunit tests/Architecture` passed: `OK (7 tests, 33 assertions)`.

Mutation m1, replacing the product-channel helper return with `return true;` while keeping `@cross-tenant-anchored`, failed under `tenant_named_without_helper` at `routes/channels.php:26`.

Mutation m2, wrapping the product helper return in `if (false) { ... } return true;`, failed under `tenant_named_without_helper` at `routes/channels.php:26`.

Mutation m3, appending `/** @cross-tenant-by-design */` plus `Broadcast::channel('global.something', fn () => true);`, failed under `bare_annotations` at the appended call site. `routes/channels.php` was restored after the mutations and a follow-up diff was clean.

## Area 2 — Class docblock honesty
The class-level docblock in `tests/Architecture/BroadcastChannelTenantContextTest.php` is honest. It explicitly calls the test a "Best-effort static-analysis regression catcher", names the three known limits from round 3 (data-flow through reassignment, late-binding/dynamic dispatch, and truthy-non-bool runtime returns), and points to `Tests\Feature\Broadcasting\BroadcastChannelAuthEndpointTest`, which exists and matches the class name. The four classification rules in the docblock match the implementation: tenant-named channels require an allowlisted helper on the first parameter; non-tenant channels require `@cross-tenant-by-design` with justification; dynamic names fail; deferrals are honored. The round-3 NICE-TO-HAVE paragraph accurately describes the new outer-closure return scan.

## Area 3 — Outer-scope Return_ scan correctness
The production scan correctly classifies the five current channel closures, and the architecture suite's nested-scope fixture passes with zero violations despite nested anonymous-class and nested-closure `return true;` noise. By inspection, `collectOuterClosureReturns()` recurses through the expected statement-level control-flow constructs (`If_`, `Switch_`, `TryCatch`, `Foreach_`, `For_`, `While_`, `Do_`, `Block`) and deliberately does not recurse into expression-contained nested scopes. I did not find a nested-scope construct that escapes the skip behavior and affects the verdict. `match` is not a blocker here because PHP `match` arms are expressions; a `return match (...) { ... }` remains a single outer `Return_` whose expression is not an allowlisted helper call unless the full expression is the helper call.

## Area 4 — Behavioral test honesty (anti-vacuous-pass)
h1: The authorizes tests assert HTTP 200. `TestBroadcaster::validAuthenticationResponse()` returns a concrete JSON response body, `{"auth":"ok"}`, so authorized success is not an empty null-driver response at the driver layer. The tests currently assert only status; adding exact body assertions would tighten the success-path pin, but the denial tests already prevent a null-driver-style vacuous suite.

h2: Requested mutation of `User::canAccessCompanyChannel()` to `return true;` produced 8 failures: all denial tests for imports, partners, POS terminal, and POS kitchen returned 200 instead of 403. The two product denials stayed green because the product channel uses `canAccessChannel()`, not `canAccessCompanyChannel()`. I then separately mutated `User::canAccessChannel()` to `return true;`; the two product denial tests failed with 200 instead of 403. This proves all ten denial tests are sensitive to their actual production helper gates. `User.php` was restored and the endpoint suite passed afterward.

h3: Cross-company-same-tenant setup is correct. `seedTenantAndCompanies()` creates `companyA1` and `companyA2` in tenant A, gives user A membership only in `companyA1`, and each cross-company test sends `companyA2->id`, not `companyA1->id`.

h4: Each denial test asserts that the response body does not contain the foreign tenant/company UUIDs. Laravel's `Broadcaster::verifyUserCanAccessChannel()` throws a bare `AccessDeniedHttpException` on `false` or no match, so the test path does not construct an error message from the channel name. `phpunit.xml` does not explicitly set `APP_DEBUG`; this checkout's `.env` has `APP_DEBUG=true`, meaning the current test environment is not relying on production-style debug-off opacity. The observed baseline denial tests pass the no-ID-reflection guard.

h5: Laravel binds channel wildcards through `extractAuthParameters()` after a fully anchored `channelNameMatchesPattern()` check. The product callback declares four parameters and the test sends all four wildcard segments, including a fake UUID product ID. By source inspection, a malformed product channel missing the product segment would not match the anchored pattern and would throw `AccessDeniedHttpException` as a 403 rather than executing the closure with bad arity.

## Area 5 — TestBroadcaster correctness
`TestBroadcaster::auth()` normalizes the `private-` prefix and calls Laravel's `verifyUserCanAccessChannel()`, which matches the registered pattern, extracts wildcard parameters, invokes the production channel closure with the authenticated user, throws `AccessDeniedHttpException` on `false`, and returns `validAuthenticationResponse()` on truthy success. `validAuthenticationResponse()` returns a raw `Illuminate\Http\Response` with JSON body `{"auth":"ok"}` and status 200; the endpoint suite confirms that authorized requests return 200 through that shape. `broadcast()` is a no-op and irrelevant because these tests exercise only auth. The re-include in `setUp()` is valid for the new driver instance; the combined run with `BroadcastChannelAuthEndpointTest.php` and `ProductChannelAuthorizationTest.php` passed: `OK (19 tests, 34 assertions)`.

## Area 6 — Inventory state integrity
`cd apps/api && php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` passed with `verified 1655 event(s) across 339 callsite(s); 0 problem(s).` Spot-checking `api.broadcast-channels.001` through `.013` showed `status: under_review`, `owner: claude`, and `fix_commit: dc67bcdb` across the callsite rows.

## Area 7 — Cluster shape sign-off
The complete coverage shape is sound: five production channels carry `@cross-tenant-anchored` PHPDoc and helper-call gates; eight broadcast event classes carry class-level `@cross-tenant-anchored` docblocks and construct `PrivateChannel` names from property-sourced tenant/company values; `BroadcastChannelTenantContextTest` catches obvious route-closure bypasses while documenting its three static-analysis limits; `BroadcastChannelAuthEndpointTest` is the behavioral ground-truth check for `/broadcasting/auth`; and the round-1 dead-code removal of `App.Models.User.{id}` remains accepted as honest.

## BLOCKERs (if any)
None.

## NICE-TO-HAVEs (if any)
1. Tighten authorized-success assertions to check the exact `{"auth":"ok"}` response body, so the success tests explicitly distinguish `TestBroadcaster` success from any future 200-empty-body auth-driver regression.
2. Consider setting `APP_DEBUG=false` explicitly in `phpunit.xml` if the desired information-leak guard is meant to model production error rendering. The current tests still pass with `.env` debug enabled, which is stricter for accidental stack/message leakage but less explicit.

## Sign-off
This approves the round-4 pivot: static closure analysis remains a bounded lint with documented limits, and the behavioral `/broadcasting/auth` suite now carries the cluster invariant. Deferred risk is intentionally limited to the documented unbounded static-analysis attack surface; future confidence should come from endpoint-level behavioral tests rather than trying to make the PhpParser lint exhaustive.
