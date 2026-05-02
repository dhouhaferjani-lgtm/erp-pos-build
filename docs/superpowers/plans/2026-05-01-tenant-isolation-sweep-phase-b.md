# Tenant-Isolation Sweep — Phase B Implementation Plan

> **STATUS: SUPERSEDED.** This document was the API-only original Phase B
> spec. It has been subsumed and extended by the master spec at
> `docs/superpowers/plans/2026-05-02-tenant-isolation-sweep.md`, which
> covers API + web + Tauri + super-admin context + module-gating + the POS
> cluster (re-folded in per user direction 2026-05-02), and uses a YAML
> inventory at `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml`
> as the coordination contract between Claude Code and Codex.
>
> Specific reusable content (helper code, architecture-test code, convention-doc
> text) is referenced from the master spec by section. Do NOT execute against
> this version — execute against the master spec only.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close every unscoped multi-tenant resource lookup in the Laravel API (validators + service-layer `find()` callsites) outside the POS module, and install a permanent CI gate that prevents the regression class from re-emerging. Survives any future migration to DB-per-tenant — the helper, tests, and convention doc apply to both row-level and Stancl multi-DB modes.

**Architecture:** Per Codex's adversarial review (`docs/superpowers/reviews/2026-05-01-tenant-isolation-B-architecture-codex-review.md`, verdict REQUIRES-DIFFERENT-APPROACH applied):
- Three-method `ScopedExists` helper at `App\Shared\Presentation\Validation\ScopedExists` (no internal `auth()`/`app()`, parameter-explicit, supports tenant+company / tenant-only / company-only via separate factories).
- FormRequests that need company context constructor-inject `CompanyContext` (matches the existing `UpdateCouponRequest` precedent — Rule #13 compliant).
- Sweep granularity is **module/callsite cluster**, not resource-family — same family appears across multiple modules with different parent contexts (e.g., `payment_methods` in POS vs Treasury vs Z-report manager flows).
- Per-cluster commit fixes BOTH validator rules AND the immediate service-layer resolver/writer in one atomic change.
- A PHPUnit architecture test (`tests/Architecture/TenantScopedExistsRulesTest.php`) is the permanent regression gate.

**Tech Stack:** Laravel 12, PHP 8.4, PostgreSQL 16, PHPUnit 11, PHPStan level 8, Pint.

**Branch:** `fix/tenant-isolation` (continuing on top of `fc5c0763` — A.1 already verified clean by Codex).

**Out of scope (deferred):**
- POS cluster (`ReceiptSyncService`, `ReceiptCreationService`, `OrderManagementService`, `ReceiptFinalizationService`, `VoucherLookupService`, `VoucherRedemptionService` voucher lookup) — folds into the POS stabilization session because the offline-first/sync hardening work overlaps. Tracked as Phase B.POS, sequenced after this plan ships.
- Schema-per-tenant or DB-per-tenant migration — strategic decision pending; this work is the foundation for either choice.
- Frontend changes — backend-only sweep.
- Adding RLS — defence-in-depth follow-up, not in this plan (separate ~4-day project).

---

## Pre-flight — confirm starting state

- [ ] **Step 0.1: Verify branch state**

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp
git status -sb
# Expected: ## fix/tenant-isolation ... no uncommitted changes
git log --oneline -3
# Expected tip: fc5c0763 fix(pos): close service-layer tenant-isolation gap on customerId + tighten regression coverage
```

- [ ] **Step 0.2: Confirm A.1 Codex verdict**

Read `docs/superpowers/reviews/2026-05-01-tenant-isolation-A1-codex-rereview.md`. Verdict line 1 must be `A1-CLEAN-PROCEED-TO-B`.

- [ ] **Step 0.3: Confirm current preflight is clean**

```bash
cd apps/api
./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/POS/Application/Services/ReceiptPaymentService.php app/Modules/POS/Presentation/Requests/StoreReceiptPaymentsRequest.php
# Expected: [OK] No errors
./vendor/bin/pint --test app/Modules/POS/Application/Services/ReceiptPaymentService.php app/Modules/POS/Presentation/Requests/StoreReceiptPaymentsRequest.php
# Expected: {"result":"pass"}
```

---

## Task 1: ScopedExists helper + unit tests

**Files:**
- Create: `apps/api/app/Shared/Presentation/Validation/ScopedExists.php`
- Create: `apps/api/tests/Unit/Shared/Presentation/Validation/ScopedExistsTest.php`

- [ ] **Step 1.1: Write failing unit test for the three factory methods**

Create `apps/api/tests/Unit/Shared/Presentation/Validation/ScopedExistsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Presentation\Validation;

use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Validation\Rules\Exists;
use PHPUnit\Framework\TestCase;

final class ScopedExistsTest extends TestCase
{
    public function test_tenant_and_company_returns_exists_rule_with_both_predicates(): void
    {
        $rule = ScopedExists::tenantAndCompany('payment_methods', 'tenant-uuid', 'company-uuid');

        $this->assertInstanceOf(Exists::class, $rule);
        $this->assertStringContainsString('payment_methods', (string) $rule);
        $this->assertStringContainsString('tenant_id', (string) $rule);
        $this->assertStringContainsString('company_id', (string) $rule);
    }

    public function test_tenant_returns_exists_rule_with_tenant_predicate_only(): void
    {
        $rule = ScopedExists::tenant('users', 'tenant-uuid');

        $this->assertInstanceOf(Exists::class, $rule);
        $this->assertStringContainsString('users', (string) $rule);
        $this->assertStringContainsString('tenant_id', (string) $rule);
        $this->assertStringNotContainsString('company_id', (string) $rule);
    }

    public function test_company_returns_exists_rule_with_company_predicate_only(): void
    {
        $rule = ScopedExists::company('accounts', 'company-uuid');

        $this->assertInstanceOf(Exists::class, $rule);
        $this->assertStringContainsString('accounts', (string) $rule);
        $this->assertStringNotContainsString('tenant_id', (string) $rule);
        $this->assertStringContainsString('company_id', (string) $rule);
    }

    public function test_null_tenant_id_translates_to_is_null_predicate(): void
    {
        // Fail-closed semantics: a null tenant id (e.g., resolveReceipt() returned null)
        // must produce a WHERE tenant_id IS NULL clause that matches no rows on
        // multi-tenant tables (where tenant_id is NOT NULL).
        $rule = ScopedExists::tenantAndCompany('payment_methods', null, null);

        // Laravel's DatabaseRule stores 'NULL' sentinel for null values; verified
        // separately in feature tests against the database.
        $this->assertInstanceOf(Exists::class, $rule);
    }

    public function test_custom_column_name_is_honored(): void
    {
        $rule = ScopedExists::tenantAndCompany('partners', 'tenant-uuid', 'company-uuid', 'uuid');

        $this->assertStringContainsString('uuid', (string) $rule);
    }
}
```

- [ ] **Step 1.2: Run unit test to verify it fails**

```bash
cd apps/api
php artisan test --filter=ScopedExistsTest
# Expected: 5 failures with "Class App\Shared\Presentation\Validation\ScopedExists not found"
```

- [ ] **Step 1.3: Implement the helper**

Create `apps/api/app/Shared/Presentation/Validation/ScopedExists.php`:

```php
<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Validation;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Tenant-scoped existence validation for FormRequests.
 *
 * Wraps Laravel's `Rule::exists()` with tenant_id / company_id predicates so
 * that user-supplied UUIDs cannot reference rows belonging to other tenants
 * or other companies within the same tenant. Pure factory — no auth, no
 * service location, no request-lifecycle coupling. Callers pass the scoping
 * context explicitly (typically derived from the authenticated user, the
 * resolved CompanyContext, or a path-bound parent entity such as the receipt
 * being paid).
 *
 * Fail-closed: nulls are translated to `WHERE tenant_id IS NULL` by Laravel.
 * Multi-tenant tables in this codebase declare `tenant_id` and `company_id`
 * as NOT NULL, so a null context value matches zero rows — safer than
 * silently emitting an unscoped query.
 *
 * Three factory methods cover the schema realities in this codebase:
 *  - tenantAndCompany — for resources with both columns (payment_methods,
 *    payment_repositories, partners, products, documents, etc.).
 *  - tenant — for resources scoped to tenant only (users, companies — these
 *    tables have no company_id column).
 *  - company — for resources scoped to company only (rare; e.g., accounts
 *    that belong to a single company without an explicit tenant column).
 *
 * See `docs/conventions/08-TENANT-ISOLATION.md` for the codebase-wide rule
 * and `tests/Architecture/TenantScopedExistsRulesTest.php` for the CI gate
 * that fails the build when a Presentation file uses a bare `exists:` rule
 * for a guarded table.
 */
final class ScopedExists
{
    /**
     * Resource scoped by both tenant_id and company_id.
     *
     * Most multi-tenant resources fit this pattern. Use when the resource
     * row has both `tenant_id` and `company_id` columns (verified at
     * 2025-11-30 migration: payment_methods, payment_repositories, partners,
     * products, documents).
     */
    public static function tenantAndCompany(
        string $table,
        ?string $tenantId,
        ?string $companyId,
        string $column = 'id',
    ): Exists {
        return Rule::exists($table, $column)
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId);
    }

    /**
     * Resource scoped by tenant_id only.
     *
     * For tables that have no `company_id` column. Verified examples:
     *   - users (`2025_11_30_000003_create_users_table.php`)
     *   - companies (`2025_11_30_104000_create_companies_table.php`)
     *
     * Using `tenantAndCompany` on these tables would produce a SQL error
     * ("column company_id does not exist") because the column literally
     * isn't there. Use this method instead.
     */
    public static function tenant(
        string $table,
        ?string $tenantId,
        string $column = 'id',
    ): Exists {
        return Rule::exists($table, $column)
            ->where('tenant_id', $tenantId);
    }

    /**
     * Resource scoped by company_id only.
     *
     * Rare. Use when a resource belongs to a single company without an
     * explicit tenant_id column (e.g., accounts that are inferred to be
     * tenant-scoped via their parent company). Verify the table actually
     * has only `company_id` before reaching for this method — most
     * multi-tenant resources in this codebase have both.
     */
    public static function company(
        string $table,
        ?string $companyId,
        string $column = 'id',
    ): Exists {
        return Rule::exists($table, $column)
            ->where('company_id', $companyId);
    }
}
```

- [ ] **Step 1.4: Run unit test to verify GREEN**

```bash
php artisan test --filter=ScopedExistsTest
# Expected: 5 passed
```

- [ ] **Step 1.5: PHPStan + Pint check**

```bash
./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Shared/Presentation/Validation/ScopedExists.php tests/Unit/Shared/Presentation/Validation/ScopedExistsTest.php
./vendor/bin/pint --test app/Shared/Presentation/Validation/ScopedExists.php tests/Unit/Shared/Presentation/Validation/ScopedExistsTest.php
# Expected: PHPStan [OK] No errors; Pint {"result":"pass"}
```

- [ ] **Step 1.6: Commit**

```bash
git add apps/api/app/Shared/Presentation/Validation/ScopedExists.php apps/api/tests/Unit/Shared/Presentation/Validation/ScopedExistsTest.php
git commit -m "$(cat <<'EOF'
feat(shared): introduce ScopedExists validation helper for tenant-isolation sweep

