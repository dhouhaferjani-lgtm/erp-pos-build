<?php

declare(strict_types=1);

namespace Tests\Feature\POS\Support;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

abstract class ReceiptReportingTestCase extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Company $company;

    protected Location $location;

    protected User $user;

    protected Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'timezone' => 'Africa/Tunis',
        ]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
            'allowed_location_ids' => null,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.view_receipts', 'sanctum');
        $this->user->givePermissionTo('pos.view_receipts');
        Sanctum::actingAs($this->user);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createReceipt(
        string $invoiceTypeCode = 'SALE',
        bool $training = false,
        ?Location $location = null,
        FiscalStatus $fiscalStatus = FiscalStatus::Fiscalized,
        ?string $postedAt = null,
        array $attributes = [],
    ): Receipt {
        $location ??= $this->location;

        $terminal = $location->is($this->location)
            ? $this->terminal
            : Terminal::factory()->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'location_id' => $location->id,
            ]);

        return Receipt::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->user->id,
            'invoice_type_code' => $invoiceTypeCode,
            'training_flag' => $training,
            'fiscal_status' => $fiscalStatus,
            'posted_at' => $postedAt ?? now(),
        ], $attributes));
    }
}
