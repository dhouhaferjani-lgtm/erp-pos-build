# Receipt settings + live back-office preview (Lane E, PR 2) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** From the web dashboard (`/settings/company`, tab *receipt*), a manager switches the receipt **logo** on/off and chooses whether the subtotal is stated **TTC or HT**, and sees a **live preview of the customer ticket exactly as the thermal printer lays it out** before saving.

**Architecture:** One new tenant column (`companies.receipt_subtotal_mode`) with a PHP enum; `companies.receipt_logo` finally gets a writer and a boolean cast; `GET companies/{id}/pos-settings` returns a Spatie DTO so the web reads a generated type; `GET /company/config` exposes the two new fields to the POS device. The preview is not a fourth template: `ReceiptPreview.tsx` calls the **same** `buildReceiptDoc` the printer uses (PR 1) on the RHF form values and renders the resulting `ReceiptDoc` into a monospace grid at 42 or 32 columns.

**Tech Stack:** Laravel 12 / PHP 8.2 strict, `spatie/laravel-data` + `spatie/typescript-transformer`, PostgreSQL 16 (database-per-tenant), React 19 + react-hook-form + zod, TanStack Query 5, Vitest 3, Playwright.

**Spec:** [`docs/superpowers/specs/2026-09-17-pos-receipt-preview-design.md`](../specs/2026-09-17-pos-receipt-preview-design.md) — Delivery table, PR 2 row. Phase-1 evidence: `docs/sessions/2026-09-17-lane-e/phase1-report.md`. Registry: `docs/qa/DEV-QA-registry.md` — **DEV-QA-089 is explicitly NOT fixed here** (ticket E-4, out of brief).

**Branch:** `feat/pos-receipt-settings-web`, cut **off PR 1's `feat/pos-receipt-preview`** (it imports `buildReceiptDoc`, `sampleReceipt`, `padColumns` and `ReceiptDisplaySettings` from `@autoerp/shared/src/receipt`). PR → `dev` after PR 1 merges; Houssam merges.

## Global Constraints

- **Money and quantities are strings end to end (CLAUDE.md rule 19).** No `Number(...)`, no `parseFloat`, no `+value`, no `(float)` cast. The preview renders the PR-1 fixture, whose every amount is already a decimal string; it performs no arithmetic.
- **No `any`** (rule 3). PHP: no `mixed` — DTOs (rule 3). TypeScript: `unknown` + type guards.
- **Every status/type column uses a PHP enum** (rule 9): `receipt_subtotal_mode` is backed by `App\Modules\Company\Domain\Enums\ReceiptSubtotalMode`.
- **Types flow from the backend** (rule 7). `packages/shared/types/generated.d.ts` is regenerated with `cd apps/api && CACHE_STORE=array php artisan typescript:transform` and committed; never hand-edited. The web re-exports from the generated ambient namespace, never re-declares a backend field (convention 04 rule 5).
- **Every user-facing string uses `t()`** (rule 11). Keys land in `fr`, `en` **and** `ar` for the web. `pnpm --filter @autoerp/web audit:i18n:local` is part of the gate.
- **Design tokens only in `.tsx`** (rule 18): import `tokens`, `textColors`, `borderColors`, `semanticColorTokens` from `@/lib/designTokens`. `ReceiptPreview.tsx` is a NEW file, so token use is an ESLint **error** there, not a warning.
- **Forms use react-hook-form + zod** (convention 06) inside the EXISTING form in `ReceiptSettingsTab.tsx` — one form, one submit, one `PUT`.
- **Tenant query keys use `tenantScopedKey([...])`** (rule 14 bullet), enforced by `apps/web/tools/audit-tanstack-keys.mjs`.
- **Constructor injection only** (rule 13) — never the `app()` helper in module code.
- **Second-of-everything (rule 22 / convention 09):** the PHPUnit class ships a second-company test, a second-location test and a re-run/idempotency test, asserting on **data meaning** and not on HTTP status.
- **The full PHPUnit suite is FORBIDDEN on this laptop.** Run scoped only: `./vendor/bin/phpunit tests/Feature/Company/ReceiptSettingsTest.php`, and never two PHPUnit processes at once.
- **`DEV-QA-089` (ungated `GET companies/{id}/pos-settings`, `apps/api/app/Modules/Company/routes.php:43-44`) is NOT fixed by this PR.** It is ticket E-4. Do not add a `can:` middleware to that route in this lane, and say so in the PR description so a reviewer does not read the cashier-403 test as covering the read path — it covers the **write** path only.
- **Commit subjects are `type(scope): summary`.** Each task ends with a commit step.

---

### Task 1: Tenant column, `ReceiptSubtotalMode` enum, model casts, lane registration

`companies.receipt_logo` exists (`apps/api/database/migrations/tenant/2026_01_10_063904_add_receipt_settings_to_companies_table.php:19-22`, `string(255)` nullable) and is read as a truthiness toggle by the PDF (`apps/api/resources/views/pos/receipt.blade.php:317-319`) — but it has **no writer at all**: it is absent from `UpdateReceiptSettingsRequest::rules()` (`:21-30`) and from the web form (`ReceiptSettingsTab.tsx:39-58`). This task gives it a boolean cast and adds the one new column ruling 4 permits.

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_09_17_120000_add_receipt_subtotal_mode_to_companies_table.php`
- Create: `apps/api/app/Modules/Company/Domain/Enums/ReceiptSubtotalMode.php`
- Create: `apps/api/tests/Feature/Company/ReceiptSettingsTest.php`
- Modify: `apps/api/app/Modules/Company/Domain/Company.php:83` (property docblock), `:259-269` (`$fillable`), `:325-330` (`casts()`)
- Modify: `apps/api/tests/feature-lane-manifest.json` (group `Company`, `classes` 34 → 35, with a raise note)

**Interfaces:**
- Produces:
  - `enum ReceiptSubtotalMode: string { case Ttc = 'TTC'; case Ht = 'HT'; }` at `App\Modules\Company\Domain\Enums\ReceiptSubtotalMode`
  - `companies.receipt_subtotal_mode` — `string(3)`, NOT NULL, default `'TTC'`
  - `Company::$casts` gains `'receipt_logo' => 'boolean'` and `'receipt_subtotal_mode' => ReceiptSubtotalMode::class`
  - Generated TS (Task 3): `App.Modules.Company.Domain.Enums.ReceiptSubtotalMode = 'TTC' | 'HT'`

- [ ] **Step 1: Write the failing test**

`apps/api/tests/Feature/Company/ReceiptSettingsTest.php` — the class the whole PR grows into. Start with the schema and cast block:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\ReceiptSubtotalMode;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

final class ReceiptSettingsTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->for($this->tenant)->create();
        $this->admin->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->admin->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
    }

    public function test_a_new_company_defaults_to_the_tax_inclusive_subtotal(): void
    {
        $company = Company::factory()->for($this->tenant)->create();

        $this->assertSame(ReceiptSubtotalMode::Ttc, $company->refresh()->receipt_subtotal_mode);
    }

    public function test_the_subtotal_mode_is_an_enum_not_a_magic_string(): void
    {
        $this->company->update(['receipt_subtotal_mode' => ReceiptSubtotalMode::Ht]);

        $this->assertInstanceOf(ReceiptSubtotalMode::class, $this->company->refresh()->receipt_subtotal_mode);
        $this->assertSame('HT', $this->company->receipt_subtotal_mode->value);
    }

    public function test_receipt_logo_reads_as_a_boolean_flag(): void
    {
        $this->company->update(['receipt_logo' => true]);
        $this->assertTrue($this->company->refresh()->receipt_logo);

        $this->company->update(['receipt_logo' => false]);
        $this->assertFalse($this->company->refresh()->receipt_logo);
    }

    public function test_a_legacy_logo_path_still_reads_as_switched_on(): void
    {
        // Before this lane the column held a path string used as a truthiness
        // toggle by the PDF (receipt.blade.php:317-319). The boolean cast must
        // preserve that meaning for rows written before the writer existed.
        Company::whereKey($this->company->id)->update(['receipt_logo' => 'storage/logos/nour.png']);

        $this->assertTrue($this->company->refresh()->receipt_logo);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Company/ReceiptSettingsTest.php`
Expected: FAIL — `Class "App\Modules\Company\Domain\Enums\ReceiptSubtotalMode" not found`.

- [ ] **Step 3: Write the enum**

`apps/api/app/Modules/Company/Domain/Enums/ReceiptSubtotalMode.php` (mirrors the shape of `PriceEntryMode.php` in the same directory):

```php
<?php

declare(strict_types=1);

namespace App\Modules\Company\Domain\Enums;

/**
 * How the customer ticket states its subtotal block.
 *
 * DISPLAY ONLY (owner ruling 3, 2026-09-17): amounts, the sealed payload, the
 * fiscal code and the TOTAL are identical in both modes. `Ht` restates the
 * subtotal and the remise tax-exclusively and labels the total `TOTAL TTC`.
 *
 * Mirrored on the frontend by the generated
 * `App.Modules.Company.Domain.Enums.ReceiptSubtotalMode`, which is the source
 * of truth for the shared `ReceiptSubtotalMode` alias (CLAUDE.md rule 7).
 */
enum ReceiptSubtotalMode: string
{
    case Ttc = 'TTC';
    case Ht = 'HT';
}
```

- [ ] **Step 4: Write the migration**

`apps/api/database/migrations/tenant/2026_09_17_120000_add_receipt_subtotal_mode_to_companies_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('receipt_subtotal_mode', 3)
                ->default('TTC')
                ->after('receipt_thank_you')
                ->comment('TTC|HT — display-only statement of the ticket subtotal block (Lane E)');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('receipt_subtotal_mode');
        });
    }
};
```

The column is NOT NULL with a default, so every existing company keeps printing exactly today's ticket. No `company_id`-scoped unique key is introduced, so the catalogue ratchet (`TenantOnlyUniqueOnCatalogueTablesRatchetTest`) is unaffected.

- [ ] **Step 5: Wire the model**

`apps/api/app/Modules/Company/Domain/Company.php`:
- after the `@property string|null $receipt_thank_you` docblock line (`:90`), add:

```php
 * @property ReceiptSubtotalMode $receipt_subtotal_mode Display-only TTC|HT statement of the ticket subtotal
```

and change line 83 to:

```php
 * @property bool|null $receipt_logo Whether the company logo prints on the receipt
```

- in `$fillable` after `'receipt_thank_you',` (`:269`), add:

