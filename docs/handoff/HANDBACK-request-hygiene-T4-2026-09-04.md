# HANDBACK — Request Hygiene Phase A, Task 4 (bounded audit trail + legacy document `limit` clamp, S-3 / S-33)

- **Plan:** `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` → `## Task 4: Bounded audit trail and legacy document limit (S-3, S-33)` (lines 1185–1594, rev 9)
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t4`
- **Branch:** `lane/rh-t4-audit-bounds`, base commit `b133caf21` (= local `dev` at lane start)
- **Commits:** `817ac93e2` (implementation) → this handback in the follow-up doc commit (a commit cannot contain its own hash)
- **Result:** all six plan steps landed. PHPStan level 8 clean, Pint clean, SQLite **183 passed / 6 skipped / 0 failed**, PG **185 passed / 4 failed — all four failures proven pre-existing at `b133caf21`**. Red-first evidence captured for all 8 audit tests and all 3 legacy-limit tests.

---

## What landed

| # | Change | File | Why (S-ref) |
|---|---|---|---|
| 1 | New `ListAuditEventsRequest` — `date_format:Y-m-d`, paired `from`/`to` + `aggregate_type`/`aggregate_id` via `required_with`, 92-day span ceiling, `per_page` 1..100, `include` in `payload` | `apps/api/app/Modules/Compliance/Presentation/Requests/ListAuditEventsRequest.php` (new) | S-3 |
| 2 | `audit_date_range_max` key added to en + fr; new complete `ar` validation file | `apps/api/lang/{en,fr}/validation.php`, `apps/api/lang/ar/validation.php` (new) | S-3 |
| 3 | `AuditService::paginateEvents()` — single bounded paginator, company predicate on every branch, ascending `occurred_at` for aggregate/range, descending elsewhere, `id` tiebreak for stable traversal. `getEventsInRange`/`countEventsByType` widened `Illuminate\Support\Carbon` → `Carbon\CarbonInterface` | `apps/api/app/Modules/Compliance/Services/AuditService.php` | S-3 |
| 4 | `AuditController::index()` takes `ListAuditEventsRequest` instead of raw `Request`; four-way collection fan-out replaced by one paginated read; `payload`/`metadata` now opt-in via `include=payload`; six-field `meta` block added | `apps/api/app/Modules/Compliance/Presentation/Controllers/AuditController.php` | S-3 |
| 5 | Legacy `?limit=` branch clamped to `max(1, min((int) $limit, 100))`; `meta.per_page` reports the clamp actually applied | `apps/api/app/Modules/Document/Presentation/Controllers/DocumentController.php` | S-33 |
| 6 | 9 new audit tests (payload-omission, malformed/oversized range, four half-pair rejections, default-50 + page-2 traversal, `per_page=101`, Step 4b non-UUID `doc-123` aggregate) + `include=payload` on the 3 pre-existing payload-asserting tests | `apps/api/tests/Feature/Compliance/AuditTrailTest.php`, `apps/api/tests/Feature/Compliance/ComplianceCrossTenantHardeningTest.php` | S-3 |
| 7 | 3 new legacy-limit clamp tests (`0`, `-5`, `5000`) + `seedLegacyLimitDocuments()` fixture | `apps/api/tests/Feature/Document/ListDocumentsTest.php` | S-33 |

---

## Environment

- Worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t4`; `apps/api/vendor`, `apps/api/.env`, `apps/web/node_modules` present. Never `git stash`, never the full PHPUnit suite — every run below is by explicit path.
- **PG deviation (see Deviations §D1):** the lane's reserved `autoerp_test_t4` on `127.0.0.1:5433` was **not reachable** — port 5433 is held by `locaplex-postgres` (`pgvector/pgvector:pg16`), and AutoERP's own `autoerp_postgres` container was not running. A dedicated container was started for this lane, mirroring what the T3 lane did with `autoerp_pg_t3`:

  ```
  docker run -d --name autoerp_pg_t4 --shm-size=1g \
    -e POSTGRES_DB=autoerp_test_t4 -e POSTGRES_USER=autoerp -e POSTGRES_PASSWORD=autoerp_secret \
    -p 127.0.0.1:5454:5432 timescale/timescaledb:latest-pg16
  ```

  PG legs therefore add `DB_HOST=127.0.0.1 DB_PORT=5454`. The container is still running; the orchestrator can `docker rm -f autoerp_pg_t4` when the lane closes.

