<?php

declare(strict_types=1);

namespace Tests\Feature\Import\RoundTrip;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Enums\OpeningBatchStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PartiesRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Parties Round Trip Tenant',
            'slug' => 'parties-round-trip-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Parties Round Trip Company',
            'legal_name' => 'Parties Round Trip Company LLC',
            'tax_id' => 'TAX-PARTIES-ROUND-TRIP',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
        ]);

        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Parties Import Admin',
            'email' => 'parties-round-trip@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        Location::create([
            'company_id' => $this->company->id,
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'type' => 'warehouse',
            'is_default' => true,
            'is_active' => true,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        Storage::fake('local');
    }

    /**
     * @param  list<string>  $lines
     */
    #[DataProvider('numberConventionProvider')]
    public function test_parties_round_trip_persists_every_mapped_field_and_opening_balance(
        array $lines,
        string $code,
        string $name,
        string $email,
        string $country,
    ): void {
        $jobId = $this->runImport($lines, ImportType::Parties->value);

        $partner = Partner::query()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
        $this->assertSame($name, $partner->name);
        $this->assertSame(PartnerType::Customer, $partner->type);
        $this->assertSame($code, $partner->code);
        $this->assertSame($email, $partner->email);
        $this->assertSame('+21670000000', $partner->phone);
        $this->assertSame('VAT-ROUND-TRIP', $partner->vat_number);
        $this->assertSame('12 Import Street', $partner->street_address);
        $this->assertSame('Tunis', $partner->city);
        $this->assertSame('1002', $partner->postal_code);
        $this->assertSame($country, $partner->country);

        // m-4: cardinality first — a double-post regression must fail here, not pass silently.
        $this->assertSame(1, Document::query()->where('company_id', $this->company->id)->where('partner_id', $partner->id)->count());
        $document = Document::query()->where('company_id', $this->company->id)->where('partner_id', $partner->id)->firstOrFail();
        $this->assertSame(DocumentType::Invoice, $document->type);
        $this->assertTrue($document->is_historical);
        $this->assertNotNull($document->total);
        $this->assertSame(0, bccomp($document->total, '12.500', 3));

        $batch = OpeningBalanceBatch::query()
            ->where('company_id', $this->company->id)
            ->where('type', OpeningBatchType::ArOpenItems)
            ->firstOrFail();
        $this->assertSame(OpeningBatchStatus::Validated, $batch->status);
        $this->assertSame('IMPORT-'.substr($jobId, 0, 8).'-AR', $batch->name);
        // m-6: assertEquals (not …Canonicalizing) so values attached to the wrong key fail.
        // NOT assertSame: PostgreSQL jsonb does not preserve key order.
        $this->assertEquals(
            ['import_job_id' => $jobId, 'source' => 'unified-import'],
            $batch->import_file_reference,
        );
    }

    /**
     * @return array<string, array{list<string>, string, string, string, string}>
     */
    public static function numberConventionProvider(): array
    {
        return [
            'European semicolon and decimal comma' => [
                [
                    'name;type;code;email;phone;tax_id;address_line1;address_city;address_postal_code;address_country;opening_balance;reference',
                    'Client EU;customer;PARTY-EU;client-eu@example.com;+21670000000;VAT-ROUND-TRIP;12 Import Street;Tunis;1002;Tunisie;12,500;EU-OPEN',
                ],
                'PARTY-EU',
                'Client EU',
                'client-eu@example.com',
                'TN',
            ],
            'US comma and decimal point' => [
                [
                    'name,type,code,email,phone,tax_id,address_line1,address_city,address_postal_code,address_country,opening_balance,reference',
                    'US Customer,customer,PARTY-US,customer-us@example.com,+21670000000,VAT-ROUND-TRIP,12 Import Street,Tunis,1002,Tunisia,12.500,US-OPEN',
                ],
                'PARTY-US',
                'US Customer',
                'customer-us@example.com',
                'TN',
            ],
        ];
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, string|bool|list<string>>  $options
     */
    private function runImport(array $lines, string $type, array $options = []): string
    {
        $payload = [
            'file' => UploadedFile::fake()->createWithContent(
                $type.'-'.bin2hex(random_bytes(4)).'.csv',
                implode("\n", $lines),
            ),
            'type' => $type,
        ];
        if ($options !== []) {
            $payload['options'] = $options;
        }

        $createResponse = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/imports', $payload);
        $createResponse->assertCreated();
        $jobId = $createResponse->json('data.id');
        $this->assertIsString($jobId);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertOk()
            ->assertJsonPath('data.failed_rows', 0);

        return $jobId;
    }
}
