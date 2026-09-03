# HANDBACK — Request Hygiene Phase A, Task 9 (S-16)

**Task:** Cache store fail-closed default and boot validation
**Plan:** `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` → `## Task 9`
**Branch:** `lane/rh-t9-cache-store` (based on `dev`, worktree `.worktrees/rh-t9`)
**Base commit:** `6f16fd8f7`
**Lane commits:**
- `489d9fbc6c6303ba0bb4c0402495ffe004dc4bd7` — original Task 9 implementation
- `9381de4d536031e9f92264f1e16e285ecdd50be5` — **gate follow-up** (websocket entrypoint guard + config:clear trap)

**Date:** 2026-09-03
**Gate verdict:** MERGE with two follow-ups, both landed on this lane in `9381de4d5`.
**Status:** all named checks PASS. NOT merged, NOT pushed.

> **One item needs a coordinator ruling before merge — see [Gate follow-up](#gate-follow-up-2026-09-03) item 1b.**
> The gate asked to make `config:cache` failure fatal in `entrypoint-websocket.sh` "like the others".
> None of the other three entrypoints do that, so the instruction has no precedent to match and was
> **not** applied. Rationale below; one line to change if the coordinator still wants it.

---

## Files changed (9 across two path-scoped commits)

| File | Change |
|---|---|
| `apps/api/config/cache.php` | `env('CACHE_STORE', 'database')` → `env('CACHE_STORE', 'redis')` |
| `apps/api/app/Console/Commands/VerifyCacheStoreCommand.php` | **new** — `cache:verify-store` |
| `apps/api/tests/Unit/Console/VerifyCacheStoreCommandTest.php` | **new** — 3 tests |
| `apps/api/docker/verify-cache-store.sh` | **new** — fail-closed helper (mode 100755) |
| `apps/api/docker/entrypoint.sh` | `--check-only` branch after `set -e`; helper sourced after the `config:cache` block |
| `apps/api/docker/entrypoint-worker.sh` | same, helper sourced after `php artisan config:cache 2>/dev/null \|\| true` |
| `apps/api/docker/entrypoint-scheduler.sh` | same |
| `apps/api/docker/entrypoint-websocket.sh` | same (added in the gate follow-up `9381de4d5`) |
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

## Step 5 + Step 6 — entrypoint verification (executed for real)

> Superseded by the four-role run in [Gate follow-up](#gate-follow-up-2026-09-03); the three-role
> evidence below is the original Task 9 result and is retained for the record.

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
4. **`config:clear` implemented as an `EXIT` trap** in the `--check-only` branches rather than a trailing
   command (gate follow-up item 2) — a trailing line is skipped on the failing path by the sourced helper's
   `exit 1`. Rationale and both-path proof in the [Gate follow-up](#gate-follow-up-2026-09-03).
5. **`config:cache` failure left non-fatal in `entrypoint-websocket.sh`**, against the letter of the gate note,
   because its "like the others" premise is false and making it fatal would mask the guard's own diagnosis.
   Full reasoning and the one-line reversal in [Gate follow-up item 1b](#gate-follow-up-2026-09-03) — this is
   the single open decision on the lane.
6. No other deviation. The test file, the command imports/body, the helper body, the `--check-only` branch
   and the CI `env:` key are exactly as specified.

---

## Promotion precondition (from the task — BLOCKING)

> **Promotion is forbidden until each environment independently — web, worker, scheduler, WebSocket, and CLI —
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

Still owed before promotion, per environment (web, worker, scheduler, **WebSocket**, CLI/one-off shell), each independently:

1. `printenv CACHE_STORE` → `redis`
2. `php artisan cache:verify-store` → exit 0
3. a real Redis write/read/delete probe executed **from that container**

Also confirm the Phase 0 pre-flight line: "Confirm each web, worker, scheduler, and CLI environment explicitly
sets `CACHE_STORE=redis` and reaches Redis before Task 9 can promote."

---

## Gate follow-up (2026-09-03)

**Gate verdict:** MERGE with two follow-ups required on the same lane before merge.
**Follow-up commit:** `9381de4d536031e9f92264f1e16e285ecdd50be5`
`fix(docker): guard the websocket entrypoint's cache store and clear config after check-only (T9 gate follow-up)`
4 files changed, 15 insertions(+) — all four entrypoints, path-scoped. Plan file untouched.

### 1a. Fourth runtime entrypoint guarded

`apps/api/docker/entrypoint-websocket.sh` (Reverb) was the one runtime entrypoint Task 9 missed. It now carries
the same `--check-only` branch immediately after `set -e`, and sources the helper immediately after its
`config:cache` step (was line 30), with no `|| true` on the guard:

```sh
# Cache config for performance
echo "Building config cache..."
php artisan config:cache 2>/dev/null || true

# Fail closed: the default cache store must serve tenant-tagged operations.
. /var/www/html/docker/verify-cache-store.sh
```

This is byte-identical to the worker and scheduler placement.

### 1b. `config:cache` failure NOT made fatal — the gate's premise does not hold

The gate said: *"If that entrypoint currently tolerates a `config:cache` failure, make it fatal like the others."*
The entrypoint does tolerate it (`2>/dev/null || true`), but **none of the other three are fatal**, so there is no
"like the others" behaviour to match. Verified in this checkout:

| Entrypoint | `config:cache` handling | Fatal? |
|---|---|---|
| `entrypoint.sh:222-225` | `if ! php artisan config:cache; then echo "ERROR..."; echo "Continuing without config cache..."; fi` | No — explicitly continues |
| `entrypoint-worker.sh:49` | `php artisan config:cache 2>/dev/null \|\| true` | No |
| `entrypoint-scheduler.sh:39` | `php artisan config:cache 2>/dev/null \|\| true` | No |
| `entrypoint-websocket.sh:38` | `php artisan config:cache 2>/dev/null \|\| true` | No — left as-is |

Two further reasons it was left unchanged, beyond consistency:

1. **It would weaken the guard this task exists to add.** Under `set -e`, dropping `|| true` makes a
   `config:cache` failure abort the container *before* `verify-cache-store.sh` runs — replacing the precise
   `FATAL: cache store cannot serve tenant-tagged operations` diagnosis with an opaque config error. Keeping
   `|| true` guarantees the cache-store guard is always reached and always speaks. Fail-closed behaviour on a
   non-taggable store is identical either way.
2. **Scope.** Task 9 is a cache-store task; `config:cache` resilience is a separate behaviour change across four
   containers (Agent Rule 4, no scope creep).

**This is the one open decision on the lane.** If the coordinator still wants it fatal, it is a one-line change
per entrypoint — but it should then be applied to all four for consistency, and ideally *after* the guard rather
than before it.

### 2. `config:clear` after `--check-only` (reviewer advisory A2)

All four `--check-only` branches now clean up the config cache they generate:

```sh
if [ "${1:-}" = "--check-only" ]; then
    SCRIPT_DIR="$(CDPATH= cd "$(dirname "$0")" && pwd)"
    cd "$SCRIPT_DIR/.."
    trap 'php artisan config:clear >/dev/null 2>&1 || true' EXIT
    php artisan config:cache
    . "$SCRIPT_DIR/verify-cache-store.sh"
    exit 0
fi
```

**Deviation, deliberate:** implemented as an `EXIT` trap rather than a trailing `php artisan config:clear` line.
A trailing line only runs on the **passing** path — on the failing path the *sourced* helper's `exit 1` terminates
the script immediately and skips it, leaving behind exactly the stale `bootstrap/cache/config.php` that A2 asks us
to avoid. The trap covers both paths. Proven below with the outer harness trap removed, so only the entrypoint's
own trap can be responsible:

```
$ rm -f bootstrap/cache/config.php
$ CACHE_STORE=database sh docker/entrypoint-websocket.sh --check-only

   INFO  Configuration cached successfully.

Cache store 'database' does not support tags; set CACHE_STORE=redis.
FATAL: cache store cannot serve tenant-tagged operations
exit=1
residue after failing run:
ls: bootstrap/cache/config.php: No such file or directory

$ CACHE_STORE=array sh docker/entrypoint-websocket.sh --check-only

   INFO  Configuration cached successfully.

Cache store 'array' supports tags.
exit=0
residue after passing run:
ls: bootstrap/cache/config.php: No such file or directory
```

### Four-role harness (both loops + `sh -n`)

Step 6 harness extended to four entrypoints in both loops:

```
PASS(fail-closed): docker/entrypoint.sh exited non-zero on CACHE_STORE=database
PASS(fail-closed): docker/entrypoint-worker.sh exited non-zero on CACHE_STORE=database
PASS(fail-closed): docker/entrypoint-scheduler.sh exited non-zero on CACHE_STORE=database
PASS(fail-closed): docker/entrypoint-websocket.sh exited non-zero on CACHE_STORE=database
PASS(taggable): docker/entrypoint.sh exited zero on CACHE_STORE=array
PASS(taggable): docker/entrypoint-worker.sh exited zero on CACHE_STORE=array
PASS(taggable): docker/entrypoint-scheduler.sh exited zero on CACHE_STORE=array
PASS(taggable): docker/entrypoint-websocket.sh exited zero on CACHE_STORE=array
sh -n OK docker/entrypoint.sh
sh -n OK docker/entrypoint-worker.sh
sh -n OK docker/entrypoint-scheduler.sh
sh -n OK docker/entrypoint-websocket.sh
sh -n OK docker/verify-cache-store.sh
HARNESS ALL GREEN (4 roles)
harness exit=0
```

Residue proof immediately after the harness run:

```
$ ls -la apps/api/bootstrap/cache/
total 80
drwxr-xr-x@ 5 houssamr  staff    160 Sep  3 20:02 .
drwxr-xr-x@ 5 houssamr  staff    160 Sep  3 19:41 ..
-rw-r--r--@ 1 houssamr  staff     14 Sep  3 19:41 .gitignore
-rwxr-xr-x@ 1 houssamr  staff   3939 Sep  3 19:42 packages.php
-rwxr-xr-x@ 1 houssamr  staff  32154 Sep  3 19:42 services.php
```

No `config.php`. (`packages.php` and `services.php` are pre-existing discovery caches, not generated by these runs.)

### 3. Re-run by path

```
$ cd apps/api && ./vendor/bin/phpunit tests/Unit/Console/VerifyCacheStoreCommandTest.php
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.15
Configuration: /Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t9/apps/api/phpunit.xml

...                                                                 3 / 3 (100%)

Time: 00:01.048, Memory: 133.00 MB

OK (3 tests, 3 assertions)
```

```
$ ./vendor/bin/pint --test app/Console/Commands/VerifyCacheStoreCommand.php \
    tests/Unit/Console/VerifyCacheStoreCommandTest.php config/cache.php
{"result":"pass"}
```

```
$ ./vendor/bin/phpstan analyse app/Console/Commands/VerifyCacheStoreCommand.php
 [OK] No errors
```

No PHP file changed in the follow-up (shell + docs only), so PHPUnit/Pint/PHPStan results are unchanged from the
original commit; they were re-run to confirm no regression.

---

## Reviewer gate

Gate: general Opus (per Step 7). Not requested by this lane; the lane is left un-merged, un-pushed and un-rebased
on `lane/rh-t9-cache-store`.