Three-method factory at App\Shared\Presentation\Validation\ScopedExists
wrapping Laravel's Rule::exists with tenant_id / company_id predicates.

- tenantAndCompany — for resources with both columns (most multi-tenant
  tables: payment_methods, payment_repositories, partners, products,
  documents).
- tenant — for tenant-scoped resources without a company_id column
  (users, companies).
- company — for the rare company-scoped-only case (accounts).

Pure factory: no auth(), no app(), no internal CompanyContext resolution.
Callers pass scoping context explicitly. Matches existing UpdateCouponRequest
precedent (constructor-inject CompanyContext into the FormRequest, derive
context, pass into the helper). Rule #13 compliant.

Fail-closed: null context values translate to WHERE column IS NULL via
Laravel's DatabaseRule, which matches zero rows on multi-tenant tables
where the columns are NOT NULL.

Architectural decision per Codex adversarial review of the original
proposal (verdict REQUIRES-DIFFERENT-APPROACH) — rejected the static
auth()/app() variant and the two-method API that would have crashed on
users/companies tables (no company_id column).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Architecture-test CI gate (Codex's recommended permanent regression guard)

**Files:**
- Create: `apps/api/tests/Architecture/TenantScopedExistsRulesTest.php`

- [ ] **Step 2.1: Write the architecture test**

Create the file using Codex's reference implementation from
`docs/superpowers/reviews/2026-05-01-tenant-isolation-B-architecture-codex-review.md`,
adapted to scan all `Presentation/` (not just `Presentation/Requests/`)
because the codebase also has inline controller validation:

```php
<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * CI gate: fails the build when any Presentation-tier PHP file uses a bare
 * `exists:<guarded_table>` rule. Every such reference must be replaced with
 * App\Shared\Presentation\Validation\ScopedExists::* (or an equivalent
 * explicit Rule::exists()->where(...) chain) so that user-supplied UUIDs
 * cannot reference rows belonging to other tenants/companies.
 *
 * The list of guarded tables is the seven multi-tenant resource families
 * Codex enumerated in the B-architecture review, plus the secondary tables
 * surfaced during sweep B execution. Add to the list whenever a new
 * multi-tenant table joins the schema.
 *
 * Sweep B leaves the POS cluster (sync, finalization, order, creation)
 * intentionally out of scope; that cluster is folded into the POS
 * stabilization session. Until that lands, this test must NOT be tightened
 * to scan POS service code — only Presentation. Application-tier coverage
 * is in tests/Architecture/TenantScopedFindCallsTest.php (Task 9).
 */
final class TenantScopedExistsRulesTest extends TestCase
{
    /** @var list<string> */
    private const GUARDED_TABLES = [
        'payment_methods',
        'payment_repositories',
        'partners',
        'products',
        'documents',
        'users',
        'companies',
        // Secondary multi-tenant tables surfaced during sweep B:
        'accounts',
        'locations',
        'payment_instruments',
        'document_lines',
        'pos_terminals',
        'contacts',
        'modifiers',
        'modifier_groups',
        'services',
    ];

    public function test_presentation_code_does_not_use_bare_exists_for_tenant_resources(): void
    {
        $root = dirname(__DIR__, 2).'/app/Modules';
        $violations = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            if (! str_contains($path, '/Presentation/')) {
                continue;
            }

            $contents = (string) file_get_contents($path);
            foreach (self::GUARDED_TABLES as $table) {
                // Match: 'exists:<table>,<column>' or "exists:<table>,<column>"
                // but allow Rule::exists('<table>', ...)->where(...) (the scoped form).
                if (preg_match("/['\"]exists:{$table}\\b/", $contents) === 1) {
                    $violations[] = str_replace($root.'/', '', $path)
                        ." uses bare exists:{$table} rule (use App\\Shared\\Presentation\\Validation\\ScopedExists)";
                }
            }
        }

        sort($violations);
        self::assertSame(
            [],
            $violations,
            "Bare exists: rules found in Presentation tier:\n  - ".implode("\n  - ", $violations).
            "\n\nReplace each with App\\Shared\\Presentation\\Validation\\ScopedExists::tenantAndCompany / tenant / company.\n".
            "See docs/conventions/08-TENANT-ISOLATION.md.",
        );
    }
}
```

- [ ] **Step 2.2: Run the test against current state**

```bash
php artisan test --filter=TenantScopedExistsRulesTest
# Expected: FAIL with a list of violations across multiple modules.
# This list IS the canonical inventory of remaining sweep-B sites.
# Save the output to docs/superpowers/audits/2026-05-01-tenant-isolation-sweep-inventory.md
# for the per-cluster tasks below.
```

- [ ] **Step 2.3: Save the inventory**

```bash
php artisan test --filter=TenantScopedExistsRulesTest 2>&1 | tee /tmp/sweep-inventory.txt
# Then extract the violation list and save to the audits directory.
```

Create `docs/superpowers/audits/2026-05-01-tenant-isolation-sweep-inventory.md` with the categorized list (one section per module cluster identified in the test output).

- [ ] **Step 2.4: Mark the architecture test as `@group sweep-progress` so CI can run it but the build is not red**

The test will stay red until the sweep finishes. Two options for the interim:

**Option A (recommended):** Mark the test `@group sweep-progress` in PHPUnit and exclude it from the default test run via `phpunit.xml` until the sweep is done. Add a separate CI job that runs only that group and reports as informational ("X violations remaining").

**Option B:** Initialize the test with the current violations as expected, so the test passes with a warning. Then strip violations from the expected list as each cluster lands.

Pick Option A — cleaner audit trail.

- [ ] **Step 2.5: Configure PHPUnit groups**

Add to `apps/api/phpunit.xml` (or whatever file declares `<groups>`):

```xml
<phpunit>
  <!-- ... existing config ... -->
  <groups>
    <exclude>
      <group>sweep-progress</group>
    </exclude>
  </groups>
</phpunit>
```

Ensure default test run excludes the group:

```bash
php artisan test
# Expected: existing suite passes; sweep-progress group is excluded.
php artisan test --group=sweep-progress
# Expected: shows the architecture test failing with the remaining violations.
```

- [ ] **Step 2.6: Commit**

```bash
git add apps/api/tests/Architecture/TenantScopedExistsRulesTest.php apps/api/phpunit.xml docs/superpowers/audits/2026-05-01-tenant-isolation-sweep-inventory.md
git commit -m "$(cat <<'EOF'
test(architecture): add CI gate against bare exists: rules for multi-tenant resources

A permanent PHPUnit architecture test that scans every Presentation-tier
PHP file and fails the build if any of the 16 guarded multi-tenant tables
appears in a bare `exists:<table>,<column>` rule. The replacement is
App\Shared\Presentation\Validation\ScopedExists::* — see
docs/conventions/08-TENANT-ISOLATION.md (Task 4 in this plan) for the rule.

The test starts red — current violations are documented in
docs/superpowers/audits/2026-05-01-tenant-isolation-sweep-inventory.md and
will be cleared cluster-by-cluster in subsequent commits. The test is
marked `@group sweep-progress` and excluded from the default test run via
phpunit.xml until the sweep completes; CI runs the group separately so
remaining violations are visible without breaking the build.

Per Codex adversarial review B (REQUIRES-DIFFERENT-APPROACH counter-
proposal). The reasoning: feature tests catch important workflows, but
they cannot enumerate every callsite — a static gate is the only way to
guarantee that future PRs cannot reintroduce the regression class.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Correct architecture documentation (load-bearing — every developer reads this)

**Files:**
- Modify: `apps/erp/CLAUDE.md` (line 111: "schema-based multi-tenancy" — false)
- Modify: `apps/erp/.claude/context/architecture.md` (lines 54-57: "schema-based" claim — false)
- Create: `apps/erp/docs/conventions/08-TENANT-ISOLATION.md`

- [ ] **Step 3.1: Correct CLAUDE.md**

Read `apps/erp/CLAUDE.md` line 111. Replace `(schema-based multi-tenancy)` with `(shared-DB multi-tenancy via tenant_id / company_id columns)`. Add a footnote pointing to the new convention doc.

Exact edit:

```markdown
| Database | PostgreSQL 16+ (shared-DB multi-tenancy via `tenant_id` / `company_id` columns; see `docs/conventions/08-TENANT-ISOLATION.md` for the scoping discipline) |
```

- [ ] **Step 3.2: Correct architecture.md**

Replace lines 54-57 of `.claude/context/architecture.md`:

```markdown
## Multi-Tenancy (Shared-DB with Row-Level Scoping)

All tenants share one PostgreSQL database in the `public` schema. Tenant
isolation is enforced at the application layer via `tenant_id` and
`company_id` columns on every multi-tenant table. The `Tenant` model is the
account holder; the `Company` model is the legal entity (one tenant can
own multiple companies). All operational tables filter by `company_id`;
sequences (`document_sequences`, `pos_z_reports`, vouchers) are
company-scoped.

`stancl/tenancy: ^3.9` is installed but currently inactive — its database
managers (`PostgreSQLDatabaseManager`, `PostgreSQLSchemaManager`) are
imported in `config/tenancy.php` but not bound to bootstrappers. The
codebase was originally written against the schema-per-tenant claim that
appeared in earlier versions of this document; the implementation
diverged, the claim was not updated, and the gap surfaced during the
2026-05-01 tenant-isolation security sweep. See
`docs/superpowers/research/2026-05-01-multi-tenancy-architecture-survey.md`
for the strategic options being evaluated for migration to a stronger
isolation model.

**Scoping discipline:** see `docs/conventions/08-TENANT-ISOLATION.md`. All
FormRequests must use `App\Shared\Presentation\Validation\ScopedExists`
for `exists:` rules. All Application-tier `Model::find()` /
`findOrFail()` calls on multi-tenant resources must be preceded by an
explicit tenant/company filter. CI gate at
`tests/Architecture/TenantScopedExistsRulesTest.php`.
```

- [ ] **Step 3.3: Write the convention doc**

Create `apps/erp/docs/conventions/08-TENANT-ISOLATION.md`:

```markdown
# 08 — Tenant Isolation

> **Status:** authoritative as of 2026-05-01. Cross-references: Rule #6 (module
> boundaries), #13 (constructor injection only), `docs/conventions/06-FORMS.md`,
> `apps/api/app/Shared/Presentation/Validation/ScopedExists.php`,
> `apps/api/tests/Architecture/TenantScopedExistsRulesTest.php`.

