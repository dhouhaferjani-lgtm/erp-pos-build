# api.broadcast-channels cluster — Codex round-3 review

Reviewed commit: 5efe9ead71f9b553e32a6a6c97782399fc4f09a6
Reviewer: codex
Date: 2026-05-08
Verdict: BLOCK-WITH-CHANGES-REQUIRED

## Verdict rationale

Round 3 closes the two round-2 blocker variants at the structural level, and the restored baseline architecture suite is green. However, adversarial probing found a new receiver-identity bypass: the analyzer accepts a direct `return $user->canAccessCompanyChannel(...)` expression after the authenticated `$user` parameter has been reassigned to a different object whose allowlisted method returns a truthy generator. Laravel's broadcaster treats any non-false truthy callback result as authorization success, so this is not just a static false positive. This is a new data-flow / late-binding class, not another control-flow or docblock variant, and it should pivot to behavioral coverage against `/broadcasting/auth` as directed by the round-3 escalation rule.

## Verdict class (only if BLOCK)

- BLOCK-NOVEL: a NEW class of bypass not surfaced before (receiver rebinding plus truthy generator return; related to late-binding / dynamic auth-result behavior that the static analyzer does not model).

## Attack matrix results

### c1.1 — Shadowed receiver with magic method returning `true`

- Mutation source (literal PHP):

```php
Broadcast::channel('tenant.{tenantId}.company.{companyId}.codex-shadow-magic', function (User $user, string $tenantId, string $companyId) {
    $user = new class {
        public function __call(string $name, array $arguments): bool
        {
            return true;
        }
    };

    return $user->canAccessCompanyChannel($tenantId, $companyId);
});
```

- Arch-test result: `vendor/bin/phpunit tests/Architecture/BroadcastChannelTenantContextTest.php` failed under `tenant_named_without_helper` for `routes/channels.php:107 (tenant.{tenantId}.company.{companyId}.codex-shadow-magic)`.
- Whether result matches expected: Yes for the test verdict, but the reason is incidental. The recursive `Return_` scan descends into the anonymous class method and rejects its inner `return true;`; it is not actually proving that the outer helper call is on the authenticated user.
- Notes on deviations: This exposed that nested class/function returns are part of the current scan. That behavior caught this specific mutation but is not a reliable receiver-identity proof.

### c1.2 / d — Shadowed receiver with allowlisted method returning a truthy generator

- Mutation source (literal PHP):

```php
Broadcast::channel('tenant.{tenantId}.company.{companyId}.codex-shadow-generator', function (User $user, string $tenantId, string $companyId) {
    $user = new class {
        public function canAccessCompanyChannel(string $tenantId, string $companyId): \Generator
        {
            yield true;
        }
    };

    return $user->canAccessCompanyChannel($tenantId, $companyId);
});
```

- Arch-test result: `vendor/bin/phpunit tests/Architecture/BroadcastChannelTenantContextTest.php` passed: `OK (2 tests, 14 assertions)`.
- Whether result matches expected: No. This should fail because the returned method call is not on the authenticated `User` parameter anymore.
- Notes on deviations: This is the BLOCK-NOVEL finding. The outer return expression is exactly a method call named `canAccessCompanyChannel` on a variable named `$user`, so `isAllowedHelperCallOnVariable()` accepts it. The reassigned object's method has no `Return_` statement for the recursive scanner to reject; it yields, so the method returns a `Generator` object. Laravel's `Broadcaster::verifyUserCanAccessChannel()` rejects only `$result === false` and accepts any truthy `$result`, so a generator object authorizes the private channel. Per the escalation rule, I stopped further A/B/C/D mutation attacks after this novel bypass class was confirmed.

### Not attempted after novel bypass confirmation

Attack classes A (a1-a8), B (b1-b7), c2-c5, and additional D variants were not run after c1.2 because the prompt's escalation rule says to flag `BLOCK-NOVEL` and stop attacking once a magic-method / late-binding / analyzer-unmodeled auth-result route is found.

## Production scan integrity

`git diff 9ed0f82a..HEAD -- apps/api/routes/channels.php apps/api/app/Modules/*/Infrastructure/Broadcasting` produced no output, confirming production broadcast routes/classes are unchanged between round 2 and round 3.

After restoring the temporary route mutation with `apply_patch` (sandbox blocked `git checkout -- apps/api/routes/channels.php` because `.git/index.lock` could not be created), `git diff -- apps/api/routes/channels.php` produced no output.

Baseline architecture reruns:

```text
vendor/bin/phpunit tests/Architecture/BroadcastChannelTenantContextTest.php
OK (2 tests, 14 assertions)

vendor/bin/phpunit tests/Architecture
OK (7 tests, 32 assertions)
```

## Inventory state integrity

```text
php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml
verified 1655 event(s) across 339 callsite(s); 0 problem(s).
```

## BLOCKERs (if any)

1. Receiver rebinding plus truthy generator return bypasses the static broadcast-channel gate. A tenant-named channel can reassign the first parameter variable, return an allowlisted method call on the shadowed object, and pass the architecture test while Laravel treats the truthy generator result as authorized. This is a new data-flow / auth-result class and should be closed with behavioral `/broadcasting/auth` coverage rather than another narrow static patch.

## NICE-TO-HAVEs (if any)

1. Consider excluding nested function/class/anonymous-class method bodies from `closureGatesViaAllowedAuthHelper()`'s return scan. The current recursion incidentally rejected c1.1 because it saw an inner `return true;`, but that is not the invariant being tested and could cause noisy future failures.

## Sign-off

Do not flip the `api.broadcast-channels` cluster to fixed at `5efe9ead`. Round 3 is stronger against the prior control-flow and docblock variants, but the static checker still cannot prove the allowlisted helper runs on the authenticated user once local rebinding and truthy non-bool callback results enter the closure. The next fix should add behavioral coverage at the broadcast auth endpoint and then decide whether the architecture test remains a lint guard or is narrowed to patterns it can prove soundly.
