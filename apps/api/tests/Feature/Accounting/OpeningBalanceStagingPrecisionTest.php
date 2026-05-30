<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\OpeningBalanceBatchService;
use App\Modules\Accounting\Domain\Enums\OpeningBatchStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 5 — JSONB content tightening for opening-balance staging.
 *
 * Numeric values written into the `raw_data` JSONB column must be pre-canonicalized
 * to numeric-strings BEFORE json_encode, with JSON_PRESERVE_ZERO_FRACTION so trailing
 * zeros survive the round-trip. Money fields canonicalize at the fixed STORAGE scale 3
 * (matching the 3dp ingress regex and the decimal(N,3) journal-posting target columns),
 * NOT the per-currency display scale; quantity is always scale 4.
 */
class OpeningBalanceStagingPrecisionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private OpeningBalanceBatchService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Staging Precision Tenant',
            'slug' => 'staging-precision-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Staging Precision Co',
            'legal_name' => 'Staging Precision Co LLC',
            'tax_id' => 'TAX-SP-123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Staging Admin',
            'email' => 'staging-admin@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->service = app(OpeningBalanceBatchService::class);
    }

    private function makeBatch(OpeningBatchType $type): OpeningBalanceBatch
    {
        return OpeningBalanceBatch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => $type,
            'name' => 'Staging batch',
            'cutover_date' => Carbon::parse('2026-01-01'),
            'status' => OpeningBatchStatus::Draft,
            'created_by' => (string) $this->user->id,
        ]);
    }

    /**
     * Read the raw JSON text of raw_data straight from the DB (bypass Eloquent casts).
     */
    private function rawJsonForBatch(string $batchId): string
    {
        $value = DB::table('opening_balance_import_rows')
            ->where('batch_id', $batchId)
            ->orderBy('row_number')
            ->value('raw_data');

        return is_string($value) ? $value : (string) json_encode($value);
    }

    public function test_accounting_money_field_trailing_zero_preserved_in_jsonb(): void
    {
        $batch = $this->makeBatch(OpeningBatchType::Accounting);

        $this->service->addImportRows($batch, [
            [
                'account_code' => '1200',
                'debit' => '10000.10',
                'credit' => '0',
                'description' => 'Bank opening',
            ],
        ]);

        $json = $this->rawJsonForBatch($batch->id);

        // Money fields canonicalize at the fixed storage scale 3 (NOT EUR display scale 2).
        // The literal stored value must keep its precision (not "10000.1").
        $this->assertStringContainsString('"debit":"10000.100"', $json);
        $this->assertStringContainsString('"credit":"0.000"', $json);
    }

    public function test_eur_money_field_preserves_third_decimal_not_truncated_to_currency_scale(): void
    {
        // Regression: canonicalizing EUR (display scale 2) money fields at the
        // currency scale silently truncated the 3rd decimal the ingress regex
        // accepts (10000.105 -> 10000.10) before it reached the decimal(N,3)
        // journal-posting columns. Money must stage at the fixed storage scale 3.
        $batch = $this->makeBatch(OpeningBatchType::Accounting);

        $this->service->addImportRows($batch, [
            [
                'account_code' => '1200',
                'debit' => '10000.105',
                'credit' => '0',
                'description' => 'Three-decimal EUR amount',
            ],
        ]);

        $json = $this->rawJsonForBatch($batch->id);

        // The 3rd decimal survives — NOT silently truncated to "10000.10".
        $this->assertStringContainsString('"debit":"10000.105"', $json);
        $this->assertStringNotContainsString('"debit":"10000.10"', $json);
    }

    public function test_inventory_quantity_trailing_zeros_preserved_at_scale_four(): void
    {
        $batch = $this->makeBatch(OpeningBatchType::Inventory);

        $this->service->addImportRows($batch, [
            [
                'product_code' => 'SKU-001',
                'location_code' => 'MAIN',
                'quantity' => '2.5000',
                'unit_cost' => '25.5',
            ],
        ]);

        $json = $this->rawJsonForBatch($batch->id);

        // Quantity always scale 4; unit_cost money at fixed storage scale 3.
        $this->assertStringContainsString('"quantity":"2.5000"', $json);
        $this->assertStringContainsString('"unit_cost":"25.500"', $json);
    }

    public function test_non_numeric_fields_are_left_untouched(): void
    {
        $batch = $this->makeBatch(OpeningBatchType::Accounting);

        $this->service->addImportRows($batch, [
            [
                'account_code' => '1200',
                'debit' => '5',
                'credit' => '0',
                'description' => 'Keep me as text',
            ],
        ]);

        $json = $this->rawJsonForBatch($batch->id);

        $this->assertStringContainsString('"account_code":"1200"', $json);
        $this->assertStringContainsString('"description":"Keep me as text"', $json);
        $this->assertStringContainsString('"debit":"5.000"', $json);
    }
}