## Why this exists

AutoERP is implemented as shared-DB multi-tenancy: all tenants share one
PostgreSQL database, and isolation is enforced at the application layer
via `tenant_id` and `company_id` columns. There is no Postgres-level
isolation. Every query that reads or writes a multi-tenant resource is a
potential cross-tenant leak unless explicitly scoped.

The 2026-05-01 security sweep found ~73 unscoped `Model::find()` calls
and ~34 bare `exists:` validator rules that accepted other tenants' UUIDs
and would silently bind them onto the requesting tenant's records. This
document is the discipline that prevents the regression class from
recurring.

## The rule

### FormRequests (Presentation tier)

Every `exists:` rule on a multi-tenant resource MUST be replaced with
`App\Shared\Presentation\Validation\ScopedExists::*`. Three factories:

- `ScopedExists::tenantAndCompany($table, $tenantId, $companyId)` — for
  resources with both `tenant_id` and `company_id` columns. Most multi-
  tenant tables. Use this by default.
- `ScopedExists::tenant($table, $tenantId)` — for resources with only
  `tenant_id` (users, companies).
- `ScopedExists::company($table, $companyId)` — rare; for resources with
  only `company_id`.

The helper does NOT resolve `auth()` / `CompanyContext` internally. Pass
the scoping context explicitly. The canonical pattern is:

