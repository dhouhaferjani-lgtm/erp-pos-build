# HANDBACK — Request Hygiene Phase A, Task 9 (S-16)

**Task:** Cache store fail-closed default and boot validation
**Plan:** `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` → `## Task 9`
**Branch:** `lane/rh-t9-cache-store` (based on `dev`, worktree `.worktrees/rh-t9`)
**Base commit:** `6f16fd8f7`
**Lane commit:** `489d9fbc6c6303ba0bb4c0402495ffe004dc4bd7`
**Date:** 2026-09-03
**Status:** all named checks PASS. NOT merged, NOT pushed.

---

## Files changed (8, path-scoped commit)

| File | Change |
|---|---|
| `apps/api/config/cache.php` | `env('CACHE_STORE', 'database')` → `env('CACHE_STORE', 'redis')` |
| `apps/api/app/Console/Commands/VerifyCacheStoreCommand.php` | **new** — `cache:verify-store` |
| `apps/api/tests/Unit/Console/VerifyCacheStoreCommandTest.php` | **new** — 3 tests |
| `apps/api/docker/verify-cache-store.sh` | **new** — fail-closed helper (mode 100755) |
| `apps/api/docker/entrypoint.sh` | `--check-only` branch after `set -e`; helper sourced after the `config:cache` block |
| `apps/api/docker/entrypoint-worker.sh` | same, helper sourced after `php artisan config:cache 2>/dev/null \|\| true` |
| `apps/api/docker/entrypoint-scheduler.sh` | same |
| `.github/workflows/ci.yml` | `types-drift` job gains job-level `env: CACHE_STORE: array` before `defaults:` |

Environment prep (NOT committed, `.gitignore`d): `vendor/` copied from the main checkout + `composer dump-autoload`
(19581 classes), `.env` copied from the main checkout. `git status` before commit showed only the 8 task files.

---

## Step 1 + Step 3 (red) — failing tests first

```
cd apps/api && ./vendor/bin/phpunit tests/Unit/Console/VerifyCacheStoreCommandTest.php
```

Output tail (test file present, command and config change absent):

```
1) Tests\Unit\Console\VerifyCacheStoreCommandTest::test_database_store_fails_tag_capability_check
Symfony\Component\Console\Exception\CommandNotFoundException: The command "cache:verify-store" does not exist.
...
2) Tests\Unit\Console\VerifyCacheStoreCommandTest::test_array_store_passes_tag_capability_check
Symfony\Component\Console\Exception\CommandNotFoundException: The command "cache:verify-store" does not exist.
...
There was 1 failure:

1) Tests\Unit\Console\VerifyCacheStoreCommandTest::test_cache_config_defaults_to_redis_when_cache_store_is_unset
Failed asserting that two strings are identical.
--- Expected
+++ Actual
@@ @@
-'redis'
+'database'

ERRORS!
Tests: 3, Assertions: 1, Errors: 2, Failures: 1.
```

Red for exactly the stated reasons: the command does not exist yet (2 errors), and the isolated
`require base_path('config/cache.php')` — run with `CACHE_STORE` removed from `putenv`/`$_ENV`/`$_SERVER`
and restored in `finally` — still resolved `database` (1 failure). This isolated require is the only way to
prove the fallback, because `phpunit.xml:43` pins `CACHE_STORE=array` for the booted app.

---

## Step 2 + Step 3 (green) — command and config default

`VerifyCacheStoreCommand` was written with the plan's exact imports and body: `private readonly CacheManager $cache`
constructor injection (no `app()`), `declare(strict_types=1)`, no `mixed`, `method_exists($store, 'tags')` capability
probe, `self::FAILURE` / `self::SUCCESS`. `config/cache.php:18` fallback flipped to `redis`.

```
cd apps/api && ./vendor/bin/phpunit tests/Unit/Console/VerifyCacheStoreCommandTest.php
```

```
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.15
Configuration: /Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t9/apps/api/phpunit.xml

...                                                                 3 / 3 (100%)

Time: 00:01.258, Memory: 133.00 MB

OK (3 tests, 3 assertions)
```

Sibling Console tests (regression check, by path — the full suite was never run, per the laptop rule):

```
cd apps/api && ./vendor/bin/phpunit tests/Unit/Console/
```

```
..............................                                    30 / 30 (100%)

Time: 00:05.631, Memory: 161.00 MB

OK (30 tests, 131 assertions)
```

---

## Step 4 — infrastructure-free CI job pinned

`.github/workflows/ci.yml`, `types-drift` job (from line 2618) now reads:

```yaml
  types-drift:
    name: Generated Types Drift Guard
    runs-on: ubuntu-latest
    # Intentionally lean: no .env, no APP_KEY, no Postgres, no Redis.
    # ...
    # CACHE_STORE is pinned so this infrastructure-free job never inherits the
    # fail-closed `redis` fallback in config/cache.php (Task 9 / S-16).
    env:
      CACHE_STORE: array
    defaults:
      run:
        working-directory: apps/api
```

`route-manifest-drift` — the other intentionally lean job — was inspected (ci.yml:2594-2617) and runs
`bash scripts/factory/check-manifest-drift.sh` only, with no `php artisan` invocation, so it needs no pin.
That matches the plan naming only `types-drift`.

---

## Step 5 + Step 6 — entrypoint verification (executed for real, all three roles)