---

## Step 1 — FormRequest + locales

`ListAuditEventsRequest.php` is the plan's body verbatim, with the plan's two inline rationales kept as comments: `required_with` is deliberately unpaired with `sometimes` (the counterpart must be rejected when its partner is present even though the field itself is absent), and `aggregate_id` is `string|max:100` and never `uuid` because the storage contract is `string(100)` (`database/migrations/tenant/2025_11_30_140000_create_audit_events_table.php:19`) and real domain keys such as `doc-123` must stay valid.

**Locale QA** (Step 6 item 4):

```
$ for L in en fr ar; do grep -n "audit_date_range_max" lang/$L/validation.php; php -l lang/$L/validation.php; done
183:    'audit_date_range_max' => 'The date range may not exceed :max days.'
No syntax errors detected in lang/en/validation.php
183:    'audit_date_range_max' => 'La période ne peut pas dépasser :max jours.'
No syntax errors detected in lang/fr/validation.php
6:    'audit_date_range_max' => 'لا يجوز أن تتجاوز الفترة :max يومًا.'
No syntax errors detected in lang/ar/validation.php
```

All three carry the `:max` placeholder. Runtime substitution verified per locale:

```
$ php artisan tinker --execute='foreach(["en","fr","ar"] as $l){app()->setLocale($l); echo $l.": ".__("validation.audit_date_range_max",["max"=>92]).PHP_EOL;}'
en: The date range may not exceed 92 days.
fr: La période ne peut pas dépasser 92 jours.
ar: لا يجوز أن تتجاوز الفترة 92 يومًا.
```

`lang/ar/validation.php` is a complete, valid PHP file opening with `<?php` + `declare(strict_types=1);` and returning a single-key array (`php -r 'var_export(array_keys(require "lang/ar/validation.php"));'` → `array(0 => 'audit_date_range_max')`).

## Steps 2–3 — service paginator + controller

`paginateEvents()` is the plan's body verbatim. `AuditController::index()` is the plan's body verbatim, with the plan's branch-selection reasoning recorded in the method docblock.

## Steps 4 / 4b / 5 — tests

All test bodies are the plan's verbatim, including Step 4b's `doc-123` positive HTTP regression (red the moment `aggregate_id` is narrowed to `uuid`, green with `string|max:100`).

---

## Step 6 item 2 — red-first evidence

The implementation already existed in the worktree when this lane resumed, so the red was produced by temporarily restoring the base-commit files and re-running, then fully restoring. Both restorations were verified by `shasum` and by `git status` returning to the exact eight-modified/two-untracked T4 state.

### Red A — audit endpoint (base `AuditController.php` restored, `ListAuditEventsRequest.php` removed)

```
$ git show b133caf21:apps/api/.../AuditController.php > apps/api/.../AuditController.php
$ rm apps/api/app/Modules/Compliance/Presentation/Requests/ListAuditEventsRequest.php
$ php artisan test tests/Feature/Compliance/AuditTrailTest.php --filter '/(…8 tests…)/'
```

```
⨯ audit api omits payload by default
⨯ malformed or oversized date range returns 422 not 500
⨯ aggregate type without aggregate id returns validation error
⨯ aggregate id without aggregate type returns validation error
⨯ from without to returns validation error
⨯ to without from returns validation error
⨯ audit api defaults to 50 and page two contains the remaining event
⨯ audit api rejects per page above 100 with validation envelope

FAILED > audit api omits payload…             Failed asserting that an array does not have the key 'payload'.
FAILED > malformed or oversized…              Expected response status code [422] but received 500.
FAILED > aggregate type without…              Expected response status code [422] but received 200.
FAILED > aggregate id without ag…             Expected response status code [422] but received 200.
FAILED > from without to returns…             Expected response status code [422] but received 200.
FAILED > to without from returns…             Expected response status code [422] but received 200.
FAILED > audit api defaults to 5…             Failed asserting that actual size 51 matches expected size 50.
FAILED > audit api rejects per p…             Expected response status code [422] but received 200.

Tests:    8 failed (11 assertions)
```

This is the S-3 claim executed, not asserted: a malformed `from` reached `Carbon::parse()` and produced a **500**; all four half-pairs silently returned **200** by falling through to the broader branches; the unbounded default returned **51 of 51** rows; and `payload` was serialized unconditionally.