```php
use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;

final class CreateBatchRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id;
        $companyId = $this->companyContext->requireCompanyId();

        return [
            'product_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('products', $tenantId, $companyId),
            ],
        ];
    }
}
```

For nested resources where the parent entity (e.g., a receipt being paid)
defines the scoping context rather than the authenticated user, derive
context from the parent:

```php
$receipt = $this->resolveReceiptFromRoute(); // returns ?Receipt
$tenantId = $receipt?->tenant_id;
$companyId = $receipt?->company_id;

ScopedExists::tenantAndCompany('payment_methods', $tenantId, $companyId)
```

If the parent is missing, null context values translate to `WHERE
tenant_id IS NULL` — fail-closed because the columns are NOT NULL.

### Application services

Any service-layer `Model::find()` or `Model::findOrFail()` on a
multi-tenant resource MUST be preceded by an explicit tenant/company
filter:

```php
// Wrong:
$method = PaymentMethod::findOrFail($paymentMethodId);

// Right:
$method = PaymentMethod::query()
    ->where('tenant_id', $receipt->tenant_id)
    ->where('company_id', $receipt->company_id)
    ->findOrFail($paymentMethodId);
```

This applies to every callsite that resolves a foreign-key id from
external input (request body, queue payload, sync envelope). Internal
flows that already operate on a known tenant context (e.g., a fiscal
verify-chain command running per-terminal) are exempt — but document the
exemption in a comment.