```php
        'receipt_subtotal_mode',
```

- in `casts()` after `'receipt_show_customer' => 'boolean',` (`:330`), add:

```php
            'receipt_logo' => 'boolean',
            'receipt_subtotal_mode' => ReceiptSubtotalMode::class,
```

and import the enum at the top of the file:

```php
use App\Modules\Company\Domain\Enums\ReceiptSubtotalMode;
```

- [ ] **Step 6: Register the new test class in the lane manifest**

`apps/api/tests/feature-lane-manifest.json` — in the `Company` group (line ~740), raise `"classes": 34` to `35` and **prepend** to the existing `note` string:

```
DELIBERATE RAISE 34 -> 35 (2026-09-17, Lane E PR 2 feat/pos-receipt-settings-web): ReceiptSettingsTest — the only pin on the receipt display settings (receipt_subtotal_mode enum + default, receipt_logo boolean cast and its legacy-path meaning, the FormRequest rules, the create->edit->revert round trip, cashier 403 on the WRITE path, save-twice idempotency, second company and second location, and the /company/config exposure). feature-lane-tenancy/Company is PARKED behind vars.SELF_HOSTED_RUNNER_READY, so the class is also named in the backend-test-pgsql --filter allowlist (same precedent as FiscalPeriodCloseEndpointTest) to execute on PR->dev. === prior note ===
```

Then add `ReceiptSettingsTest` to the `--filter` allowlist of the `backend-test-pgsql` job in `.github/workflows/ci.yml`, beside the other named Company classes.

- [ ] **Step 7: Run the tests to verify they pass**

```bash
cd apps/api && php artisan migrate && php artisan tenants:migrate
cd apps/api && ./vendor/bin/phpunit tests/Feature/Company/ReceiptSettingsTest.php
```
Expected: PASS — 4 tests.

Run: `cd apps/api && php tools/feature-lane-manifest-check.php` (or the preflight step that wraps it)
Expected: PASS — the new directory entry is consistent.

Run: `cd apps/api && ./vendor/bin/pint --test app/Modules/Company database/migrations/tenant tests/Feature/Company`
Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Company --level=8 --memory-limit=2G`
Expected: both PASS.

- [ ] **Step 8: Commit**

```bash
git add apps/api/database/migrations/tenant apps/api/app/Modules/Company/Domain \
        apps/api/tests/Feature/Company/ReceiptSettingsTest.php \
        apps/api/tests/feature-lane-manifest.json .github/workflows/ci.yml
git commit -m "feat(company): receipt_subtotal_mode column + ReceiptSubtotalMode enum + receipt_logo boolean cast"
```

---

### Task 2: Validation rules and the write path — round-trip, cashier 403, idempotency, second company, second location

`UpdateReceiptSettingsRequest::rules()` (`apps/api/app/Modules/Company/Presentation/Requests/UpdateReceiptSettingsRequest.php:21-30`) has no `receipt_logo` rule at all, which is why the column has no writer. `CompanyController::updateReceiptSettings` (`:602-630`) passes `$request->validated()` straight to `$company->update(...)`, so an added rule is all that is needed on the write side; the response array at `:617-627` must also return the two fields.

The write route is already gated `can:settings.update` (`apps/api/app/Modules/Company/routes.php:47-49`); `cashier` holds no `settings.*` at all (`RolesAndPermissionsSeeder.php:786-795`). This task proves it rather than assuming it.

**Files:**
- Modify: `apps/api/app/Modules/Company/Presentation/Requests/UpdateReceiptSettingsRequest.php:21-30` (rules), `:35-44` (messages)
- Modify: `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:617-627` (update response)
- Modify: `apps/api/tests/Feature/Company/ReceiptSettingsTest.php` (append)

**Interfaces:**
- Consumes: `ReceiptSubtotalMode` (Task 1).
- Produces: `PUT /api/v1/companies/{companyId}/receipt-settings` accepts `receipt_logo` (boolean) and `receipt_subtotal_mode` (`TTC`|`HT`) and echoes both in `data`.

- [ ] **Step 1: Write the failing tests**

Append to `apps/api/tests/Feature/Company/ReceiptSettingsTest.php`:

```php
    private function secondCompany(): Company
    {
        $company = Company::factory()->for($this->tenant)->create();

        UserCompanyMembership::create([
            'user_id' => $this->admin->id,
            'company_id' => $company->id,
            'role' => 'admin',
        ]);

        return $company;
    }

    private function cashier(): User
    {
        $cashier = User::factory()->for($this->tenant)->create();
        $cashier->assignRole('cashier');

        UserCompanyMembership::create([
            'user_id' => $cashier->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);

        return $cashier;
    }

    public function test_the_manager_can_switch_the_logo_on_and_choose_the_ht_subtotal(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/companies/{$this->company->id}/receipt-settings", [
                'receipt_logo' => true,
                'receipt_subtotal_mode' => 'HT',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.receipt_logo', true)
            ->assertJsonPath('data.receipt_subtotal_mode', 'HT');

        $this->company->refresh();
        $this->assertTrue($this->company->receipt_logo);
        $this->assertSame(ReceiptSubtotalMode::Ht, $this->company->receipt_subtotal_mode);
    }

    public function test_the_settings_survive_a_create_edit_revert_round_trip(): void
    {
        $put = fn (array $payload) => $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/companies/{$this->company->id}/receipt-settings", $payload);

        // create
        $put(['receipt_logo' => true, 'receipt_subtotal_mode' => 'HT'])->assertOk();
        $this->assertTrue($this->company->refresh()->receipt_logo);
        $this->assertSame(ReceiptSubtotalMode::Ht, $this->company->receipt_subtotal_mode);

        // edit
        $put(['receipt_logo' => true, 'receipt_subtotal_mode' => 'TTC'])->assertOk();
        $this->assertSame(ReceiptSubtotalMode::Ttc, $this->company->refresh()->receipt_subtotal_mode);
        $this->assertTrue($this->company->receipt_logo);

        // revert to the shipped default
        $put(['receipt_logo' => false, 'receipt_subtotal_mode' => 'TTC'])->assertOk();
        $this->company->refresh();
        $this->assertFalse($this->company->receipt_logo);
        $this->assertSame(ReceiptSubtotalMode::Ttc, $this->company->receipt_subtotal_mode);
    }

    public function test_saving_the_same_settings_twice_leaves_one_row_state(): void
    {
        $payload = ['receipt_logo' => true, 'receipt_subtotal_mode' => 'HT', 'receipt_footer' => 'A bientôt'];

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/companies/{$this->company->id}/receipt-settings", $payload)
            ->assertOk();
        $afterFirst = $this->company->refresh()->only([
            'receipt_logo', 'receipt_subtotal_mode', 'receipt_footer',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/companies/{$this->company->id}/receipt-settings", $payload)
            ->assertOk();

        $this->assertSame($afterFirst, $this->company->refresh()->only([
            'receipt_logo', 'receipt_subtotal_mode', 'receipt_footer',
        ]));
        $this->assertSame(1, Company::whereKey($this->company->id)->count());
    }

    public function test_an_unknown_subtotal_mode_is_refused(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/companies/{$this->company->id}/receipt-settings", [
                'receipt_subtotal_mode' => 'ht',
            ]);

        $this->assertApiValidationErrors($response, ['receipt_subtotal_mode']);
        $this->assertSame(ReceiptSubtotalMode::Ttc, $this->company->refresh()->receipt_subtotal_mode);
    }

    public function test_a_non_boolean_logo_flag_is_refused(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/companies/{$this->company->id}/receipt-settings", [
                'receipt_logo' => 'storage/logos/nour.png',
            ]);

        $this->assertApiValidationErrors($response, ['receipt_logo']);
    }

    public function test_a_cashier_cannot_change_the_receipt_settings(): void
    {
        $response = $this->actingAs($this->cashier(), 'sanctum')
            ->putJson("/api/v1/companies/{$this->company->id}/receipt-settings", [
                'receipt_subtotal_mode' => 'HT',
            ]);

        $response->assertForbidden();
        $this->assertSame(ReceiptSubtotalMode::Ttc, $this->company->refresh()->receipt_subtotal_mode);
    }

    public function test_a_second_company_keeps_its_own_receipt_settings(): void
    {
        $other = $this->secondCompany();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/companies/{$this->company->id}/receipt-settings", [
                'receipt_logo' => true,
                'receipt_subtotal_mode' => 'HT',
                'receipt_footer' => 'Café Nour',
            ])->assertOk();

        // The sibling company is untouched — data meaning, not a status code.
        $other->refresh();
        $this->assertSame(ReceiptSubtotalMode::Ttc, $other->receipt_subtotal_mode);
        $this->assertNotTrue($other->receipt_logo);
        $this->assertNotSame('Café Nour', $other->receipt_footer);

        // And it can hold the opposite settings at the same time.
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/companies/{$other->id}/receipt-settings", [
                'receipt_logo' => false,
                'receipt_subtotal_mode' => 'TTC',
                'receipt_footer' => 'Boutique 2',
            ])->assertOk();

        $this->assertSame(ReceiptSubtotalMode::Ht, $this->company->refresh()->receipt_subtotal_mode);
        $this->assertSame(ReceiptSubtotalMode::Ttc, $other->refresh()->receipt_subtotal_mode);
        $this->assertSame('Café Nour', $this->company->receipt_footer);
        $this->assertSame('Boutique 2', $other->receipt_footer);
    }

    public function test_receipt_settings_are_company_scoped_not_location_scoped(): void
    {
        // Second location (convention 09). Receipt display settings live on the
        // COMPANY: both locations of one company print the same settings, and a
        // second location must not create a second source of truth.
        // `locations.receipt_header/footer` exist and are read ONLY by the server
        // PDF (receipt.blade.php:335-339) — collapsing those three surfaces is
        // ticket E-5, deliberately not this lane.
        $first = $this->company->locations()->create([
            'name' => 'Boutique centre', 'code' => 'MAIN', 'type' => 'shop', 'is_default' => true,
        ]);
        $second = $this->company->locations()->create([
            'name' => 'Boutique gare', 'code' => 'GARE', 'type' => 'shop', 'is_default' => false,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/companies/{$this->company->id}/receipt-settings", [
                'receipt_subtotal_mode' => 'HT',
            ])->assertOk();

        foreach ([$first, $second] as $location) {
            $this->assertSame(
                ReceiptSubtotalMode::Ht,
                $location->refresh()->company->receipt_subtotal_mode,
                'every location of the company resolves the same display settings',
            );
        }
    }