### Red B — legacy document `limit` (base `DocumentController.php` restored)

```
$ git show b133caf21:apps/api/.../DocumentController.php > apps/api/.../DocumentController.php
$ php artisan test tests/Feature/Document/ListDocumentsTest.php --filter '/(…3 tests…)/'
```

```
⨯ legacy limit zero clamps to one          Failed asserting that actual size 0   matches expected size 1.
⨯ legacy limit negative five clamps to one Failed asserting that actual size 3   matches expected size 1.
⨯ legacy limit 5000 returns exactly 100…   Failed asserting that actual size 101 matches expected size 100.

Tests:    3 failed (6 assertions)
```

Exactly the S-33 claim: `limit=0` returned an empty set, `limit=-5` returned the **full** set, `limit=5000` materialized **all 101** documents.

### Restoration proof

```
$ git status --porcelain
 M apps/api/app/Modules/Compliance/Presentation/Controllers/AuditController.php
 M apps/api/app/Modules/Compliance/Services/AuditService.php
 M apps/api/app/Modules/Document/Presentation/Controllers/DocumentController.php
 M apps/api/lang/en/validation.php
 M apps/api/lang/fr/validation.php
 M apps/api/tests/Feature/Compliance/AuditTrailTest.php
 M apps/api/tests/Feature/Compliance/ComplianceCrossTenantHardeningTest.php
 M apps/api/tests/Feature/Document/ListDocumentsTest.php
?? apps/api/app/Modules/Compliance/Presentation/Requests/
?? apps/api/lang/ar/validation.php
```

`shasum` of the restored `AuditController.php` = `35acb724ff185c60c5d09f51108c6621ba2a9d03` and of `ListAuditEventsRequest.php` = `b702b26dc2577a5617438b643d6f734195af5d98`, identical to the pre-revert backups.

---

## Step 6 item 1 — green runs, both drivers, by explicit path

Every file from `rg --files tests/Feature/Compliance | sort` (23 files) plus `tests/Feature/Document/ListDocumentsTest.php` was run explicitly. No `--testsuite`, no bare `php artisan test`.

### SQLite (`php artisan test <paths>`)

| Batch | Files | Result |
|---|---|---|
| A | `AuditTrailTest`, `ComplianceCrossTenantHardeningTest`, `ListDocumentsTest` | **49 passed** (182 assertions), 38.84s |
| B | `BlindCashCountDefaultMigrationTest`, `DeliveryNoteHashChainTest`, `DetectFraudPatternsCommandTest`, `DetectFraudPatternsDriftDbPerTenantTest`, `DocumentHashChainWorkOrderMigrationTest`, `DomainEventSubscriberTest`, `FacturXWorkOrderInvoiceTest`, `FiscalBackfillTenantScopeTest`, `FiscalHardeningE2ETest`, `FraudAlertStructuredDescriptionTest` | **66 passed, 6 skipped** (252 assertions), 31.25s |
| C | `FraudAlertTenantIsolationTest`, `FraudSettingsControllerCashControlsTest`, `FraudSettingsControllerContractTest`, `InstrumentAuditTrailTest`, `Nf525CanonicalZGrandTotalPeriodTotalsTest`, `Nf525ExportSnapshotTest`, `Nf525JetExportTest`, `ReceiptReportingRouteGateTest`, `UninvoicedDNReportTest`, `UninvoicedDeliveryNoteScalingTest`, `VerifyFiscalChainGenesisDocumentTest` | **68 passed** (332 assertions), 35.79s |

**SQLite total: 183 passed, 6 skipped, 0 failed.**

Batch A detail — every new test green, and the three plan-named branch-selection guards (`can query audit events by aggregate`, `can query audit events by date range`, `audit events aggregate branch scopes by company`) still green, confirming the controller picks the same branches after validation:

```
✓ can query audit events by aggregate                                  ✓ audit api aggregate branch accepts non uuid string aggregate id
✓ can query audit events by date range                                 ✓ audit api can filter by event type
✓ audit api returns events                                             ✓ audit events aggregate branch scopes by company
✓ audit api omits payload by default                                   ✓ audit events legacy route resolves company from context not header
✓ malformed or oversized date range returns 422 not 500                ✓ legacy limit zero clamps to one
✓ aggregate type without aggregate id returns validation error         ✓ legacy limit negative five clamps to one
✓ aggregate id without aggregate type returns validation error         ✓ legacy limit 5000 returns exactly 100 of 101 documents
✓ from without to returns validation error
✓ to without from returns validation error
✓ audit api defaults to 50 and page two contains the remaining event
✓ audit api rejects per page above 100 with validation envelope

Tests:    49 passed (182 assertions)
```

