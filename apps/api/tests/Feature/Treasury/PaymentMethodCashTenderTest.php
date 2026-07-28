<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PaymentMethodCashTenderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Cash Tender Tenant',
            'slug' => 'cash-tender-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cash Tender Shop',
            'legal_name' => 'Cash Tender Shop SARL',
            'tax_id' => 'TAX-CT-1',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('treasury.view', 'sanctum');
        Permission::findOrCreate('treasury.manage', 'sanctum');

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Treasury Admin',
            'email' => 'admin@cash-tender-test.local',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->admin->givePermissionTo('treasury.view');
        $this->admin->givePermissionTo('treasury.manage');

        UserCompanyMembership::create([
            'user_id' => $this->admin->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Owner,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);
    }

    public function test_column_exists_with_false_default(): void
    {
        $this->assertTrue(Schema::hasColumn('payment_methods', 'is_cash_tender'));

        $method = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CARD',
            'name' => 'Carte bancaire',
            'is_physical' => false,
            'has_maturity' => false,
            'is_active' => true,
            'position' => 2,
        ]);

        $this->assertFalse($method->refresh()->is_cash_tender);
    }

    public function test_format_method_exposes_is_cash_tender(): void
    {
        PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Espèces',
            'is_physical' => true,
            'has_maturity' => false,
            'is_cash_tender' => true,
            'is_active' => true,
            'position' => 1,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/payment-methods');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('code', 'CASH');
        $this->assertNotNull($row);
        $this->assertArrayHasKey('is_cash_tender', $row);
        $this->assertTrue($row['is_cash_tender']);
    }

    public function test_store_rejects_cash_tender_on_non_cash_code(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/payment-methods', [
                'code' => 'meal_voucher',
                'name' => 'Ticket Restaurant',
                'is_physical' => true,
                'is_cash_tender' => true,
            ]);

        $response->assertStatus(422);
    }

    public function test_store_uppercases_code_and_accepts_cash(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/payment-methods', [
                'code' => 'cash',
                'name' => 'Espèces',
                'is_physical' => true,
                'is_cash_tender' => true,
            ]);

        $response->assertStatus(201);
        $this->assertSame('CASH', $response->json('data.code'));
        $this->assertTrue($response->json('data.is_cash_tender'));
    }

    public function test_store_rejects_case_variant_of_an_existing_code_with_422_not_500(): void
    {
        PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Espèces',
            'is_physical' => true,
            'has_maturity' => false,
            'is_cash_tender' => true,
            'is_active' => true,
            'position' => 1,
        ]);

        // Uppercasing AFTER validation would let this pass Rule::unique against
        // the raw 'cash' and then violate unique(company_id, code) → HTTP 500.
        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/payment-methods', [
                'code' => '  cash  ',
                'name' => 'Espèces bis',
                'is_physical' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('code', $response->json('error.errors'));
        $this->assertSame(1, PaymentMethod::query()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_update_rejects_case_variant_of_an_existing_code_with_422_not_500(): void
    {
        PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Espèces',
            'is_physical' => true,
            'has_maturity' => false,
            'is_cash_tender' => true,
            'is_active' => true,
            'position' => 1,
        ]);

        $card = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CARD',
            'name' => 'Carte bancaire',
            'is_physical' => false,
            'has_maturity' => false,
            'is_active' => true,
            'position' => 2,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/payment-methods/'.$card->id, [
                'code' => 'cash',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('code', $response->json('error.errors'));
        $this->assertSame('CARD', $card->refresh()->code);
    }

    public function test_update_without_a_code_key_preserves_the_stored_code(): void
    {
        $card = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CARD',
            'name' => 'Carte bancaire',
            'is_physical' => false,
            'has_maturity' => false,
            'is_active' => true,
            'position' => 2,
        ]);

        // Guards the has('code') condition on the pre-validation merge: an
        // unconditional merge would inject '' and the `sometimes` rule would
        // then overwrite the stored code with it.
        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/payment-methods/'.$card->id, [
                'name' => 'Carte bancaire (CB)',
            ]);

        $response->assertOk();
        $this->assertSame('CARD', $card->refresh()->code);
        $this->assertSame('Carte bancaire (CB)', $card->name);
    }

    public function test_update_rejects_an_explicit_null_code_and_preserves_the_stored_one(): void
    {
        $card = $this->makeCard();

        // Normalizing an explicit null into '' would satisfy `sometimes` +
        // `string` and silently persist a blank code with 200 OK; a second
        // blanked row would then collide on unique(company_id, code).
        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/payment-methods/'.$card->id, [
                'code' => null,
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('code', $response->json('error.errors'));
        $this->assertSame('CARD', $card->refresh()->code);
    }

    public function test_update_rejects_a_blank_code(): void
    {
        $card = $this->makeCard();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/payment-methods/'.$card->id, [
                'code' => '   ',
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('code', $response->json('error.errors'));
        $this->assertSame('CARD', $card->refresh()->code);
    }

    public function test_store_rejects_a_non_string_code_with_422_not_500(): void
    {
        // Uppercasing a non-string would fatal on `Array to string conversion`
        // inside the pre-validation merge — a 500 raised before the `string`
        // rule ever gets to return its 422.
        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/payment-methods', [
                'code' => ['CASH'],
                'name' => 'Espèces',
                'is_physical' => true,
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('code', $response->json('error.errors'));
    }

    public function test_update_rejects_a_non_string_code_with_422_not_500(): void
    {
        $card = $this->makeCard();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/payment-methods/'.$card->id, [
                'code' => ['CASH'],
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('code', $response->json('error.errors'));
        $this->assertSame('CARD', $card->refresh()->code);
    }

    public function test_update_rejects_flipping_cash_tender_on_non_cash_method(): void
    {
        $method = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CARD',
            'name' => 'Carte bancaire',
            'is_physical' => false,
            'has_maturity' => false,
            'is_active' => true,
            'position' => 2,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/payment-methods/'.$method->id, [
                'is_cash_tender' => true,
            ]);

        $response->assertStatus(422);
    }

    public function test_migration_backfills_existing_cash_rows(): void
    {
        // Simulate a brownfield lowercase code by writing past the controller.
        $id = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Espèces',
            'is_physical' => true,
            'has_maturity' => false,
            'is_active' => true,
            'position' => 1,
        ])->id;

        DB::table('payment_methods')
            ->where('id', $id)
            ->update(['code' => 'cash', 'is_cash_tender' => false]);

        $this->runBackfillMigration();

        $row = DB::table('payment_methods')->where('id', $id)->first();
        $this->assertSame('CASH', $row->code);
        $this->assertTrue((bool) $row->is_cash_tender);
    }

    public function test_migration_skips_variant_when_canonical_cash_row_already_exists(): void
    {
        // A brownfield company holding BOTH 'CASH' and 'cash'. unique(company_id,
        // code) is case-sensitive in PostgreSQL, so both rows coexist legally
        // and a blind normalization would abort the whole tenants:migrate run.
        $canonicalId = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Espèces',
            'is_physical' => true,
            'has_maturity' => false,
            'is_active' => true,
            'position' => 1,
        ])->id;

        $variantId = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH_LEGACY',
            'name' => 'Espèces (legacy)',
            'is_physical' => true,
            'has_maturity' => false,
            'is_active' => true,
            'position' => 9,
        ])->id;

        DB::table('payment_methods')->where('id', $variantId)->update(['code' => 'cash']);
        DB::table('payment_methods')
            ->whereIn('id', [$canonicalId, $variantId])
            ->update(['is_cash_tender' => false]);

        Log::spy();

        // Must not throw: a unique-violation here would abort tenants:migrate.
        $this->runBackfillMigration();

        $canonical = DB::table('payment_methods')->where('id', $canonicalId)->first();
        $this->assertSame('CASH', $canonical->code);
        $this->assertTrue((bool) $canonical->is_cash_tender, 'The canonical CASH row must be flagged.');

        $variant = DB::table('payment_methods')->where('id', $variantId)->first();
        $this->assertSame('cash', $variant->code, 'The colliding variant must be left untouched.');
        $this->assertFalse(
            (bool) $variant->is_cash_tender,
            'The skipped variant must stay unflagged (fail-closed).',
        );

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($variantId): bool {
                return $message === 'cash_rounding.backfill.skipped_ambiguous_cash_code'
                    && $context['payment_method_id'] === $variantId
                    && $context['tenant_id'] === $this->tenant->id
                    && $context['company_id'] === $this->company->id
                    && $context['code'] === 'cash';
            })
            ->once();
    }

    public function test_migration_normalizes_a_sibling_companys_variant(): void
    {
        // The unique index is (company_id, code) — 2025_12_30_195300 replaced
        // the original (tenant_id, code) so sibling companies can reuse codes.
        // A second company's 'cash' therefore collides with NOTHING and must be
        // normalized and flagged. Correlating the skip-check on tenant_id would
        // wrongly skip it and leave this company without a cash tender.
        $sibling = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cash Tender Shop II',
            'legal_name' => 'Cash Tender Shop II SARL',
            'tax_id' => 'TAX-CT-2',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        // Company 1 holds the canonical 'CASH'.
        PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Espèces',
            'is_physical' => true,
            'has_maturity' => false,
            'is_active' => true,
            'position' => 1,
        ]);

        // Company 2 holds only the lowercase variant.
        $siblingId = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $sibling->id,
            'code' => 'CASH_SIBLING',
            'name' => 'Espèces',
            'is_physical' => true,
            'has_maturity' => false,
            'is_active' => true,
            'position' => 1,
        ])->id;

        DB::table('payment_methods')->where('id', $siblingId)->update([
            'code' => 'cash',
            'is_cash_tender' => false,
        ]);

        $this->runBackfillMigration();

        $row = DB::table('payment_methods')->where('id', $siblingId)->first();
        $this->assertSame('CASH', $row->code, "The sibling company's variant must be normalized.");
        $this->assertTrue((bool) $row->is_cash_tender, "The sibling company's row must be flagged.");
    }

    public function test_migration_elects_one_winner_when_a_company_has_two_variants_and_no_canonical(): void
    {
        // 'cash' + 'Cash' and NO canonical 'CASH'. Both pass the
        // canonical-row guard, so without a tie-break both would rewrite to
        // 'CASH' and fire unique(company_id, code) mid tenants:migrate.
        $firstId = $this->makeCashVariant('CASH_V1', 'cash');
        $secondId = $this->makeCashVariant('CASH_V2', 'Cash');

        // The winner is the lowest id, not the creation order — assert on the
        // actual ordering rather than assuming how HasUuids generates them.
        $ids = [$firstId, $secondId];
        sort($ids);
        [$winnerId, $loserId] = $ids;
        $loserCode = $loserId === $firstId ? 'cash' : 'Cash';

        Log::spy();

        // Must not throw: a unique violation here would abort tenants:migrate.
        $this->runBackfillMigration();

        $winner = DB::table('payment_methods')->where('id', $winnerId)->first();
        $this->assertSame('CASH', $winner->code, 'The lowest-id variant must be elected.');
        $this->assertTrue((bool) $winner->is_cash_tender, 'The elected winner must be flagged.');

        $loser = DB::table('payment_methods')->where('id', $loserId)->first();
        $this->assertSame($loserCode, $loser->code, 'The losing variant must be left untouched.');
        $this->assertFalse((bool) $loser->is_cash_tender, 'The losing variant must stay unflagged.');

        // Exactly one skip is logged — the winner must not be reported.
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($loserId, $loserCode): bool {
                return $message === 'cash_rounding.backfill.skipped_ambiguous_cash_code'
                    && $context['payment_method_id'] === $loserId
                    && $context['code'] === $loserCode
                    && str_contains($context['reason'], 'no canonical row');
            })
            ->once();
    }

    private function makeCard(): PaymentMethod
    {
        return PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CARD',
            'name' => 'Carte bancaire',
            'is_physical' => false,
            'has_maturity' => false,
            'is_active' => true,
            'position' => 2,
        ]);
    }

    /**
     * Create a payment method under a placeholder code, then write the
     * brownfield case-variant straight to the column (past the controller,
     * which would uppercase it).
     */
    private function makeCashVariant(string $placeholderCode, string $variantCode): string
    {
        $id = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => $placeholderCode,
            'name' => 'Espèces',
            'is_physical' => true,
            'has_maturity' => false,
            'is_active' => true,
            'position' => 1,
        ])->id;

        DB::table('payment_methods')->where('id', $id)->update([
            'code' => $variantCode,
            'is_cash_tender' => false,
        ]);

        return $id;
    }

    /**
     * Execute the REAL migration file rather than a pasted copy of its SQL, so
     * this suite fails if the migration is deleted or its backfill changes.
     */
    private function runBackfillMigration(): void
    {
        $migration = require base_path(
            'database/migrations/tenant/2026_07_28_100000_add_is_cash_tender_to_payment_methods.php'
        );

        $migration->up();
    }
}