```

If `Company::locations()` or the `locations` columns differ from the names used above, read the real shape at `apps/api/app/Modules/Company/Domain/Location.php` and `apps/api/database/migrations/tenant/2025_11_30_105000_create_locations_table.php` and use those — do not invent columns (CLAUDE.md rule 17).

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Company/ReceiptSettingsTest.php`
Expected: FAIL — `receipt_logo` and `receipt_subtotal_mode` are silently dropped (the round-trip assertions fail), and the two validation tests get `200` where they expect `422`.

- [ ] **Step 3: Add the rules**

`apps/api/app/Modules/Company/Presentation/Requests/UpdateReceiptSettingsRequest.php` — replace the `rules()` body (lines 21-30):

```php
        return [
            'receipt_header' => ['nullable', 'string', 'max:500'],
            'receipt_footer' => ['nullable', 'string', 'max:500'],
            'receipt_thank_you' => ['nullable', 'string', 'max:200'],
            'receipt_show_vat_breakdown' => ['sometimes', 'boolean'],
            'receipt_show_fiscal_info' => ['sometimes', 'boolean'],
            'receipt_show_payment_details' => ['sometimes', 'boolean'],
            'receipt_show_customer' => ['sometimes', 'boolean'],
            'auto_print_receipts' => ['sometimes', 'boolean'],
            // Lane E: the column existed since 2026-01-10 with NO writer at all
            // (Phase-1 §2 "Logo"), so the PDF's logo toggle could never be set.
            'receipt_logo' => ['sometimes', 'boolean'],
            'receipt_subtotal_mode' => ['sometimes', Rule::enum(ReceiptSubtotalMode::class)],
        ];
```

and add the imports at the top:

```php
use App\Modules\Company\Domain\Enums\ReceiptSubtotalMode;
use Illuminate\Validation\Rule;
```

Append to `messages()` (after the `receipt_thank_you.max` entry, line 42):

```php
            'receipt_subtotal_mode.Illuminate\Validation\Rules\Enum' => 'Receipt subtotal mode must be TTC or HT.',
```

- [ ] **Step 4: Echo the two fields in the write response**

`apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php` — in `updateReceiptSettings`, add to the `data` array after `'receipt_show_customer' => $company->receipt_show_customer,` (`:626`):

```php
                'receipt_subtotal_mode' => $company->receipt_subtotal_mode->value,
```

`'receipt_logo' => $company->receipt_logo,` is already at `:619`; with the Task-1 cast it now serialises as a boolean rather than a path string.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Company/ReceiptSettingsTest.php`
Expected: PASS — 12 tests.

Run: `cd apps/api && ./vendor/bin/pint --test app/Modules/Company tests/Feature/Company`
Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Company --level=8 --memory-limit=2G`
Expected: both PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/api/app/Modules/Company apps/api/tests/Feature/Company/ReceiptSettingsTest.php
git commit -m "feat(company): receipt-settings accepts receipt_logo and receipt_subtotal_mode (round-trip, cashier 403, second company)"
```

---

### Task 3: `ReceiptSettingsData` DTO for `GET pos-settings`, and the generated types

`getPOSSettings` (`CompanyController.php:575-597`) returns a hand-built array with no DTO, so the web hand-rolls `ReceiptSettingsResponse` (`ReceiptSettingsTab.tsx:64-77`) — a hand-rolled FE type beside no generated one, which convention 11 and rule 7 both forbid once a DTO is available.

The DTO also carries `has_logo`: the logo switch must be **disabled with a hint when the company has no `logo_path`** (spec §1.5), and `companies.logo_path` (`2025_11_30_104000_create_companies_table.php:55`) is not exposed by this endpoint today. `has_logo` is a derived boolean — the path itself is not leaked.

**Files:**
- Create: `apps/api/app/Modules/Company/Application/DTOs/ReceiptSettingsData.php`
- Modify: `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:575-597` (`getPOSSettings`)
- Modify (generated, committed): `packages/shared/types/generated.d.ts`
- Modify: `apps/api/tests/Feature/Company/ReceiptSettingsTest.php` (append)

**Interfaces:**
- Consumes: `ReceiptSubtotalMode` (Task 1).
- Produces:
  - `App\Modules\Company\Application\DTOs\ReceiptSettingsData` — `#[TypeScript]`, snake_case properties (convention 04 rules 1-2)
  - Generated `App.Modules.Company.Application.DTOs.ReceiptSettingsData` and `App.Modules.Company.Domain.Enums.ReceiptSubtotalMode`
  - `GET /api/v1/companies/{companyId}/pos-settings` returns that shape under `data`

- [ ] **Step 1: Write the failing test**

Append to `apps/api/tests/Feature/Company/ReceiptSettingsTest.php`:

```php
    public function test_the_read_endpoint_returns_the_full_settings_shape(): void
    {
        $this->company->update([
            'receipt_logo' => true,
            'receipt_subtotal_mode' => ReceiptSubtotalMode::Ht,
            'receipt_footer' => 'A bientôt',
            'logo_path' => 'storage/logos/nour.png',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/companies/{$this->company->id}/pos-settings");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'auto_print_receipts',
                    'receipt_logo',
                    'has_logo',
                    'receipt_header',
                    'receipt_footer',
                    'receipt_thank_you',
                    'receipt_show_vat_breakdown',
                    'receipt_show_fiscal_info',
                    'receipt_show_payment_details',
                    'receipt_show_customer',
                    'receipt_subtotal_mode',
                ],
            ])
            ->assertJsonPath('data.receipt_logo', true)
            ->assertJsonPath('data.has_logo', true)
            ->assertJsonPath('data.receipt_subtotal_mode', 'HT')
            ->assertJsonPath('data.receipt_footer', 'A bientôt');
    }

    public function test_has_logo_is_false_when_the_company_uploaded_none(): void
    {
        $this->company->update(['logo_path' => null]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/companies/{$this->company->id}/pos-settings")
            ->assertOk()
            ->assertJsonPath('data.has_logo', false);
    }

    public function test_has_logo_is_false_for_a_blank_path(): void
    {
        $this->company->update(['logo_path' => '   ']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/companies/{$this->company->id}/pos-settings")
            ->assertOk()
            ->assertJsonPath('data.has_logo', false);
    }

    public function test_the_read_endpoint_is_scoped_to_the_requested_company(): void
    {
        $other = $this->secondCompany();
        $other->update(['receipt_subtotal_mode' => ReceiptSubtotalMode::Ht]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/companies/{$this->company->id}/pos-settings")
            ->assertOk()
            ->assertJsonPath('data.receipt_subtotal_mode', 'TTC');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/companies/{$other->id}/pos-settings")
            ->assertOk()
            ->assertJsonPath('data.receipt_subtotal_mode', 'HT');
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit --filter test_the_read_endpoint_returns_the_full_settings_shape tests/Feature/Company/ReceiptSettingsTest.php`
Expected: FAIL — `has_logo` and `receipt_subtotal_mode` are missing from the response structure.

- [ ] **Step 3: Write the DTO**

`apps/api/app/Modules/Company/Application/DTOs/ReceiptSettingsData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Company\Application\DTOs;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\ReceiptSubtotalMode;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Receipt display settings as the back office reads them
 * (`GET companies/{companyId}/pos-settings`).
 *
 * The endpoint returned a hand-built array before Lane E, so `apps/web`
 * hand-rolled its own response interface beside no generated type
 * (ReceiptSettingsTab.tsx:64-77) — a duplicate surface for one concept.
 * This DTO is the single declaration both sides read (rule 7, convention 11).
 */
#[TypeScript]
final class ReceiptSettingsData extends Data
{
    public function __construct(
        public bool $auto_print_receipts,
        /** Whether the logo PRINTS on the ticket. */
        public bool $receipt_logo,
        /**
         * Whether the company HAS a logo to print. The switch above is disabled
         * without one; the path itself is never exposed here.
         */
        public bool $has_logo,
        public ?string $receipt_header,
        public ?string $receipt_footer,
        public ?string $receipt_thank_you,
        public bool $receipt_show_vat_breakdown,
        public bool $receipt_show_fiscal_info,
        public bool $receipt_show_payment_details,
        public bool $receipt_show_customer,
        public ReceiptSubtotalMode $receipt_subtotal_mode,
    ) {}

    public static function fromCompany(Company $company): self
    {
        $logoPath = $company->logo_path;

        return new self(
            auto_print_receipts: (bool) $company->auto_print_receipts,
            receipt_logo: (bool) $company->receipt_logo,
            has_logo: $logoPath !== null && trim($logoPath) !== '',
            receipt_header: $company->receipt_header,
            receipt_footer: $company->receipt_footer,
            receipt_thank_you: $company->receipt_thank_you,
            receipt_show_vat_breakdown: (bool) $company->receipt_show_vat_breakdown,
            receipt_show_fiscal_info: (bool) $company->receipt_show_fiscal_info,
            receipt_show_payment_details: (bool) $company->receipt_show_payment_details,
            receipt_show_customer: (bool) $company->receipt_show_customer,
            receipt_subtotal_mode: $company->receipt_subtotal_mode,
        );
    }
}
```

- [ ] **Step 4: Use it in the controller**

`apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php` — replace the body of `getPOSSettings` (lines 575-597) after the `firstOrFail()`:

```php
        return response()->json([
            'data' => ReceiptSettingsData::fromCompany($company),
        ]);
```

and add the import:

```php
use App\Modules\Company\Application\DTOs\ReceiptSettingsData;
```

The route itself (`apps/api/app/Modules/Company/routes.php:43-44`) is **not** touched: its missing permission gate is DEV-QA-089 / ticket E-4, out of this lane.

- [ ] **Step 5: Regenerate the shared types**

```bash
cd apps/api && CACHE_STORE=array php artisan typescript:transform
```

Confirm `packages/shared/types/generated.d.ts` now contains, in `declare namespace App.Modules.Company.Domain.Enums`:

```ts
export type ReceiptSubtotalMode = 'TTC' | 'HT';
```

and a `ReceiptSettingsData` interface in `App.Modules.Company.Application.DTOs`. Never hand-edit the file — if a field is wrong, fix the PHP and regenerate.

Re-point the shared alias so the generated enum is the source (rule 7). In `packages/shared/src/receipt/types.ts`, replace the `ReceiptSubtotalMode` declaration with:

