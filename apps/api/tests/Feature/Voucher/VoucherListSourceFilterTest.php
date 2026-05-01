<?php

declare(strict_types=1);

namespace Tests\Feature\Voucher;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Voucher\Domain\Enums\VoucherSource;
use App\Modules\Voucher\Domain\Voucher;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Codex review M1 — backend source-filter round-trip test.
 *
 * Verifies that:
 *  - GET ?source=refund  → 200, only the refund voucher.
 *  - GET ?source=goodwill → 200, only the goodwill voucher.
 *  - GET ?source=<all-six-values> → 200 for each.
 *  - GET ?source=Refund  → 422 (PascalCase rejected; proves the API does NOT
 *    silently normalise, and the frontend must send lowercase values).
 */
final class VoucherListSourceFilterTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user->givePermissionTo('pos.void_voucher');
        $this->user->givePermissionTo('pos.issue_goodwill_voucher');
    }

    public function test_filter_by_refund_returns_only_refund_voucher(): void
    {
        Sanctum::actingAs($this->user);

        Voucher::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'source' => VoucherSource::Refund,
            'issued_by_user_id' => $this->user->id,
        ]);

        Voucher::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'source' => VoucherSource::Goodwill,
            'issued_by_user_id' => $this->user->id,
        ]);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/vouchers?source=refund');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.source', 'refund');
    }

    public function test_filter_by_goodwill_returns_only_goodwill_voucher(): void
    {
        Sanctum::actingAs($this->user);

        Voucher::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'source' => VoucherSource::Refund,
            'issued_by_user_id' => $this->user->id,
        ]);

        Voucher::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'source' => VoucherSource::Goodwill,
            'issued_by_user_id' => $this->user->id,
        ]);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/vouchers?source=goodwill');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.source', 'goodwill');
    }

    /** @dataProvider allSixSourcesProvider */
    public function test_filter_accepts_all_six_lowercase_storage_values(VoucherSource $source): void
    {
        Sanctum::actingAs($this->user);

        Voucher::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'source' => $source,
            'issued_by_user_id' => $this->user->id,
        ]);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/vouchers?source='.$source->value);

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.source', $source->value);
    }

    /** @return array<string, array{VoucherSource}> */
    public static function allSixSourcesProvider(): array
    {
        return [
            'refund' => [VoucherSource::Refund],
            'exchange_surplus' => [VoucherSource::ExchangeSurplus],
            'goodwill' => [VoucherSource::Goodwill],
            'loyalty_credit' => [VoucherSource::LoyaltyCredit],
            'gift_card_purchase' => [VoucherSource::GiftCardPurchase],
            'promotional' => [VoucherSource::Promotional],
        ];
    }

    public function test_filter_with_pascal_case_source_returns_422(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/vouchers?source=Refund');

        $response->assertUnprocessable();
    }
}