The entrypoints were executed locally via their real `--check-only` branch (no Docker image needed; the
branch derives its directory from `$0`, so it works from the source checkout). Harness exactly as written
in the plan, with a `PASS:` echo added per iteration for evidence:

```
cd apps/api && sh -c '<Step 6 harness>'
```

```
PASS(fail-closed): docker/entrypoint.sh exited non-zero on CACHE_STORE=database
PASS(fail-closed): docker/entrypoint-worker.sh exited non-zero on CACHE_STORE=database
PASS(fail-closed): docker/entrypoint-scheduler.sh exited non-zero on CACHE_STORE=database
PASS(taggable): docker/entrypoint.sh exited zero on CACHE_STORE=array
PASS(taggable): docker/entrypoint-worker.sh exited zero on CACHE_STORE=array
PASS(taggable): docker/entrypoint-scheduler.sh exited zero on CACHE_STORE=array
sh -n OK docker/entrypoint.sh
sh -n OK docker/entrypoint-worker.sh
sh -n OK docker/entrypoint-scheduler.sh
sh -n OK docker/verify-cache-store.sh
HARNESS ALL GREEN
harness exit=0
```

Raw single invocations, showing the guard is what fails (not an earlier step) and the trap cleaned the config cache:

```
$ CACHE_STORE=database sh docker/entrypoint.sh --check-only

   INFO  Configuration cached successfully.

Cache store 'database' does not support tags; set CACHE_STORE=redis.
FATAL: cache store cannot serve tenant-tagged operations
exit=1

$ CACHE_STORE=array sh docker/entrypoint-worker.sh --check-only

   INFO  Configuration cached successfully.

Cache store 'array' supports tags.
exit=0

$ php artisan config:clear && ls bootstrap/cache/config.php
ls: bootstrap/cache/config.php: No such file or directory
```

Both the `--check-only` branch and the normal-boot path source the same helper. The normal boot line is
`. /var/www/html/docker/verify-cache-store.sh`, placed immediately after each entrypoint's existing
`config:cache` command/block and before any runtime process starts (`exec ... horizon`,
`exec ... schedule:work`, supervisor). It is **not** guarded by `|| true` anywhere — `exit 1` is the
permanent fail-closed boot behavior, and because the helper is *sourced*, its `exit 1` terminates the
entrypoint itself.

---

## Step 7 — PHPStan

```
cd apps/api && ./vendor/bin/phpstan analyse app/Console/Commands/VerifyCacheStoreCommand.php
```

```
Note: Using configuration file .../apps/api/phpstan.neon.
 1/1 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%

 [OK] No errors
```

Pint (style, changed PHP files):

```
cd apps/api && ./vendor/bin/pint --test app/Console/Commands/VerifyCacheStoreCommand.php \
  tests/Unit/Console/VerifyCacheStoreCommandTest.php config/cache.php
{"result":"pass"}
```

---

## Deviations

1. **Comment lines added** above the CI `env:` block and above each entrypoint's sourced helper line
   (`# Fail closed: the default cache store must serve tenant-tagged operations.`). The plan's YAML/shell
   snippets are otherwise byte-identical; the comments explain a hard-fail to a future reader.
2. **`PASS(...)` echoes added inside the Step 6 harness loops.** The plan's harness is silent on success,
   which would leave no evidence tail. Control flow, conditions and exit codes are unchanged.
3. **Blank lines before `return` in the command body** (Pint / PSR-12 style, `--test` passes). No semantic change.
4. No other deviation. The test file, the command imports/body, the helper body, the `--check-only` branch
   and the CI `env:` key are exactly as specified.

---

## Promotion precondition (from the task — BLOCKING)

> **Promotion is forbidden until each environment independently — web, worker, scheduler, and CLI —
> shows `CACHE_STORE=redis`, boots `cache:verify-store` successfully, and completes a real Redis
> write/read/delete probe from that environment. Evidence from one environment cannot stand in for another.**

The entrypoints now **hard-fail** on a non-taggable store, so any environment that reaches this deploy without a
reachable Redis `CACHE_STORE` will fail to boot rather than silently degrade. `cache:verify-store` proves
**tag capability, not network reachability** — a store configured as `redis` against an unreachable host still
passes the command and then fails at first use. The write/read/delete probe is therefore mandatory and separate.

Repository-side facts confirmed in this checkout (necessary, not sufficient — these are config declarations, not
running-environment evidence):

- `apps/api/.env.example:102` → `CACHE_STORE=redis`
- `apps/api/.env.production.example:70` → `CACHE_STORE=redis`
- `docker-compose.staging.yml:33` → `CACHE_STORE: redis`
- `docker-compose.dokploy.yml:30` → `CACHE_STORE: redis`
- `docker-compose.sidebar-demo.yml:57` → `CACHE_STORE: redis`

Still owed before promotion, per environment (web, worker, scheduler, CLI/one-off shell), each independently:

1. `printenv CACHE_STORE` → `redis`
2. `php artisan cache:verify-store` → exit 0
3. a real Redis write/read/delete probe executed **from that container**

Also confirm the Phase 0 pre-flight line: "Confirm each web, worker, scheduler, and CLI environment explicitly
sets `CACHE_STORE=redis` and reaches Redis before Task 9 can promote."

---

## Reviewer gate

Gate: general Opus (per Step 7). Not requested by this lane; the lane is left un-merged, un-pushed and un-rebased
on `lane/rh-t9-cache-store`.