```ts
/**
 * Mirrors `App\Modules\Company\Domain\Enums\ReceiptSubtotalMode`. The generated
 * ambient type is the source (CLAUDE.md rule 7); this alias exists so the
 * dependency-free shared receipt package does not import a `.d.ts` that only
 * the two apps have on their typeRoots.
 */
export type ReceiptSubtotalMode = App.Modules.Company.Domain.Enums.ReceiptSubtotalMode;
```

If `packages/shared`'s own `tsconfig.json` cannot see the ambient global (it `include`s `types/**/*`, so it can), keep the literal union and add a compile-time equivalence assertion in `apps/web/src/features/settings/types.ts`:

```ts
import type { ReceiptSubtotalMode as SharedSubtotalMode } from '@autoerp/shared/src/receipt'

export type ReceiptSettings = App.Modules.Company.Application.DTOs.ReceiptSettingsData
export type ReceiptSubtotalMode = App.Modules.Company.Domain.Enums.ReceiptSubtotalMode

// Fails to compile if the two ever drift.
const _subtotalModesAgree: SharedSubtotalMode = 'HT' satisfies ReceiptSubtotalMode
void _subtotalModesAgree
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Company/ReceiptSettingsTest.php` → PASS (16 tests).
Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Company --level=8 --memory-limit=2G` → PASS.
Run: `pnpm --filter @autoerp/web typecheck` → PASS.
Run: `git diff --stat packages/shared/types/generated.d.ts` — must be non-empty and contain only the two additions.

- [ ] **Step 7: Commit**

```bash
git add apps/api/app/Modules/Company apps/api/tests/Feature/Company/ReceiptSettingsTest.php \
        packages/shared/types/generated.d.ts packages/shared/src/receipt/types.ts \
        apps/web/src/features/settings/types.ts