### PostgreSQL (`DB_HOST=127.0.0.1 DB_PORT=5454 DB_DATABASE=autoerp_test_t4 DB_CENTRAL_DATABASE=autoerp_test_t4 php artisan test -c phpunit-pgsql.xml <paths>`)

| Batch | Result |
|---|---|
| A (same 3 files) | **49 passed** (182 assertions), 140.30s |
| B (same 10 files) | **69 passed, 3 failed** (255 assertions), 59.27s |
| C (same 11 files) | **67 passed, 1 failed** (326 assertions), 71.82s |

**PG total: 185 passed, 4 failed — all four proven pre-existing.**

Per-driver totals reconcile: SQLite 183 passed + 6 skipped = 189; PG 185 passed + 4 failed = 189.

#### The 4 PG failures are pre-existing at `b133caf21`

All four share one cause, and none is in a file this lane touched:

```
SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation "sqlite_master" does not exist
  tests/Traits/ProvisionsTenantDatabases.php:71
```

`ProvisionsTenantDatabases` issues a SQLite-only `select sql from sqlite_master …` when provisioning tenant databases, so any test using that trait fails under `phpunit-pgsql.xml`. Affected:

- `DetectFraudPatternsDriftDbPerTenantTest` — 3 failures (lines 86, 107, …)
- `VerifyFiscalChainGenesisDocumentTest::test_a_skipped_tenant_blocks_the_pass_line…` — 1 failure (line 288)

**Proof of pre-existence** — the same two files, run from the **main checkout** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api` (at `b133caf21`, `git status --porcelain -- apps/api` shows no code changes, only an untracked session doc), against the **same** PG database, with **zero** T4 changes present:

```
$ cd /Users/houssamr/Projects/syneriva/apps/erp/apps/api
$ DB_HOST=127.0.0.1 DB_PORT=5454 DB_DATABASE=autoerp_test_t4 DB_CENTRAL_DATABASE=autoerp_test_t4 \
    php artisan test -c phpunit-pgsql.xml tests/Feature/Compliance/DetectFraudPatternsDriftDbPerTenantTest.php
  tests/Traits/ProvisionsTenantDatabases.php:71
  Tests:    3 failed (0 assertions)

$ … tests/Feature/Compliance/VerifyFiscalChainGenesisDocumentTest.php
  tests/Traits/ProvisionsTenantDatabases.php:71
  Tests:    1 failed, 14 passed (42 assertions)
```

Identical failure count, identical file:line, identical message. **Not caused by Task 4.** Worth a separate ticket: this trait makes the whole db-per-tenant test family PG-incapable.

---

## Step 6 item 3 — PHPStan and Pint

```
$ ./vendor/bin/phpstan analyse --memory-limit=2G \
    app/Modules/Compliance/Presentation/Requests/ListAuditEventsRequest.php \
    app/Modules/Compliance/Presentation/Controllers/AuditController.php \
    app/Modules/Compliance/Services/AuditService.php \
    app/Modules/Document/Presentation/Controllers/DocumentController.php \
    lang/en/validation.php lang/fr/validation.php lang/ar/validation.php
 7/7 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%
 [OK] No errors