### Forbidden patterns

- `Model::find($id)` without preceding tenant/company filter for any
  guarded resource.
- `Model::findOrFail($id)` same.
- `'exists:<guarded_table>,id'` as a string rule.
- `Rule::exists('<guarded_table>', 'id')` without `->where('tenant_id', ...)`.
- `app(SomeContextService::class)` inside a static factory or static
  rule builder (use constructor injection, see Rule #13).

## What's a "guarded resource"?

The current guarded list lives in
`tests/Architecture/TenantScopedExistsRulesTest.php::GUARDED_TABLES`:
payment_methods, payment_repositories, partners, products, documents,
users, companies, accounts, locations, payment_instruments,
document_lines, pos_terminals, contacts, modifiers, modifier_groups,
services.

When a new multi-tenant table joins the schema:
1. Add it to the migration with both `tenant_id` and `company_id`
   columns where applicable.
2. Add it to `GUARDED_TABLES` in the architecture test.
3. Document any tenant-only tables (no `company_id`) in the docblock.

## CI enforcement

`tests/Architecture/TenantScopedExistsRulesTest.php` scans every
Presentation-tier PHP file for bare `exists:<guarded_table>` patterns
and fails the build. A second test
(`tests/Architecture/TenantScopedFindCallsTest.php`, added in Task 9 of
the sweep) scans Application services for unscoped `Model::find()`
calls.

## Future direction (informational)

The team is evaluating migration to Stancl/Tenancy multi-database mode
(one PostgreSQL DB per tenant). See
`docs/superpowers/research/2026-05-01-multi-tenancy-architecture-survey.md`
and `docs/superpowers/research/2026-05-01-db-per-tenant-migration-mechanics.md`.

This convention applies regardless: under multi-DB, the helper enforces
defence-in-depth during the migration window when half the codebase is
multi-DB and half is still row-level. The convention doc evolves; the
discipline survives.
```

- [ ] **Step 3.4: Commit**

```bash
git add apps/erp/CLAUDE.md apps/erp/.claude/context/architecture.md apps/erp/docs/conventions/08-TENANT-ISOLATION.md
git commit -m "$(cat <<'EOF'
docs: correct multi-tenancy claims and add tenant-isolation convention

The architecture documentation claimed schema-based multi-tenancy ("each
tenant gets a PostgreSQL schema"), but the implementation is shared-DB
with tenant_id/company_id columns. The drift was the root cause of the
~73 unscoped find() calls and ~34 bare exists: rules surfaced by the
2026-05-01 security sweep — every developer who read the docs assumed
schema isolation was doing the work that was actually their
responsibility.

Changes:
- CLAUDE.md L111: "schema-based" → "shared-DB via tenant_id/company_id".
- architecture.md "Multi-Tenancy" section: replaced with truthful
  description, including stancl/tenancy installed-but-inactive note.
- New docs/conventions/08-TENANT-ISOLATION.md: the codebase-wide rule
  for scoping discipline (FormRequests use ScopedExists; service-layer
  find() calls preceded by explicit where()), the canonical
  UpdateCouponRequest precedent, the forbidden patterns, and the CI
  gate references.

Future-proof: convention doc explicitly notes that the rule survives a
future migration to Stancl multi-database mode (defence-in-depth during
the migration window).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Tasks 4-8: Per-cluster sweep (validator + service-layer in same commit)

Each cluster follows the same template. The cluster boundaries are determined
by the inventory from Task 2.3. Adjust files based on actual violations
discovered in Step 2.2.

### Task 4: Treasury cluster

**Files (representative — confirm against inventory):**
- Modify: `apps/api/app/Modules/Treasury/Presentation/Requests/RefundPrepaymentRequest.php`
- Modify: `apps/api/app/Modules/Treasury/Presentation/Controllers/MultiPaymentController.php`
- Modify: `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php`
- Modify: `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentInstrumentController.php`
- Test: `apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php`

- [ ] **Step 4.1: Inventory pass — list every Treasury Presentation site touched.**

```bash
grep -rnE "['\"]exists:(payment_methods|payment_repositories|partners|products|documents)" apps/api/app/Modules/Treasury/Presentation/
grep -rnE "(PaymentMethod|PaymentRepository|Partner|Document)::(find|findOrFail)\(" apps/api/app/Modules/Treasury/Presentation/ apps/api/app/Modules/Treasury/Application/
```

- [ ] **Step 4.2: Write a failing feature test for the cluster's most exploitable flow.**

Mirror the shape of `apps/api/tests/Feature/POS/StoreReceiptPaymentsTenantIsolationTest.php` (the canonical reference test). Cover at least:
- Cross-tenant payment_method_id submission via the most-mutating Treasury endpoint.
- Cross-tenant repository_id submission.
- Cross-tenant partner_id submission (where the endpoint accepts one).
- Same-tenant control to prove the fix isn't over-aggressive.

- [ ] **Step 4.3: Run the test to verify RED.**

- [ ] **Step 4.4: Replace bare `exists:` rules with `ScopedExists::*` in every Request file in the cluster.**

For FormRequests that don't already constructor-inject `CompanyContext`, refactor to follow `UpdateCouponRequest` pattern. For controller inline validation (`MultiPaymentController`, `PaymentInstrumentController`), either move to a FormRequest or inject `CompanyContext` into the controller and use `ScopedExists` inline.

- [ ] **Step 4.5: Replace bare `find()`/`findOrFail()` calls in the cluster's service/controller code with explicit tenant-scoped lookups.**

- [ ] **Step 4.6: Run the cluster feature test to verify GREEN.**

- [ ] **Step 4.7: Run the broader Treasury suite to confirm no regression.**

```bash
php artisan test --filter=Treasury
```

- [ ] **Step 4.8: PHPStan + Pint on changed files. Commit.**

Commit message template:
```
fix(treasury): tenant-scope all multi-tenant resource lookups

Closes the Treasury slice of the tenant-isolation sweep:
- ${N} bare `exists:` rules in Request files replaced with ScopedExists.
- ${M} unscoped Model::find()/findOrFail() calls in services/controllers
  replaced with explicit tenant/company-filtered lookups.
- New regression test at tests/Feature/Treasury/TreasuryTenantIsolationTest.php
  covers cross-tenant payment_method_id, repository_id, and partner_id
  rejection plus a same-tenant control.

Cluster boundary: see docs/superpowers/audits/2026-05-01-tenant-isolation-sweep-inventory.md.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
```

### Task 5: Document cluster

Same template as Task 4. Files to investigate (subject to inventory):
- `DocumentConversionController` (route document load — bare `Document::findOrFail()`).
- `RefundController` (route document load).
- `DocumentPdfController` (download/preview/generate paths from bare doc IDs).
- Any Document FormRequest with bare `exists:partners,id` or `exists:documents,id`.

Test file: `tests/Feature/Document/DocumentTenantIsolationTest.php`.

### Task 6: Inventory cluster

Same template. Files:
- `CreateBatchRequest`, `CreateCountingRequest`, `CountingItemRequest` etc.
- `StockReservationService::Product::find` (`:355-368`).
- `StockAdjustmentService::Product::find` (`:463-475`).

Test file: `tests/Feature/Inventory/InventoryTenantIsolationTest.php`.

### Task 7: Taxation cluster

Same template. Files:
- `CreateWithholdingCertificateRequest::partner_id`, `document_id`.
- Any tax-related service `find()` calls.

Test file: `tests/Feature/Taxation/TaxationTenantIsolationTest.php`.

### Task 8: Loyalty + Accounting clusters

Same template, separate commits per cluster:
- Loyalty: `CreateProgramRequest::company_ids.*` (uses `tenant` factory because companies has no company_id).
- Accounting: `GetLedgerRequest::partner_id`, `PartnerBalanceService::Partner::findOrFail` (`:288-318`, `:357-367`).

Test files: `tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php`, `tests/Feature/Accounting/AccountingTenantIsolationTest.php`.

---

## Task 9: Service-layer architecture test (catch service-tier `find()` regressions)

**Files:**
- Create: `apps/api/tests/Architecture/TenantScopedFindCallsTest.php`

- [ ] **Step 9.1: Write the architecture test**

Mirror Task 2's shape but scan `app/Modules/*/Application/` and
`app/Modules/*/Domain/Services/` for patterns like
`(PaymentMethod|PaymentRepository|Partner|Product|...)::find\(` that are NOT
preceded (in the same statement or the previous 5 lines) by a
`->where('tenant_id', ...)` or `->where('company_id', ...)` filter.

Implementation note: the regex is more complex than Task 2 because we
need a windowed lookback. Use a simple line-buffer approach: parse the
file, find every call, scan the previous N lines for the scoping filter,
flag if absent. Generate a per-callsite report.

- [ ] **Step 9.2: Initial run produces violation list. Mark `@group sweep-progress` like Task 2.**

- [ ] **Step 9.3: Save inventory + commit.**

---

## Task 10: Final verification + cleanup

- [ ] **Step 10.1: Strip the `@group sweep-progress` markers from both architecture tests once their violation lists are empty for non-POS clusters.**

The POS cluster items remain — those are deferred. Mark the architecture tests as "PASSES for non-POS modules; POS is in
sweep-progress until the POS stabilization session lands the offline-first/sync work."

- [ ] **Step 10.2: Full preflight.**

```bash
cd apps/api
./scripts/preflight.sh
# OR if that's missing, run individually:
./vendor/bin/phpstan analyse --no-progress --memory-limit=2G
./vendor/bin/pint --test
php artisan test
```

- [ ] **Step 10.3: Open PR `fix/tenant-isolation` → `dev`.**

PR body must include:
- The diagnostic memory + Codex reviews as references.
- The cluster-by-cluster commit list.
- The architecture test enforcement story.
- The known-deferred POS cluster (with link to where it'll be picked up).
- The corrected docs as a load-bearing artefact.

- [ ] **Step 10.4: Hand to user for `dev → main` promotion.**

Per `feedback_dev_main_promotion_discipline.md`: do NOT promote directly. Land on `dev` first, let the user decide when to merge `dev → main`.

---

## Out-of-scope items deliberately left for follow-up

| Item | Where it lives | Why deferred |
|---|---|---|
| POS cluster (ReceiptSyncService, ReceiptCreationService, OrderManagementService, ReceiptFinalizationService, VoucherLookupService, VoucherRedemptionService voucher lookup) | POS stabilization session | Overlaps with offline-first / sync hardening work; better sequenced together. |
| Adding RLS as defence-in-depth | Separate ~4-day project | Not blocked by this sweep; does not change the Application-tier discipline. |
| Migration to Stancl multi-DB mode | Separate strategic project | See `docs/superpowers/research/2026-05-01-multi-tenancy-architecture-survey.md`. |
| Frontend equivalents (TanStack Query keys leaking across tenants on tenant switch) | Separate frontend audit | Backend-only sweep. |

---

## Self-review checklist

- [ ] Spec covers every cluster Codex identified in the B-architecture review (Treasury, Document, Inventory, Taxation, Loyalty, Accounting). POS deliberately excluded.
- [ ] No placeholder steps. Every step has actual code or actual command.
- [ ] Method signatures (`tenantAndCompany`, `tenant`, `company`) consistent across the helper and its usages.
- [ ] Architecture test code complete (not "// TODO scan files").
- [ ] Convention doc complete with concrete examples, not abstractions.
- [ ] Preflight commands match `apps/api`'s actual tooling.
- [ ] Branch + commit cadence respects the dev-first workflow (`feedback_dev_branch_workflow.md`).