git commit -m "feat(company): ReceiptSettingsData DTO for GET pos-settings + regenerated shared types"
```

---

### Task 4: `/company/config` exposes `logo` and `subtotal_mode` to the POS device

`CompanyConfigController::__invoke` builds `receipt_visibility` at `apps/api/app/Http/Controllers/Api/CompanyConfigController.php:91-96` with the four `show_*` flags. PR 1's `resolveReceiptDisplaySettings` already reads `logo` and `subtotal_mode` from that block and defaults them when absent; this task actually sends them. No new endpoint (ruling 4).

**Files:**
- Modify: `apps/api/app/Http/Controllers/Api/CompanyConfigController.php:91-96`
- Modify: `apps/api/tests/Feature/Company/ReceiptSettingsTest.php` (append)

**Interfaces:**
- Consumes: `ReceiptSubtotalMode` (Task 1).
- Produces: `GET /api/v1/company/config` → `data.receipt_visibility.logo` (bool) and `data.receipt_visibility.subtotal_mode` (`'TTC' | 'HT'`), matching the device-side `ReceiptVisibility` type PR 1 extended (`apps/pos/src/types/companyConfig.ts`).

- [ ] **Step 1: Write the failing test**

Append to `apps/api/tests/Feature/Company/ReceiptSettingsTest.php`:

```php
    public function test_the_device_config_carries_the_two_new_display_settings(): void
    {
        $this->company->update([
            'receipt_logo' => true,
            'receipt_subtotal_mode' => ReceiptSubtotalMode::Ht,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', (string) $this->company->id)
            ->getJson('/api/v1/company/config')
            ->assertOk()
            ->assertJsonPath('data.receipt_visibility.logo', true)
            ->assertJsonPath('data.receipt_visibility.subtotal_mode', 'HT')
            ->assertJsonPath('data.receipt_visibility.show_vat_breakdown', true);
    }

    public function test_the_device_config_defaults_match_todays_printed_ticket(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', (string) $this->company->id)
            ->getJson('/api/v1/company/config')
            ->assertOk()
            ->assertJsonPath('data.receipt_visibility.logo', false)
            ->assertJsonPath('data.receipt_visibility.subtotal_mode', 'TTC');
    }
```

If the company-context header used by `/company/config` differs, copy the pattern from the existing `tests/Feature/CompanyConfig` class rather than guessing.

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit --filter test_the_device_config_carries_the_two_new_display_settings tests/Feature/Company/ReceiptSettingsTest.php`
Expected: FAIL — `data.receipt_visibility.logo` does not exist.

- [ ] **Step 3: Extend the payload**

`apps/api/app/Http/Controllers/Api/CompanyConfigController.php` — replace lines 91-96:

```php
                'receipt_visibility' => [
                    'show_vat_breakdown' => (bool) ($company?->receipt_show_vat_breakdown ?? true),
                    'show_fiscal_info' => (bool) ($company?->receipt_show_fiscal_info ?? true),
                    'show_payment_details' => (bool) ($company?->receipt_show_payment_details ?? true),
                    'show_customer' => (bool) ($company?->receipt_show_customer ?? true),
                    // Lane E display settings. Both default to today's printed
                    // ticket, so a device on an older build — or with no company
                    // in context — keeps printing exactly what it prints now.
                    'logo' => (bool) ($company?->receipt_logo ?? false),
                    'subtotal_mode' => ($company?->receipt_subtotal_mode ?? ReceiptSubtotalMode::Ttc)->value,
                ],
```

and add the import:

```php
use App\Modules\Company\Domain\Enums\ReceiptSubtotalMode;
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Company/ReceiptSettingsTest.php` → PASS (18 tests).
Run: `cd apps/api && ./vendor/bin/pint --test app/Http/Controllers/Api/CompanyConfigController.php` → PASS.
Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Http/Controllers/Api --level=8 --memory-limit=2G` → PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Http/Controllers/Api/CompanyConfigController.php \
        apps/api/tests/Feature/Company/ReceiptSettingsTest.php
git commit -m "feat(company-config): /company/config receipt_visibility exposes logo and subtotal_mode"
```

---

### Task 5: i18n keys for the two controls and the preview — `fr`, `en`, `ar`

Rule 11: no hardcoded user-facing string. The `settings` namespace already has a `receipt` section (`apps/web/src/locales/fr/settings.json` → `receipt.{title,sections,fields,descriptions,placeholders,help,legal,messages}`), so the new keys extend it rather than starting a new one (convention 11).

Arabic is written right-to-left; use logical properties in the component (`ms-`/`me-`, not `ml-`/`mr-`) — see `.claude/context/i18n.md`.

**Files:**
- Modify: `apps/web/src/locales/fr/settings.json` (`receipt` section)
- Modify: `apps/web/src/locales/en/settings.json` (`receipt` section)
- Modify: `apps/web/src/locales/ar/settings.json` (`receipt` section)

**Interfaces:**
- Produces these exact keys, used verbatim by Tasks 6 and 7:
  `settings:receipt.sections.preview` · `settings:receipt.fields.logo` · `settings:receipt.descriptions.logo` · `settings:receipt.logoMissing` · `settings:receipt.fields.subtotalMode` · `settings:receipt.descriptions.subtotalMode` · `settings:receipt.subtotalMode.ttc` · `settings:receipt.subtotalMode.ht` · `settings:receipt.subtotalMode.ttcHint` · `settings:receipt.subtotalMode.htHint` · `settings:receipt.preview.title` · `settings:receipt.preview.description` · `settings:receipt.preview.paperWidth` · `settings:receipt.preview.paper80` · `settings:receipt.preview.paper58` · `settings:receipt.preview.unsavedWidthHint` · `settings:receipt.preview.logoPlaceholder` · `settings:receipt.preview.qrLabel` · `settings:receipt.preview.cut` · `settings:receipt.preview.sampleNotice`

- [ ] **Step 1: Add the French keys**

In `apps/web/src/locales/fr/settings.json`, inside `receipt`: add `"preview": "Aperçu du ticket"` to `sections`; add to `fields`, `descriptions`, and add the two new sub-objects:

```json
      "fields": {
        "logo": "Imprimer le logo",
        "subtotalMode": "Affichage du sous-total"
      },
      "descriptions": {
        "logo": "Afficher le logo de la société en haut du ticket.",
        "subtotalMode": "Choisissez si le sous-total du ticket est présenté TTC ou HT. Le TOTAL et les montants encaissés ne changent pas."
      },
      "logoMissing": "Aucun logo n'a été téléversé pour cette société. Ajoutez-en un dans l'onglet Société pour activer cette option.",
      "subtotalMode": {
        "ttc": "TTC (toutes taxes comprises)",
        "ht": "HT (hors taxes)",
        "ttcHint": "Sous-total TTC avant remise, puis Remise, la ventilation de TVA et TOTAL.",
        "htHint": "Sous-total HT et Remise HT, puis la ventilation de TVA et TOTAL TTC."
      },
      "preview": {
        "title": "Aperçu du ticket",
        "description": "Rendu du ticket client tel que l'imprimante thermique le met en page.",
        "paperWidth": "Largeur du papier",
        "paper80": "80 mm (42 colonnes)",
        "paper58": "58 mm (32 colonnes)",
        "unsavedWidthHint": "La largeur est réglée sur chaque caisse ; ce choix ne sert qu'à l'aperçu et n'est pas enregistré.",
        "logoPlaceholder": "LOGO",
        "qrLabel": "QR",
        "cut": "découpe",
        "sampleNotice": "Ticket d'exemple — les montants sont fictifs."
      }
```

`fields` and `descriptions` already exist: **merge** the new entries into them, do not replace the objects.

- [ ] **Step 2: Add the English keys**

In `apps/web/src/locales/en/settings.json`, same structure:

```json
      "fields": {
        "logo": "Print the logo",
        "subtotalMode": "Subtotal display"
      },
      "descriptions": {
        "logo": "Show the company logo at the top of the receipt.",
        "subtotalMode": "Choose whether the receipt subtotal is stated tax-inclusive or tax-exclusive. The TOTAL and the amounts taken are unchanged."
      },
      "logoMissing": "No logo has been uploaded for this company. Add one in the Company tab to enable this option.",
      "subtotalMode": {
        "ttc": "Tax-inclusive (TTC)",
        "ht": "Tax-exclusive (HT)",
        "ttcHint": "Tax-inclusive subtotal before discount, then Discount, the VAT breakdown and TOTAL.",
        "htHint": "Tax-exclusive subtotal and discount, then the VAT breakdown and the tax-inclusive TOTAL."
      },
      "preview": {
        "title": "Receipt preview",
        "description": "The customer receipt as the thermal printer lays it out.",
        "paperWidth": "Paper width",
        "paper80": "80 mm (42 columns)",
        "paper58": "58 mm (32 columns)",
        "unsavedWidthHint": "Paper width is set on each till; this choice only affects the preview and is not saved.",
        "logoPlaceholder": "LOGO",
        "qrLabel": "QR",
        "cut": "cut",
        "sampleNotice": "Sample receipt — the amounts are fictitious."
      }
```

Plus `"preview": "Receipt preview"` inside `sections`.

- [ ] **Step 3: Add the Arabic keys**

In `apps/web/src/locales/ar/settings.json`, same structure, plus `"preview": "معاينة الإيصال"` inside `sections`:

```json
      "fields": {
        "logo": "طباعة الشعار",
        "subtotalMode": "عرض المجموع الفرعي"
      },
      "descriptions": {
        "logo": "إظهار شعار الشركة في أعلى الإيصال.",
        "subtotalMode": "اختر عرض المجموع الفرعي شاملاً الضريبة أو بدونها. لا يتغير الإجمالي ولا المبالغ المحصّلة."
      },
      "logoMissing": "لم يتم رفع أي شعار لهذه الشركة. أضف شعاراً من تبويب الشركة لتفعيل هذا الخيار.",
      "subtotalMode": {
        "ttc": "شامل الضريبة (TTC)",
        "ht": "بدون ضريبة (HT)",
        "ttcHint": "المجموع الفرعي شاملاً الضريبة قبل الخصم، ثم الخصم وتفصيل الضريبة والإجمالي.",
        "htHint": "المجموع الفرعي والخصم بدون ضريبة، ثم تفصيل الضريبة والإجمالي شاملاً الضريبة."
      },
      "preview": {
        "title": "معاينة الإيصال",
        "description": "إيصال العميل كما تطبعه الطابعة الحرارية.",
        "paperWidth": "عرض الورق",
        "paper80": "٨٠ مم (٤٢ عموداً)",
        "paper58": "٥٨ مم (٣٢ عموداً)",
        "unsavedWidthHint": "يُضبط عرض الورق على كل صندوق؛ هذا الخيار للمعاينة فقط ولا يُحفظ.",
        "logoPlaceholder": "شعار",
        "qrLabel": "رمز QR",
        "cut": "قص",
        "sampleNotice": "إيصال نموذجي — المبالغ افتراضية."
      }
```

- [ ] **Step 4: Verify completeness**

Run: `pnpm --filter @autoerp/web audit:i18n:local`
Expected: PASS — no key present in one locale and missing in another.

```bash
node -e "for (const l of ['fr','en','ar']) { const d = require('./apps/web/src/locales/'+l+'/settings.json'); console.log(l, Object.keys(d.receipt.preview).length, Object.keys(d.receipt.subtotalMode).length) }"
```
Expected: `10 4` on all three lines.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/locales
git commit -m "feat(settings-i18n): receipt logo, subtotal-mode and preview keys in fr/en/ar"
```

---

### Task 6: The two new controls in the existing receipt-settings form

`ReceiptSettingsTab.tsx` already carries the RHF + zod form (`:38-59` schema, `:137-165` `useForm` + reset, `:184-195` mutation, `:191` the `PUT`) and the permission mirror `canEdit = hasPermission('settings.update')` (`:110-115`, F1 2026-08-02 ruling). The two controls join that **same** form — one submit, one PUT, one dirty state.

**Files:**
- Modify: `apps/web/src/features/settings/components/ReceiptSettingsTab.tsx:38-59` (schema), `:64-77` (delete the hand-rolled response interface), `:127-136` (query), `:137-165` (defaults), `:155-170` (reset), `:320-345` (visibility card), and the new Printing-card block
- Create: `apps/web/src/features/settings/components/__tests__/ReceiptSettingsTab.test.tsx`

**Interfaces:**
- Consumes: `ReceiptSettings` / `ReceiptSubtotalMode` from `apps/web/src/features/settings/types.ts` (Task 3); the i18n keys (Task 5); `Toggle` and `Radio` atoms (`apps/web/src/components/atoms`).
- Produces: `ReceiptSettingsFormData` gains `receipt_logo: boolean` and `receipt_subtotal_mode: ReceiptSubtotalMode`; both are sent by the existing mutation. The form's `watch()` output is what Task 7's preview consumes.

- [ ] **Step 1: Write the failing test**

`apps/web/src/features/settings/components/__tests__/ReceiptSettingsTab.test.tsx` — follow the mocking style of the existing web component tests (see any `apps/web/src/features/**/__tests__/*.test.tsx` for the `QueryClientProvider` + `api` mock pattern; do not invent a new harness):

```tsx
import { describe, expect, it } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ReceiptSettingsTab } from '../ReceiptSettingsTab'
import { renderWithProviders, mockGet, mockPut } from '../../../../test/harness'

const settings = {
  auto_print_receipts: false,
  receipt_logo: false,
  has_logo: true,
  receipt_header: null,
  receipt_footer: null,
  receipt_thank_you: null,
  receipt_show_vat_breakdown: true,
  receipt_show_fiscal_info: true,
  receipt_show_payment_details: true,
  receipt_show_customer: true,
  receipt_subtotal_mode: 'TTC' as const,
}

describe('ReceiptSettingsTab — logo switch', () => {
  it('is enabled and sends receipt_logo when the company has a logo', async () => {
    mockGet('/companies/:id/pos-settings', settings)
    const put = mockPut('/companies/:id/receipt-settings')
    renderWithProviders(<ReceiptSettingsTab />)

    const toggle = await screen.findByRole('switch', { name: /imprimer le logo/i })
    expect(toggle).toBeEnabled()

    await userEvent.click(toggle)
    await userEvent.click(screen.getByRole('button', { name: /enregistrer/i }))

    await waitFor(() => {
      expect(put).toHaveBeenCalledWith(
        expect.objectContaining({ receipt_logo: true, receipt_subtotal_mode: 'TTC' }),
      )
    })
  })

  it('is disabled with a hint when the company has no logo', async () => {
    mockGet('/companies/:id/pos-settings', { ...settings, has_logo: false })
    renderWithProviders(<ReceiptSettingsTab />)

    expect(await screen.findByRole('switch', { name: /imprimer le logo/i })).toBeDisabled()
    expect(screen.getByText(/aucun logo n'a été téléversé/i)).toBeInTheDocument()
  })
})

describe('ReceiptSettingsTab — subtotal mode', () => {
  it('pre-selects the saved mode and sends the other one when switched', async () => {
    mockGet('/companies/:id/pos-settings', { ...settings, receipt_subtotal_mode: 'HT' })
    const put = mockPut('/companies/:id/receipt-settings')
    renderWithProviders(<ReceiptSettingsTab />)

    expect(await screen.findByRole('radio', { name: /HT \(hors taxes\)/i })).toBeChecked()

    await userEvent.click(screen.getByRole('radio', { name: /TTC \(toutes taxes comprises\)/i }))
    await userEvent.click(screen.getByRole('button', { name: /enregistrer/i }))

    await waitFor(() => {
      expect(put).toHaveBeenCalledWith(expect.objectContaining({ receipt_subtotal_mode: 'TTC' }))
    })
  })

  it('shows the hint that matches the selected mode', async () => {
    mockGet('/companies/:id/pos-settings', settings)
    renderWithProviders(<ReceiptSettingsTab />)

    expect(await screen.findByText(/sous-total ttc avant remise/i)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('radio', { name: /HT \(hors taxes\)/i }))
    expect(screen.getByText(/sous-total ht et remise ht/i)).toBeInTheDocument()
  })
})

describe('ReceiptSettingsTab — permissions', () => {
  it('disables both new controls for a user without settings.update', async () => {
    mockGet('/companies/:id/pos-settings', settings)
    renderWithProviders(<ReceiptSettingsTab />, { permissions: ['settings.view'] })

    expect(await screen.findByRole('switch', { name: /imprimer le logo/i })).toBeDisabled()
    expect(screen.getByRole('radio', { name: /HT \(hors taxes\)/i })).toBeDisabled()
    expect(screen.getByRole('button', { name: /enregistrer/i })).toBeDisabled()
  })
})
```

If `apps/web/src/test/harness` does not export `renderWithProviders` / `mockGet` / `mockPut`, use whatever the neighbouring feature tests use and keep the assertions identical.

- [ ] **Step 2: Run the test to verify it fails**

Run: `pnpm --filter @autoerp/web vitest run src/features/settings/components/__tests__/ReceiptSettingsTab.test.tsx`
Expected: FAIL — no switch and no radios are rendered.

- [ ] **Step 3: Extend the schema and the types**

`apps/web/src/features/settings/components/ReceiptSettingsTab.tsx`:

- append to the zod object (after `auto_print_receipts: z.boolean(),`, line 58):

```ts
  receipt_logo: z.boolean(),
  receipt_subtotal_mode: z.enum(['TTC', 'HT']),
```

- **delete** the hand-rolled `ReceiptSettingsResponse` interface (lines 64-77) and import the generated shape instead:

```ts
import type { ReceiptSettings } from '../types'
```

- the query (lines 127-136) becomes:

```ts
  const { data, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['receipt-settings']),
    queryFn: async () => {
      const response = await api.get<{ data: ReceiptSettings }>(
        `/companies/${currentCompany?.id ?? ''}/pos-settings`
      )
      return response.data.data
    },
    enabled: tenantId !== null && companyId !== null && !!currentCompany?.id,
  })
```

- add to `defaultValues` (line 155):

```ts
      receipt_logo: false,
      receipt_subtotal_mode: 'TTC',
```

- add to the `reset({...})` call in the load effect (line 169):

```ts
        receipt_logo: data.receipt_logo,
        receipt_subtotal_mode: data.receipt_subtotal_mode,
```

- fix the stale invalidation at line 187 so the refetch actually targets the scoped key:

```ts
      await queryClient.invalidateQueries({ queryKey: tenantScopedKey(['receipt-settings']) })
```

- [ ] **Step 4: Render the controls**

Add, inside the existing "Printing Options" card (after the `auto_print_receipts` `ToggleField`, line ~340):

```tsx
          {/* Logo — the column has had no writer since 2026-01-10 (Phase-1 §2). */}
          <div className="flex items-start gap-3">
            <div className="flex h-6 items-center">
              <Controller
                name="receipt_logo"
                control={control}
                render={({ field }) => (
                  <Toggle
                    id="receipt_logo"
                    aria-label={t('settings:receipt.fields.logo')}
                    checked={field.value}
                    onChange={(e) => { field.onChange(e.target.checked) }}
                    ref={field.ref}
                    disabled={!canEdit || !hasLogo}
                  />
                )}
              />
            </div>
            <div className="flex-1">
              <label
                htmlFor="receipt_logo"
                className={cn('text-sm font-medium', hasLogo ? textColors.secondary : textColors.disabled)}
              >
                {t('settings:receipt.fields.logo')}
              </label>
              <p className={tokens.helperText.base}>{t('settings:receipt.descriptions.logo')}</p>
              {!hasLogo && (
                <div className={cn('mt-1 flex items-center gap-1 text-xs', textColors.warningDark)}>
                  <Info className="h-3.5 w-3.5" />
                  <span>{t('settings:receipt.logoMissing')}</span>
                </div>
              )}
            </div>
          </div>

          {/* Subtotal display — TTC (today) or HT (owner ruling 7). */}
          <fieldset className="border-0 p-0">
            <legend className={tokens.label.base}>{t('settings:receipt.fields.subtotalMode')}</legend>
            <p className={tokens.helperText.base}>{t('settings:receipt.descriptions.subtotalMode')}</p>
            <div className="mt-2 space-y-2">
              {(['TTC', 'HT'] as const).map((mode) => (
                <div key={mode} className="flex items-start gap-3">
                  <div className="flex h-6 items-center">
                    <Radio
                      id={`receipt_subtotal_mode_${mode}`}
                      value={mode}
                      {...register('receipt_subtotal_mode')}
                      disabled={!canEdit}
                    />
                  </div>
                  <label
                    htmlFor={`receipt_subtotal_mode_${mode}`}
                    className={cn('text-sm font-medium', textColors.secondary)}
                  >
                    {mode === 'TTC'
                      ? t('settings:receipt.subtotalMode.ttc')
                      : t('settings:receipt.subtotalMode.ht')}
                  </label>
                </div>
              ))}
            </div>
            <p className={tokens.helperText.base}>
              {subtotalMode === 'HT'
                ? t('settings:receipt.subtotalMode.htHint')
                : t('settings:receipt.subtotalMode.ttcHint')}
            </p>
          </fieldset>
```

Supporting declarations, added beside the existing `watch` calls (line ~212):

```tsx
  const subtotalMode = watch('receipt_subtotal_mode')
  const hasLogo = data?.has_logo ?? false
```

and `control` pulled out of `useForm` (line 139):

```tsx
    control,
```

New imports:

```tsx
import { Controller } from 'react-hook-form'
import { Radio, Toggle } from '../../../components/atoms'
```

`Toggle` is strictly controlled and must be wired with `<Controller>`, never `register()` — see its doc comment at `apps/web/src/components/atoms/Toggle/Toggle.tsx:19-44`. `Radio` works with `register()` plus `value`, per its own example at `Radio/Radio.tsx:24`.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `pnpm --filter @autoerp/web vitest run src/features/settings` → PASS.
Run: `pnpm --filter @autoerp/web typecheck` → PASS.
Run: `pnpm --filter @autoerp/web lint` → PASS (includes `audit:keys`, `audit:design-system`, `audit:i18n:local` and the ESLint rule tests).

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/features/settings
git commit -m "feat(settings): logo switch and TTC/HT subtotal radio in the receipt settings form"
```

---

### Task 7: `ReceiptPreview` — the same document the printer gets, rendered on a monospace grid

The preview is faithful **by construction**: it calls the same `buildReceiptDoc` the POS device calls (PR 1) and pads `two-column` / `three-column` rows with the same `padColumns` helper the Rust encoder mirrors. It is not a fourth template — that is the whole point of moving the layout to `packages/shared` (spec §1, Phase-1 §5 "Consequence for the preview"). `apps/web/src/features/pos/RECEIPT_PRINTING_INTEGRATION.md:207` has carried this as a Phase-2 TODO since inception.

Paper width is a **device** fact (`printerStore.ts:31,64`, localStorage, per till) that the server cannot see — ticket E-6. The preview therefore carries an **unsaved** 80/58 mm switch, defaulting to 80 mm, with a hint saying so.

**Files:**
- Create: `apps/web/src/features/settings/components/ReceiptPreview.tsx`
- Create: `apps/web/src/features/settings/components/__tests__/ReceiptPreview.test.tsx`
- Modify: `apps/web/src/features/settings/components/ReceiptSettingsTab.tsx` (mount the preview; wrap the form in the two-column layout)

**Interfaces:**
- Consumes: `buildReceiptDoc`, `padColumns`, `sampleReceipt`, `ReceiptColumns`, `ReceiptDisplaySettings`, `ReceiptSegment` from `@autoerp/shared/src/receipt` (PR 1); the i18n keys (Task 5).
- Produces:

```tsx
interface ReceiptPreviewProps {
  display: ReceiptDisplaySettings
  columns: ReceiptColumns
}
export function ReceiptPreview({ display, columns }: ReceiptPreviewProps): JSX.Element
```

- [ ] **Step 1: Write the failing test**

`apps/web/src/features/settings/components/__tests__/ReceiptPreview.test.tsx`:

```tsx
import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { ReceiptPreview } from '../ReceiptPreview'
import type { ReceiptDisplaySettings } from '@autoerp/shared/src/receipt'

const display: ReceiptDisplaySettings = {
  logo: false,
  subtotalMode: 'TTC',
  showVatBreakdown: true,
  showFiscalInfo: true,
  showPaymentDetails: true,
  showCustomer: true,
}

function rows(): string[] {
  return screen
    .getByTestId('receipt-preview-body')
    .textContent!.split('\n')
}

describe('ReceiptPreview', () => {
  it('renders the fixture ticket: company, accented lines and the formatted total', () => {
    render(<ReceiptPreview display={display} columns={42} />)
    const text = screen.getByTestId('receipt-preview-body').textContent ?? ''
    expect(text).toContain('Café Nour')
    expect(text).toContain('Café crème')
    expect(text).toContain('Garçon')
    expect(text).toContain('11.000 TND')
    expect(text).toContain('10.000 TND')
  })

  it('pads every column row to exactly the configured width', () => {
    render(<ReceiptPreview display={display} columns={42} />)
    const widths = new Set(rows().filter((row) => row.includes('TOTAL')).map((row) => row.length))
    expect(widths).toEqual(new Set([42]))
  })

  it('re-lays the ticket at 32 columns for 58 mm paper', () => {
    render(<ReceiptPreview display={display} columns={32} />)
    const widths = new Set(rows().filter((row) => row.includes('TOTAL')).map((row) => row.length))
    expect(widths).toEqual(new Set([32]))
  })

  it('switching to HT restates the subtotal block and keeps the total tax-inclusive', () => {
    render(<ReceiptPreview display={{ ...display, subtotalMode: 'HT' }} columns={42} />)
    const text = screen.getByTestId('receipt-preview-body').textContent ?? ''
    expect(text).toContain('Sous-total HT :')
    expect(text).toContain('10.092 TND')
    expect(text).toContain('Remise HT :')
    expect(text).toContain('10.000 TND')
    expect(text).not.toContain('Sous-total :')
  })

  it('shows a labelled logo placeholder only when the logo is on', () => {
    const { rerender } = render(<ReceiptPreview display={display} columns={42} />)
    expect(screen.queryByTestId('receipt-preview-logo')).not.toBeInTheDocument()

    rerender(<ReceiptPreview display={{ ...display, logo: true }} columns={42} />)
    expect(screen.getByTestId('receipt-preview-logo')).toHaveTextContent('LOGO')
  })

  it('draws the return QR as a labelled box with its caption', () => {
    render(<ReceiptPreview display={display} columns={42} />)
    const qr = screen.getByTestId('receipt-preview-qr')
    expect(qr).toHaveTextContent('QR')
    expect(qr).toHaveTextContent('Scanner pour retour / échange')
  })

  it('prints the matricule once — the preview shows the fix, not the old ticket', () => {
    render(<ReceiptPreview display={display} columns={42} />)
    const text = screen.getByTestId('receipt-preview-body').textContent ?? ''
    expect(text.match(/1234567\/A\/M\/000/g)).toHaveLength(1)
    expect(text).not.toContain('N° TVA :')
  })

  it('hides the VAT ventilation when the company switched it off', () => {
    render(<ReceiptPreview display={{ ...display, showVatBreakdown: false }} columns={42} />)
    const text = screen.getByTestId('receipt-preview-body').textContent ?? ''
    expect(text).toContain('TVA :')
    expect(text).not.toContain('Base HT')
  })

  it('marks the sample as fictitious and shows the cut line', () => {
    render(<ReceiptPreview display={display} columns={42} />)
    expect(screen.getByText(/montants sont fictifs/i)).toBeInTheDocument()
    expect(screen.getByTestId('receipt-preview-cut')).toBeInTheDocument()
  })
})
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `pnpm --filter @autoerp/web vitest run src/features/settings/components/__tests__/ReceiptPreview.test.tsx`
Expected: FAIL — `Cannot find module '../ReceiptPreview'`.

- [ ] **Step 3: Write the component**

`apps/web/src/features/settings/components/ReceiptPreview.tsx`:

```tsx
import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import {
  buildReceiptDoc,
  padColumns,
  type ReceiptColumns,
  type ReceiptDisplaySettings,
  type ReceiptSegment,
} from '@autoerp/shared/src/receipt'
import { sampleReceipt } from '@autoerp/shared/src/receipt/fixtures/sampleReceipt'
import { tokens, textColors, borderColors, semanticColorTokens } from '../../../lib/designTokens'
import { cn } from '../../../lib/utils'

interface ReceiptPreviewProps {
  /** Live form values, not the saved ones — the preview updates before saving. */
  display: ReceiptDisplaySettings
  /** 42 for 80 mm paper, 32 for 58 mm. Unsaved: paper width is per till (E-6). */
  columns: ReceiptColumns
}

/**
 * Live preview of the customer ticket.
 *
 * Faithful BY CONSTRUCTION: it renders the very `ReceiptDoc` the POS device
 * sends to the printer (`buildReceiptDoc`, `@autoerp/shared/src/receipt`) and
 * pads column rows with the same helper the Rust encoder mirrors
 * (`escpos.rs:213-271`). It is not a second template.
 *
 * Two segment kinds cannot be drawn as characters and get a labelled box
 * instead: `logo` (no ESC/POS raster command exists — ticket E-1) and `qr`.
 */
export function ReceiptPreview({ display, columns }: ReceiptPreviewProps) {
  const { t } = useTranslation(['settings'])

  const doc = useMemo(
    () =>
      buildReceiptDoc(sampleReceipt, display, {
        columns,
        cutMode: 'partial',
        footerText: '',
      }),
    [display, columns],
  )

  return (
    <div className={cn(tokens.card.base, 'space-y-3')}>
      <div>
        <h2 className={tokens.heading.section}>{t('settings:receipt.preview.title')}</h2>
        <p className={tokens.helperText.base}>{t('settings:receipt.preview.description')}</p>
      </div>

      <div
        className={cn(
          'overflow-x-auto rounded-md border p-4',
          borderColors.light,
          semanticColorTokens.surface.page,
        )}
      >
        <div
          data-testid="receipt-preview-body"
          className={cn('whitespace-pre font-mono text-[11px] leading-[1.35]', textColors.primary)}
          style={{ width: `${String(columns)}ch` }}
        >
          {doc.segments.map((segment, index) => (
            <PreviewRow key={index} segment={segment} columns={columns} t={t} />
          ))}
        </div>
      </div>

      <p className={tokens.helperText.base}>{t('settings:receipt.preview.sampleNotice')}</p>
    </div>
  )
}

function PreviewRow({
  segment,
  columns,
  t,
}: {
  segment: ReceiptSegment
  columns: ReceiptColumns
  t: ReturnType<typeof useTranslation>['t']
}) {
  const padded = padColumns(segment, columns)
  if (padded !== null) {
    return <div className={emphasisClass(segment)}>{padded}</div>
  }

  switch (segment.kind) {
    case 'text':
      return (
        <div className={cn(emphasisClass(segment), alignClass(segment.align))}>
          {segment.text === '' ? ' ' : segment.text}
        </div>
      )
    case 'separator':
      return <div>{(segment.char ?? '-').repeat(columns)}</div>
    case 'blank':
      return <div>{' '}</div>
    case 'feed':
      return (
        <div aria-hidden="true">
          {Array.from({ length: segment.lines }, () => ' ').join('\n')}
        </div>
      )
    case 'cut':
      return segment.mode === 'none' ? null : (
        <div
          data-testid="receipt-preview-cut"
          className={cn('my-1 border-t border-dashed text-center text-[10px]', borderColors.dark, textColors.disabled)}
        >
          {t('settings:receipt.preview.cut')}
        </div>
      )
    case 'qr':
      return (
        <div data-testid="receipt-preview-qr" className="my-1 text-center">
          {segment.label !== undefined && segment.label !== '' ? (
            <div className="text-[10px]">{segment.label}</div>
          ) : null}
          <div
            className={cn(
              'mx-auto flex h-16 w-16 items-center justify-center border text-[10px]',
              borderColors.dark,
              textColors.tertiary,
            )}
          >
            {t('settings:receipt.preview.qrLabel')}
          </div>
        </div>
      )
    case 'logo':
      return (
        <div
          data-testid="receipt-preview-logo"
          className={cn(
            'mx-auto my-1 flex h-10 w-24 items-center justify-center border border-dashed text-[10px]',
            borderColors.dark,
            textColors.disabled,
          )}
        >
          {t('settings:receipt.preview.logoPlaceholder')}
        </div>
      )
    case 'drawer-kick':
      return null
    default:
      return null
  }
}

function emphasisClass(segment: ReceiptSegment): string {
  const bold = 'bold' in segment && segment.bold === true ? 'font-bold' : ''
  const size = 'size' in segment ? segment.size : undefined
  const scale =
    size === 'double'
      ? 'text-[15px] leading-[1.2] tracking-wide'
      : size === 'double-height'
        ? 'text-[11px] leading-[2]'
        : size === 'double-width'
          ? 'tracking-[0.35em]'
          : ''
  return cn(bold, scale)
}

function alignClass(align: 'left' | 'center' | 'right' | undefined): string {
  return align === 'center' ? 'text-center' : align === 'right' ? 'text-end' : 'text-start'
}
```

`text-start` / `text-end` (not `text-left` / `text-right`) keep the preview correct under the Arabic RTL layout.

- [ ] **Step 4: Mount it beside the form**

In `apps/web/src/features/settings/components/ReceiptSettingsTab.tsx`:

- add the unsaved paper-width state and the display object derived from the live form values, beside the other `watch` calls:

```tsx
  const [previewColumns, setPreviewColumns] = useState<ReceiptColumns>(42)
  const watchedLogo = watch('receipt_logo')
  const watchedVat = watch('receipt_show_vat_breakdown')
  const watchedFiscal = watch('receipt_show_fiscal_info')
  const watchedPayments = watch('receipt_show_payment_details')
  const watchedCustomer = watch('receipt_show_customer')

  const previewDisplay: ReceiptDisplaySettings = useMemo(
    () => ({
      logo: watchedLogo,
      subtotalMode: subtotalMode,
      showVatBreakdown: watchedVat,
      showFiscalInfo: watchedFiscal,
      showPaymentDetails: watchedPayments,
      showCustomer: watchedCustomer,
    }),
    [watchedLogo, subtotalMode, watchedVat, watchedFiscal, watchedPayments, watchedCustomer],
  )
```

- wrap the returned `<form>` in the responsive two-column layout the spec asks for (preview on the right at ≥ lg, stacked below on smaller widths):

```tsx
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
      <form onSubmit={(e) => { void handleSubmit(onSubmit)(e) }} className="space-y-6">
        {/* … the existing cards, unchanged … */}
      </form>

      <aside className="space-y-3 lg:sticky lg:top-4 lg:self-start">
        <div>
          <label htmlFor="preview_paper_width" className={tokens.label.base}>
            {t('settings:receipt.preview.paperWidth')}
          </label>
          <Select
            id="preview_paper_width"
            value={String(previewColumns)}
            onChange={(e) => { setPreviewColumns(e.target.value === '32' ? 32 : 42) }}
          >
            <option value="42">{t('settings:receipt.preview.paper80')}</option>
            <option value="32">{t('settings:receipt.preview.paper58')}</option>
          </Select>
          <p className={tokens.helperText.base}>{t('settings:receipt.preview.unsavedWidthHint')}</p>
        </div>

        <ReceiptPreview display={previewDisplay} columns={previewColumns} />
      </aside>
    </div>
```

New imports:

```tsx
import { useMemo, useState } from 'react'
import type { ReceiptColumns, ReceiptDisplaySettings } from '@autoerp/shared/src/receipt'
import { Select } from '../../../components/atoms'
import { ReceiptPreview } from './ReceiptPreview'
```

(`useEffect` is already imported at line 1 — extend that import rather than adding a second one.)

The preview is visible to anyone who can read the tab (`settings.view`); only the mutation affordance is gated by `canEdit`, per the F1 2026-08-02 ruling recorded at `ReceiptSettingsTab.tsx:110-115`.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `pnpm --filter @autoerp/web vitest run src/features/settings` → PASS.
Run: `pnpm --filter @autoerp/web typecheck` → PASS.
Run: `pnpm --filter @autoerp/web lint` → PASS. `audit:design-system` treats hardcoded Tailwind colours as an **error** in a new file; if it flags anything in `ReceiptPreview.tsx`, replace the class with a token — do not baseline it.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/features/settings
git commit -m "feat(settings): ReceiptPreview renders the shared ReceiptDoc at 80/58 mm"
```

---

### Task 8: Playwright — change, preview, save, reload, revert; cashier denied; second company

The web e2e suite mocks the API with `page.route` (see `apps/web/e2e/company.spec.ts:33-60` and `apps/web/e2e/fixtures.ts:19-50`) rather than driving a live stack, so this spec is deterministic and needs no Docker.

**Files:**
- Create: `apps/web/e2e/settings/receipt-preview.spec.ts`

**Interfaces:**
- Consumes: the route `/settings/company` tab `receipt` (`apps/web/src/routes/index.tsx:2360-2369`, `CompanyPage.tsx:99,450-459,479`); the endpoints `GET /api/v1/companies/:id/pos-settings` and `PUT /api/v1/companies/:id/receipt-settings`; the i18n keys (Task 5).
- Produces: no exports.

- [ ] **Step 1: Write the spec**

`apps/web/e2e/settings/receipt-preview.spec.ts`:

```ts
import { expect, test } from '@playwright/test'

const baseSettings = {
  auto_print_receipts: false,
  receipt_logo: false,
  has_logo: true,
  receipt_header: null,
  receipt_footer: null,
  receipt_thank_you: null,
  receipt_show_vat_breakdown: true,
  receipt_show_fiscal_info: true,
  receipt_show_payment_details: true,
  receipt_show_customer: true,
  receipt_subtotal_mode: 'TTC',
}

/**
 * Stand up the receipt tab with a mutable in-memory settings row, so a save
 * followed by a reload reads back what was written (the round trip the spec
 * asks for) instead of a frozen fixture.
 */
async function openReceiptTab(
  page: import('@playwright/test').Page,
  options: { permissions?: string[]; companyId?: string; settings?: Record<string, unknown> } = {},
) {
  const companyId = options.companyId ?? 'company-1'
  const state: Record<string, unknown> = { ...baseSettings, ...options.settings }

  await page.route(`**/api/v1/companies/${companyId}/pos-settings`, (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: state }),
    }),
  )

  await page.route(`**/api/v1/companies/${companyId}/receipt-settings`, async (route) => {
    const body = route.request().postDataJSON() as Record<string, unknown>
    Object.assign(state, body)
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: state, message: 'Receipt settings updated successfully' }),
    })
  })

  // Auth + companies + permissions mocks, following e2e/fixtures.ts.
  await installAuthMocks(page, { companyId, permissions: options.permissions ?? ['settings.view', 'settings.update'] })

  await page.goto('/settings/company?tab=receipt')
  await expect(page.getByRole('heading', { name: /aperçu du ticket/i })).toBeVisible()
  return state
}

test.describe('receipt settings preview', () => {
  test('the preview updates before saving, and the saved value survives a reload and a revert', async ({ page }) => {
    await openReceiptTab(page)
    const preview = page.getByTestId('receipt-preview-body')

    // Baseline: TTC, no logo.
    await expect(preview).toContainText('Sous-total :')
    await expect(preview).toContainText('11.000 TND')
    await expect(page.getByTestId('receipt-preview-logo')).toHaveCount(0)

    // Change -> preview updates with NOTHING saved yet.
    await page.getByRole('radio', { name: /HT \(hors taxes\)/i }).click()
    await page.getByRole('switch', { name: /imprimer le logo/i }).click()
    await expect(preview).toContainText('Sous-total HT :')
    await expect(preview).toContainText('10.092 TND')
    await expect(preview).toContainText('10.000 TND')
    await expect(page.getByTestId('receipt-preview-logo')).toBeVisible()

    // Save -> reload -> persisted.
    await page.getByRole('button', { name: /enregistrer/i }).click()
    await expect(page.getByText(/enregistrés avec succès/i)).toBeVisible()
    await page.reload()
    await expect(page.getByRole('radio', { name: /HT \(hors taxes\)/i })).toBeChecked()
    await expect(page.getByTestId('receipt-preview-body')).toContainText('Sous-total HT :')

    // Revert -> back to the shipped default.
    await page.getByRole('radio', { name: /TTC \(toutes taxes comprises\)/i }).click()
    await page.getByRole('switch', { name: /imprimer le logo/i }).click()
    await page.getByRole('button', { name: /enregistrer/i }).click()
    await page.reload()
    await expect(page.getByRole('radio', { name: /TTC \(toutes taxes comprises\)/i })).toBeChecked()
    await expect(page.getByTestId('receipt-preview-body')).toContainText('Sous-total :')
    await expect(page.getByTestId('receipt-preview-logo')).toHaveCount(0)
  })

  test('the 58 mm switch re-lays the preview and is never saved', async ({ page }) => {
    await openReceiptTab(page)
    const preview = page.getByTestId('receipt-preview-body')

    const widthAt80 = await preview.evaluate((node) => node.getBoundingClientRect().width)
    await page.getByLabel(/largeur du papier/i).selectOption('32')
    const widthAt58 = await preview.evaluate((node) => node.getBoundingClientRect().width)
    expect(widthAt58).toBeLessThan(widthAt80)

    await expect(page.getByText(/n'est pas enregistré/i)).toBeVisible()
    await expect(page.getByRole('button', { name: /enregistrer/i })).toBeDisabled()
  })

  test('the logo switch is disabled with a hint when the company uploaded no logo', async ({ page }) => {
    await openReceiptTab(page, { settings: { has_logo: false } })

    await expect(page.getByRole('switch', { name: /imprimer le logo/i })).toBeDisabled()
    await expect(page.getByText(/aucun logo n'a été téléversé/i)).toBeVisible()
  })

  test('a cashier can read the tab but cannot save', async ({ page }) => {
    await openReceiptTab(page, { permissions: ['settings.view'] })

    await expect(page.getByTestId('receipt-preview-body')).toBeVisible()
    await expect(page.getByRole('switch', { name: /imprimer le logo/i })).toBeDisabled()
    await expect(page.getByRole('radio', { name: /HT \(hors taxes\)/i })).toBeDisabled()
    await expect(page.getByRole('button', { name: /enregistrer/i })).toBeDisabled()
  })

  test('a second company shows its own settings, not the first one\'s', async ({ page }) => {
    await openReceiptTab(page, {
      companyId: 'company-2',
      settings: { receipt_subtotal_mode: 'HT', receipt_logo: true },
    })

    await expect(page.getByRole('radio', { name: /HT \(hors taxes\)/i })).toBeChecked()
    await expect(page.getByTestId('receipt-preview-body')).toContainText('Sous-total HT :')
    await expect(page.getByTestId('receipt-preview-logo')).toBeVisible()
  })
})
```

`installAuthMocks` is not a new helper to invent: reuse the auth/companies/permissions routing that `apps/web/e2e/fixtures.ts:19-50` already sets up (extract it there if it is currently inlined in the fixture, and import it here). Read that file before writing this one.

- [ ] **Step 2: Run the spec**

Run: `pnpm --filter @autoerp/web test:e2e -- e2e/settings/receipt-preview.spec.ts`
Expected: PASS — 5 tests.

If the shared Playwright browser is locked by another session, run with an isolated port: `PLAYWRIGHT_PORT=5199 pnpm --filter @autoerp/web test:e2e -- e2e/settings/receipt-preview.spec.ts` (the config honours `PLAYWRIGHT_PORT` and then does not reuse another checkout's server — `playwright.config.ts:2-4,29-34`).

Run: `pnpm --filter @autoerp/web typecheck:e2e` → PASS.

- [ ] **Step 3: Run the PR gate**

```bash
cd apps/api && ./vendor/bin/phpunit tests/Feature/Company/ReceiptSettingsTest.php
cd apps/api && ./vendor/bin/pint --test app/Modules/Company app/Http/Controllers/Api database/migrations/tenant tests/Feature/Company
cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Company app/Http/Controllers/Api --level=8 --memory-limit=2G
PREFLIGHT_TEST_PATHS='tests/Feature/Company' PREFLIGHT_PHPSTAN_PATHS='app/Modules/Company' ./scripts/preflight.sh
pnpm --filter @autoerp/web test
pnpm --filter @autoerp/web lint
pnpm --filter @autoerp/web typecheck
pnpm --filter @autoerp/shared test
pnpm --filter @autoerp/pos test
```

When Docker is up (Fable owns it), also run the real lane: `scripts/run-feature-lane-local.sh feature-lane-tenancy` (or the Company group). `cargo` is **not** run in PR 2 — it touches no Rust.

Finally, confirm the generated artefacts are in sync:

```bash
cd apps/api && CACHE_STORE=array php artisan typescript:transform && git diff --exit-code packages/shared/types/generated.d.ts
cd apps/api && php artisan permissions:export-frontend-map && git diff --exit-code apps/web/src/hooks/permissionsMap.generated.ts
```
Both must exit clean.

- [ ] **Step 4: Update the registry and write the PR description**

In `docs/qa/DEV-QA-registry.md`, leave **DEV-QA-085…088** as PR 1 recorded them and leave **DEV-QA-089** `OUVERT` — PR 2 does not gate `GET companies/{id}/pos-settings`; say so explicitly in the PR description so the cashier-403 test is not misread as covering the read path.

The PR description must state the verification level actually reached per layer (rule 23 step 2, CLAUDE.md "Verified means end-to-end"): **PHPUnit scoped (SQLite unless the Company PG lane was run — name which)**, **web Vitest**, **Playwright with mocked API routes**, and **no manual recette yet**. Enumerate every changed endpoint and field with `path:line`. Re-check every `t()` key added in Task 5 against the three locale files (rule 23 step 4). Refresh the description after each correction round (rule 23 step 5).

- [ ] **Step 5: Commit**

```bash
git add apps/web/e2e/settings/receipt-preview.spec.ts apps/web/e2e/fixtures.ts docs/qa/DEV-QA-registry.md
git commit -m "test(settings-e2e): receipt preview round-trip, cashier denial, second company"
```

---

## Self-review

**1. Spec coverage.** Every PR-2 row of the spec's Delivery table maps to a task: API columns/enum → Task 1; DTO → Task 3; config read → Task 4; web controls → Task 6; `ReceiptPreview` → Task 7; i18n → Task 5. Spec §5's PHPUnit row (valid/invalid mode, boolean logo, create→edit→revert, second company, cashier 403, save-twice idempotent, `/company/config` exposure, `ReceiptSettingsData` shape) is covered by Tasks 1-4 in one class; the Vitest (web) row by Tasks 6-7; the Playwright row by Task 8. Spec §1.5's layout requirement (preview right column at ≥ lg, stacked below) is Task 7 Step 4. B7's unsaved 80/58 mm switch is Task 7. B12's "reprints follow current settings" needs no PR-2 code — it is the consequence of reading settings live, and PR 1 Task 11 pins that no fiscal byte moves.

**Gaps and deviations, all deliberate:**
- **`has_logo` is a field the spec does not name.** Spec §1.5 requires the switch to be disabled "when the company has no `logo_path`", but `logo_path` is not on this endpoint and must not be leaked. Task 3 adds the derived boolean. Flagged rather than silent.
- **`receipt_logo` keeps its `string(255)` column with a boolean cast**, per ruling 4 (no column change beyond the one new column). A legacy row holding a path reads as `true`, which preserves the PDF's current truthiness semantics (`receipt.blade.php:317-319`); Task 1 pins that with `test_a_legacy_logo_path_still_reads_as_switched_on`. Residual: the column type still permits a path, so a future direct-SQL write could put one there. Narrowing it to `boolean` is a separate migration, out of this lane's ruling.
- **DEV-QA-089 is not fixed.** `GET companies/{id}/pos-settings` stays ungated (ticket E-4). The cashier test covers the **write** path only, and the PR description must say so — otherwise the row reads as closed when it is not.
- **PR 2 does not touch the server PDF.** The logo toggle now has a writer, so `receipt.blade.php:317-319` starts honouring it — that is the intended effect, but it also means the PDF and the thermal ticket diverge further on the logo until ticket E-1 lands the raster. Declared in the glossary row PR 1 Task 12 adds.
- **The Playwright spec mocks the API**, matching the existing suite's style. It proves the UI round trip and the permission gating, not the server persistence — the PHPUnit class proves that. Neither is a manual recette; the PR says so.
- **Preview double-width/height rendering is an approximation.** A thermal printer doubles the glyph cell; the preview approximates with `text-[15px]` and letter-spacing. Column *padding* is exact (the shared `padColumns`), which is the fidelity that matters for layout; the emphasised rows are visually indicative. Stated so no one reads the preview as pixel-exact.
- **The `Company::locations()` shape in Task 2's second-location test is unverified.** The step names the two files to read first and forbids inventing columns (rule 17).

**2. Placeholder scan.** No `TBD`, no "add validation", no "similar to Task N". The three places that defer to the codebase — the web test harness (Task 6 Step 1), `installAuthMocks` (Task 8 Step 1) and the locations shape (Task 2 Step 1) — each name the exact file to read and forbid invention.

**3. Type consistency.** `ReceiptSubtotalMode` is `'TTC' | 'HT'` in the PHP enum (Task 1), the generated ambient type (Task 3), the zod enum (Task 6) and the shared alias (PR 1 Task 1, re-pointed in Task 3). `ReceiptSettings` (the generated DTO alias) is used in Tasks 3, 6 and 8 with the same field names — `has_logo`, `receipt_logo`, `receipt_subtotal_mode` — everywhere. `ReceiptPreview`'s props `{ display, columns }` match its call site in Task 7 Step 4 and its tests. `buildReceiptDoc(data, display, print)` and `padColumns(segment, columns)` keep the PR-1 signatures. The `data-testid` values `receipt-preview-body`, `receipt-preview-logo`, `receipt-preview-qr`, `receipt-preview-cut` are spelled identically in Task 7's component, Task 7's Vitest and Task 8's Playwright spec.