```

```
$ ./vendor/bin/pint --test <the 7 files above + the 3 touched test files>
{"result":"pass"}
```

Two fixes were needed to reach this state — see Deviations §D2 and §D3.

---

## Step 6 item 5 — legacy `limit=` consumers (read-only, no live stack)

```
$ grep -n "limit=" apps/web/src/features/dashboard/Dashboard.tsx apps/web/e2e/campaign/onboarding.campaign.ts
src/features/dashboard/Dashboard.tsx:140:  await api.get<DocumentsResponse>('/documents?limit=5&sort=-created_at')
src/features/dashboard/Dashboard.tsx:149:  await api.get<PaymentsResponse>('/payments?limit=5&sort=-created_at')
e2e/campaign/onboarding.campaign.ts:293:  `${apiRoutes.documents}?limit=100`
e2e/campaign/onboarding.campaign.ts:300:  `${apiRoutes.documents}?limit=100`
e2e/campaign/onboarding.campaign.ts:347:  `${apiRoutes.documents}?limit=100`
e2e/campaign/onboarding.campaign.ts:527:  `${apiRoutes.documents}?limit=100`
e2e/campaign/onboarding.campaign.ts:530:  `${apiRoutes.documents}?limit=100`
```

- **Dashboard** sends `limit=5` — comfortably inside 1..100, clamp is a no-op. (In **this** worktree the documents request is at `Dashboard.tsx:140`, not 152; Task 3's own lane may renumber it. The `/payments` call at :149 does not go through the clamped `DocumentController` branch.)
- **Campaign** sends `limit=100` at **five** sites (the plan names only :293; :300, :347, :527 and :530 are the same request). 100 is exactly the ceiling, so `max(1, min(100, 100)) === 100` — unchanged behavior, no truncation. Worth flagging: these five are now pinned *at* the boundary; if the campaign ever grows past 100 documents it will silently truncate rather than error. Not a Task 4 regression (the clamp equals the pre-existing `page`-branch ceiling), but a real future trap.

**No web file was changed by this lane.** The plan's Dashboard test leg was still run for evidence:

```
$ cd apps/web && pnpm vitest run src/features/dashboard/dashboard.test.tsx \
    src/features/dashboard/__tests__/Dashboard.tenantScope.test.tsx
 ✓ src/features/dashboard/dashboard.test.tsx (10 tests) 405ms
 Test Files  2 passed (2)
      Tests  13 passed (13)
