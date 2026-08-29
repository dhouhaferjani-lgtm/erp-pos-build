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
use Illuminate\Log\Events\MessageLogged;
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

        Log::spy();

        $this->runBackfillMigration();

        $row = DB::table('payment_methods')->where('id', $id)->first();
        $this->assertSame('CASH', $row->code);
        $this->assertTrue((bool) $row->is_cash_tender);

        // The REWRITE must be logged, not just the skips: after the UPDATE the
        // old code is unrecoverable, and the operator needs the affected
        // company list to force a device payment-method resync and re-drive
        // in-flight projections carrying the old mixed-case method_code.
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($id): bool {
                return $message === 'cash_rounding.backfill.rewritten_cash_code'
                    && $context['payment_method_id'] === $id
                    && $context['tenant_id'] === $this->tenant->id
                    && $context['company_id'] === $this->company->id
                    && $context['code'] === 'cash'
                    && $context['new_code'] === 'CASH';
            })
            ->once();
    }

    public function test_migration_does_not_log_a_rewrite_for_an_already_canonical_row(): void
    {
        // A row already sitting on 'CASH' is FLAGGED but not REWRITTEN. Logging
        // it would hand the operator a resync list padded with companies whose
        // code never moved.
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

        DB::table('payment_methods')->where('id', $id)->update(['is_cash_tender' => false]);

        Log::spy();

        $this->runBackfillMigration();

        $row = DB::table('payment_methods')->where('id', $id)->first();
        $this->assertSame('CASH', $row->code);
        $this->assertTrue((bool) $row->is_cash_tender, 'The canonical row must still be flagged.');

        // `shouldHaveReceived(...)->times(0)` does NOT express this: Mockery
        // verifies a spy expectation as "called at least once" before the count
        // is consulted, so it fails on a spy that (correctly) never received the
        // call. Assert the absence directly instead, matching the message
        // EXACTLY and the context loosely — every `Log::warning` this migration
        // emits passes an array context, so the pair pins the event key without
        // the full-argument-list brittleness of a literal context array.
        Log::shouldNotHaveReceived('warning', [
            'cash_rounding.backfill.rewritten_cash_code',
            \Mockery::type('array'),
        ]);

        // Guard the guard: the skip warning must not fire either — a canonical
        // row is neither rewritten NOR ambiguous.
        Log::shouldNotHaveReceived('warning', [
            'cash_rounding.backfill.skipped_ambiguous_cash_code',
            \Mockery::type('array'),
        ]);
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

    // ------------------------------------------------- I-1 bidirectional guard

    /**
     * Session D final review, I-1 — the MISSING half of the invariant.
     *
     * `store()` used to persist a canonical `CASH` method with the flag false.
     * Such a row is read as NON-cash by `TenderRepositoryResolver`, the bridge
     * and the device checkout resolver, and as CASH by every historical
     * `UPPER(code) = 'CASH'` consumer — the same tender classified in opposite
     * directions, one of which lands in a certified expected-cash figure.
     */
    public function test_store_refuses_a_canonical_cash_code_that_is_not_flagged(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/payment-methods', [
                'code' => 'CASH',
                'name' => 'Espèces',
                'is_physical' => true,
                'is_cash_tender' => false,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'PAYMENT_METHOD_CANONICAL_CASH_CODE_NOT_FLAGGED');
        $response->assertJsonPath('error.payment_method_code', 'CASH');
        $this->assertSame(0, PaymentMethod::query()->where('tenant_id', $this->tenant->id)->count());
    }

    /**
     * The flag is OPTIONAL in the request; omitting it defaults to false, which
     * is exactly how the brownfield rows I-1 found were created. Absence must
     * refuse for the same reason an explicit `false` does.
     */
    public function test_store_refuses_a_canonical_cash_code_with_the_flag_omitted_entirely(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/payment-methods', [
                'code' => 'cash',
                'name' => 'Espèces',
                'is_physical' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'PAYMENT_METHOD_CANONICAL_CASH_CODE_NOT_FLAGGED');
        $this->assertSame(0, PaymentMethod::query()->where('tenant_id', $this->tenant->id)->count());
    }

    /**
     * The other direction, unchanged in semantics but now typed: the code is
     * part of the contract, and the two remedies differ in kind.
     */
    public function test_store_refusal_of_a_flag_on_a_non_cash_code_carries_its_own_typed_code(): void
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
        $response->assertJsonPath('error.code', 'PAYMENT_METHOD_CASH_TENDER_FLAG_ON_NON_CANONICAL_CODE');
        $response->assertJsonPath('error.payment_method_code', 'MEAL_VOUCHER');
    }

    /**
     * A PATCH that would UNSET the flag on the canonical row is the same defect
     * arriving by the update door — the door I-1 found open at
     * `PaymentMethodController.php:255-268,358-371`.
     */
    public function test_update_refuses_clearing_the_flag_on_the_canonical_cash_row(): void
    {
        $cash = PaymentMethod::create([
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
            ->patchJson('/api/v1/payment-methods/'.$cash->id, [
                'is_cash_tender' => false,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'PAYMENT_METHOD_CANONICAL_CASH_CODE_NOT_FLAGGED');
        $this->assertTrue($cash->refresh()->is_cash_tender);
    }

    /**
     * RENAMING a flagged method away from `CASH` breaks the invariant from the
     * other side: the final state would be a flag on a non-canonical code.
     */
    public function test_update_refuses_renaming_the_flagged_cash_method_off_the_canonical_code(): void
    {
        $cash = PaymentMethod::create([
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
            ->patchJson('/api/v1/payment-methods/'.$cash->id, [
                'code' => 'ESPECES',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'PAYMENT_METHOD_CASH_TENDER_FLAG_ON_NON_CANONICAL_CODE');
        $this->assertSame('CASH', $cash->refresh()->code);
    }

    /**
     * THE ESCAPE HATCH for shape (A) — a brownfield canonical row with the flag
     * false. The guard would be a trap without it: the row cannot be edited at
     * all until it is coherent, so the coherent edit itself has to be reachable.
     */
    public function test_update_lets_an_operator_flag_a_brownfield_canonical_cash_row(): void
    {
        $id = $this->brownfieldCanonicalCashRow();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/payment-methods/'.$id, [
                'is_cash_tender' => true,
            ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.is_cash_tender'));
    }

    /**
     * And the trap itself, pinned deliberately: while the row stays incoherent,
     * an UNRELATED edit is refused rather than silently perpetuating it. This is
     * the behaviour change with the widest blast radius in this lane, so it is
     * asserted rather than left to be discovered in production.
     */
    public function test_update_refuses_an_unrelated_edit_to_an_incoherent_brownfield_row(): void
    {
        $id = $this->brownfieldCanonicalCashRow();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/payment-methods/'.$id, [
                'name' => 'Espèces (renommé)',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'PAYMENT_METHOD_CANONICAL_CASH_CODE_NOT_FLAGGED');
    }

    /**
     * THE ESCAPE HATCH for shape (B) — a mixed-case collision loser. It cannot
     * be flagged (the canonical row owns `CASH`, and the flag is only legal on
     * the exact code), so the only coherent end state is a code that is not in
     * the cash family at all. That edit must go through.
     */
    public function test_update_lets_an_operator_rename_a_mixed_case_collision_loser(): void
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

        $loserId = $this->brownfieldCaseVariantCashRow('Cash');

        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/payment-methods/'.$loserId, [
                'code' => 'cash_legacy',
            ]);

        $response->assertStatus(200);
        $this->assertSame('CASH_LEGACY', $response->json('data.code'));
        $this->assertFalse($response->json('data.is_cash_tender'));
    }

    /**
     * And it must NOT be flaggable: only one method per company may hold `CASH`,
     * so allowing the variant to carry the flag would give the company two cash
     * tenders with different codes and reopen the split from the other side.
     */
    public function test_update_refuses_flagging_a_mixed_case_collision_loser(): void
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

        $loserId = $this->brownfieldCaseVariantCashRow('Cash');

        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/payment-methods/'.$loserId, [
                'is_cash_tender' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'PAYMENT_METHOD_CASH_TENDER_FLAG_ON_NON_CANONICAL_CODE');
        $this->assertFalse((bool) DB::table('payment_methods')->where('id', $loserId)->value('is_cash_tender'));
    }

    // ------------------------------------------------------- I-1 census (A3)

    /**
     * The census is REPORTING infrastructure — its whole value is that an
     * operator can act on it, so what it prints is the contract.
     */
    public function test_the_census_names_every_violating_shape_and_changes_nothing(): void
    {
        // (A) canonical CASH, unflagged.
        $canonicalUnflagged = $this->brownfieldCanonicalCashRow();

        // (B) a mixed-case variant, unflagged. Coexists with (A) legally: the
        // unique index is case-sensitive in PostgreSQL.
        $variant = $this->brownfieldCaseVariantCashRow('Cash');

        // (C) the flag on a non-canonical code — unreachable through the
        // controller, reachable through a seeder or a hand-run UPDATE.
        $flaggedVoucher = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'MEAL_VOUCHER',
            'name' => 'Ticket Restaurant',
            'is_physical' => true,
            'has_maturity' => false,
            'is_active' => true,
            'position' => 5,
        ])->id;
        DB::table('payment_methods')->where('id', $flaggedVoucher)->update(['is_cash_tender' => true]);

        // A coherent row that must NOT be censused.
        $card = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CARD',
            'name' => 'Carte',
            'is_physical' => false,
            'has_maturity' => false,
            'is_active' => true,
            'position' => 2,
        ])->id;

        $output = $this->runCashTenderCensusMigrationAndCollectLog();

        $this->assertStringContainsString('[I-1] cash-tender invariant violations found: 3', $output);
        $this->assertStringContainsString($this->company->id, $output, 'the census must group by company');
        $this->assertStringContainsString($canonicalUnflagged, $output);
        $this->assertStringContainsString($variant, $output);
        $this->assertStringContainsString($flaggedVoucher, $output);
        $this->assertStringNotContainsString($card, $output, 'a coherent row must not be reported');

        // The suggested remedies, one per shape.
        $this->assertStringContainsString(
            "UPDATE payment_methods SET is_cash_tender = true WHERE id = '".$canonicalUnflagged."'",
            $output,
        );
        $this->assertStringContainsString(
            "UPDATE payment_methods SET code = 'CASH_LEGACY' WHERE id = '".$variant."'",
            $output,
        );
        $this->assertStringContainsString(
            "UPDATE payment_methods SET is_cash_tender = false WHERE id = '".$flaggedVoucher."'",
            $output,
        );

        // NON-MUTATING is the point: remediation is an operator decision.
        $this->assertFalse((bool) DB::table('payment_methods')->where('id', $canonicalUnflagged)->value('is_cash_tender'));
        $this->assertSame('Cash', DB::table('payment_methods')->where('id', $variant)->value('code'));
        $this->assertTrue((bool) DB::table('payment_methods')->where('id', $flaggedVoucher)->value('is_cash_tender'));
    }

    /**
     * A census of ZERO must still print, so silence in the migrate log can only
     * ever mean "the migration did not run" — the same standard the W4-1 lot
     * census is held to.
     */
    public function test_a_zero_census_still_prints_so_silence_is_unambiguous(): void
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

        $output = $this->runCashTenderCensusMigrationAndCollectLog();

        $this->assertStringContainsString('[I-1] cash-tender invariant violations found: 0', $output);
        $this->assertStringNotContainsString('NOTHING WAS CHANGED', $output);
    }

    /**
     * Shape (A): a canonical `CASH` row with the flag false — created past the
     * controller, because the controller now refuses exactly this.
     */
    private function brownfieldCanonicalCashRow(): string
    {
        $id = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Espèces',
            'is_physical' => true,
            'has_maturity' => false,
            'is_cash_tender' => true,
            'is_active' => true,
            'position' => 1,
        ])->id;

        DB::table('payment_methods')->where('id', $id)->update(['is_cash_tender' => false]);

        return $id;
    }

    /**
     * Shape (B): a mixed-case cash-family code left unflagged — the collision
     * loser `2026_07_28_100000_add_is_cash_tender_to_payment_methods` skips.
     */
    private function brownfieldCaseVariantCashRow(string $variantCode): string
    {
        $id = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH_VARIANT_PLACEHOLDER',
            'name' => 'Espèces (variante)',
            'is_physical' => true,
            'has_maturity' => false,
            'is_active' => true,
            'position' => 8,
        ])->id;

        DB::table('payment_methods')->where('id', $id)->update([
            'code' => $variantCode,
            'is_cash_tender' => false,
        ]);

        return $id;
    }

    /**
     * Execute the REAL census migration file, so this suite fails if it is
     * deleted or its predicate changes.
     */
    private function runCashTenderCensusMigration(): void
    {
        $migration = require base_path(
            'database/migrations/tenant/2026_08_27_100000_census_cash_tender_invariant_violations.php'
        );

        $migration->up();
    }

    private function runCashTenderCensusMigrationAndCollectLog(): string
    {
        $messages = [];
        Log::listen(static function (MessageLogged $event) use (&$messages): void {
            $messages[] = $event->message;
        });

        $this->runCashTenderCensusMigration();

        return implode("\n", $messages);
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
