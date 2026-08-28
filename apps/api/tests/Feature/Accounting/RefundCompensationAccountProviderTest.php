<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\RefundCompensationAccountProvider;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RefundCompensationAccountProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_drifted_purpose_holder_is_reported_and_left_untouched(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'country_code' => 'TN',
        ]);
        $salesReturn = Account::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => '709-LOCAL',
            'name' => 'Accountant-selected returns',
            'type' => AccountType::Expense,
            'system_purpose' => SystemAccountPurpose::SalesReturn,
            'is_system' => false,
        ]);
        Account::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => '6590',
            'name' => 'Perte sur remboursement (write-off)',
            'type' => AccountType::Expense,
            'system_purpose' => SystemAccountPurpose::RefundWriteOff,
            'is_system' => true,
        ]);

        $drifts = app(RefundCompensationAccountProvider::class)->provisionNewCompany(
            $company->id,
            $tenant->id,
            $company->country_code,
        );

        self::assertSame([
            [
                'company_id' => $company->id,
                'purpose' => SystemAccountPurpose::SalesReturn->value,
                'expected' => [
                    'code' => '709',
                    'name' => 'Rabais, remises et ristournes accordés',
                ],
                'actual' => [
                    'code' => '709-LOCAL',
                    'name' => 'Accountant-selected returns',
                ],
            ],
        ], $drifts);
        self::assertSame('709-LOCAL', $salesReturn->refresh()->code);
        self::assertSame('Accountant-selected returns', $salesReturn->name);
        self::assertFalse($salesReturn->is_system);
    }
}
