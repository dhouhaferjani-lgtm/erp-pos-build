<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Procurement\Presentation\Requests\CreatePurchaseQuoteRequestRequest;
use App\Modules\Procurement\Presentation\Requests\UpdatePurchaseQuoteRequestRequest;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

final class PurchaseQuoteRequestValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create([
            'name' => 'RFQ Validation Tenant',
            'slug' => 'rfq-validation-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'RFQ Validation Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($company->id);
    }

    public function test_create_request_enforces_partner_count_and_decimal_precision(): void
    {
        $request = new CreatePurchaseQuoteRequestRequest(app(CompanyContext::class));

        $validator = Validator::make([
            'partner_ids' => [],
            'lines' => [
                [
                    'product_id' => 'not-a-uuid',
                    'quantity' => '1.12345',
                    'unit_price' => '8.1234',
                ],
            ],
        ], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('partner_ids', $validator->errors()->toArray());
        $this->assertArrayHasKey('lines.0.product_id', $validator->errors()->toArray());
        $this->assertArrayHasKey('lines.0.quantity', $validator->errors()->toArray());
        $this->assertArrayHasKey('lines.0.unit_price', $validator->errors()->toArray());
    }

    public function test_update_request_rejects_group_id_changes(): void
    {
        $request = new UpdatePurchaseQuoteRequestRequest(app(CompanyContext::class));

        $validator = Validator::make([
            'payload' => [
                'rfq' => [
                    'group_id' => '01908744-7a64-7000-9f61-94b3dff5a999',
                ],
            ],
            'lines' => [
                [
                    'product_id' => '01908744-7a64-7000-9f61-94b3dff5a333',
                    'quantity' => '2.5000',
                    'unit_price' => '8.125',
                ],
            ],
        ], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('payload.rfq.group_id', $validator->errors()->toArray());
    }
}
