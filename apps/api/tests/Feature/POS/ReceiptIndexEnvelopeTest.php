<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ReceiptIndexEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_preserves_frozen_envelope_and_emits_resolved_utc_window(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00 UTC');

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'timezone' => 'Africa/Tunis',
            'currency' => 'EUR',
        ]);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'admin',
            'allowed_location_ids' => null,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        Permission::findOrCreate('pos.view_receipts', 'sanctum');
        $user->givePermissionTo('pos.view_receipts');
        Sanctum::actingAs($user);

        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);
        Receipt::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $user->id,
            'posted_at' => '2026-08-17 09:00:00 UTC',
            'invoice_type_code' => 'SALE',
            'training_flag' => false,
            'currency' => 'TND',
            'subtotal' => '10.000',
            'tax_amount' => '2.345',
            'discount_amount' => '0.000',
            'cash_rounding_adjustment' => null,
            'total' => '12.3450',
        ]);

        $response = $this->getJson('/api/v1/pos/receipts?from_date=2026-08-17&to_date=2026-08-17');

        $response->assertOk();
        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertSame(['data', 'meta'], array_keys($response->json('data')));
        $this->assertSame(
            [
                'id', 'receipt_number', 'posted_at', 'invoice_type_code', 'receipt_type',
                'training_flag', 'is_voided', 'fiscal_status', 'location_id', 'location_name',
                'terminal_id', 'terminal_code', 'cashier_id', 'cashier_name', 'total',
                'currency', 'original_receipt_id',
            ],
            array_keys($response->json('data.data.0')),
        );
        $this->assertSame(
            ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
            array_keys($response->json('data.meta')),
        );
        $response->assertJsonPath('data.meta.from', '2026-08-16T23:00:00.000000Z');
        $response->assertJsonPath('data.meta.to', '2026-08-17T23:00:00.000000Z');
        $response->assertJsonPath('data.data.0.currency', 'TND');
        $response->assertJsonPath('data.data.0.total', '12.345');
    }
}