```

`pnpm typecheck` was **not** run — no web file changed, and the API change is purely additive (`meta.per_page` added alongside the existing `meta.total`), so no FE type can narrow-fail on it.

The live-stack legs the plan asks for (drive the onboarding campaign; browser-check Dashboard's request) were **not** run — no live stack tonight, per the lane brief. Both requests are statically inside 1..100, so the clamp cannot change their results, but that is a static argument, not a browser observation. See Not verified below.

---

## Step 6 item 6 — audit hash chain is untouched

Stated explicitly so the orchestrator can decide on `fiscal-pos-reviewer`:

- **`event_hash` is still emitted** on every row: `apps/api/app/Modules/Compliance/Presentation/Controllers/AuditController.php:83`. It sits in the unconditional base array, not the `include=payload` branch, so it is returned on **both** the default and the `include=payload` shape.
- **Hash computation was not touched.** It lives in `apps/api/app/Modules/Compliance/Domain/AuditEvent.php:134` and `:207` (`$this->attributes['event_hash'] = $this->eventHash;`). `AuditEvent.php` is not in this lane's diff at all.
- **No write path changed.** `git diff -U0` on `AuditService.php` produces exactly four hunks — `@@ -15 +15,2 @@` (imports), `@@ -133,2 +134,2 @@ getEventsInRange` (Carbon type widening), `@@ -167,2 +168,2 @@ countEventsByType` (Carbon type widening), `@@ -175,0 +177,47 @@` (the new read-only `paginateEvents`). `AuditService::record()` (line 43) is byte-identical.
- Grepping the entire `app/` diff for added write verbs (`->save(`, `->create(`, `->insert(`, `->update(`, `->delete(`, `->fill(`, `->forceFill(`) returns **nothing**.
- `include=payload` changes **serialization only** — which keys the JSON response carries. It does not touch what is stored, hashed, or chained.

**Recommendation: general Opus gate is sufficient; `fiscal-pos-reviewer` is not required.** The plan's own trigger ("add fiscal-pos-reviewer only if audit-chain behavior changes") is not met.

---

## Deviations from the plan

**D1 — PG host/port.** The lane brief specified `autoerp_test_t4` on `127.0.0.1:5433`. That port is held by `locaplex-postgres` and authentication as `autoerp` fails there; AutoERP's own `autoerp_postgres` container is not running. A dedicated `autoerp_pg_t4` container was started on `127.0.0.1:5454` (`timescale/timescaledb:latest-pg16`, `--shm-size=1g` per the known 64MB `/dev/shm` exhaustion note), matching the sibling T3 lane's `autoerp_pg_t3` pattern. All PG runs add `DB_HOST=127.0.0.1 DB_PORT=5454`. Container left running for the orchestrator; `docker rm -f autoerp_pg_t4` to clean up.

**D2 — `LengthAwarePaginator`: contract → concrete.** The plan's Step 2 snippet imports `Illuminate\Contracts\Pagination\LengthAwarePaginator`. PHPStan level 8 rejects that:

```
app/Modules/Compliance/Presentation/Controllers/AuditController.php:75
  Call to an undefined method Illuminate\Contracts\Pagination\LengthAwarePaginator
  <int, App\Modules\Compliance\Domain\AuditEvent>::getCollection().   🪪 method.notFound
```

The contract interface has `items(): array` but no `getCollection()`. `Eloquent\Builder::paginate()` in fact returns the **concrete** `\Illuminate\Pagination\LengthAwarePaginator` (`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:1112`). The import and return type were changed to the concrete class, which is both truthful about the runtime type and keeps the plan's `$events->getCollection()->map(...)` body verbatim. The alternative (rewriting the controller to `collect($events->items())`) would have widened the deviation into the plan's controller snippet for no benefit. The rationale is recorded in the `paginateEvents()` docblock. **No `@phpstan-ignore`, no baseline entry, no cast.**

**D3 — Pint import ordering.** The previous agent's `use App\Modules\Compliance\Presentation\Requests\ListAuditEventsRequest;` was inserted after `…\Services\AnomalyDetectionService`, which `ordered_imports` rejects. `./vendor/bin/pint` fixed it (the only change was moving that one line). Both files are now `{"result":"pass"}`.

No other deviation. Steps 1, 3, 4, 4b and 5 are the plan's bodies verbatim.

---

## Not verified (stated plainly)

1. **Live-stack legs.** The onboarding campaign was not driven and the Dashboard `limit=5` request was not browser-observed — no live stack this session. Both are statically inside the 1..100 clamp (evidence above), but that is a code read, not a runtime observation.
2. **`pnpm typecheck` / `pnpm lint` on `apps/web`.** Not run: no web file was changed and the API change is additive-only.
3. **The 4 PG failures** are proven pre-existing but are **not fixed** — `tests/Traits/ProvisionsTenantDatabases.php:71` remains SQLite-only. Out of Task 4's scope; recommend a separate ticket.
4. **`AuditService::getEventsForAggregate()` / `getEventsByType()` / `getEventsForCompany()`** are no longer called by `AuditController::index()`. A repo grep (`grep -rn 'getEventsForAggregate\|getEventsByType\|getEventsForCompany' apps/api/app apps/api/tests`) finds **no remaining production caller** — only their own declarations and two direct test calls (`AuditTrailTest.php:184`, `:221`). They were left in place because deleting public service methods is outside Task 4's scope; flagging for the reviewer to decide.

---

## For the reviewer

- **Highest-value diff to read:** `AuditController::index()` — verify the branch-selection claim. `required_with` makes each pair all-or-nothing *before* the controller runs, so `$hasAggregate` / `$hasRange` can never see a half-specified input, and nothing falls through to the broader event-type/company branches. The three pre-existing branch tests staying green is the evidence.
- **Ordering contract:** aggregate and range branches keep **ascending** `occurred_at`; company and event-type branches keep **descending**. `->orderBy('id')` is a tiebreak only — it does not override the primary sort, and it is what makes the 51-row page-1/page-2 traversal test deterministic (the test asserts 51 unique ids across both pages via `assertEqualsCanonicalizing`).
- **Watch item:** `include=payload` is a breaking response-shape change for any consumer that read `payload`/`metadata` from `GET /api/v1/audit/events` without opting in. Three in-repo test call sites were updated. A grep across `apps/web/src`, `apps/pos/src` and `apps/web/e2e` finds **no client that calls the read endpoint at all** — every `audit_events` hit in `apps/pos` is the client-side `queued_audit_events` write outbox (`apps/pos/src/lib/db/repositories/queuedAuditEventRepository.ts`), not this GET. A consumer outside this repo (mobile, platform) would still silently lose the two fields.
- **Second watch item:** the five campaign call sites now sit exactly at the 100 ceiling (see Step 6 item 5).
- **Gate recommendation:** general Opus. `fiscal-pos-reviewer` not required — hash chain untouched, argued with file:line in Step 6 item 6.
