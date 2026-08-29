<?php

declare(strict_types=1);

namespace Tests\Feature\Import\RoundTrip;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\OpeningBatchStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class OpeningBalancesRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Account $cashAccount;

    private Account $obeAccount;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->tenant = Tenant::create([
            'name' => 'Opening Balances Round Trip Tenant',
            'slug' => 'opening-balances-round-trip-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Opening Balances Round Trip Company',
            'legal_name' => 'Opening Balances Round Trip Company LLC',
            'tax_id' => 'TAX-OPENING-ROUND-TRIP',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'fiscal_year_start_month' => 4,
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Opening Balances Import Admin',
            'email' => 'opening-balances-round-trip@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');
        $this->user->givePermissionTo('accounts.manage');

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
        $this->cashAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '512000',
            'name' => 'Cash',
            'type' => AccountType::Asset,
        ]);
        $this->obeAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '890000',
            'name' => 'Opening Balance Equity',
            'type' => AccountType::Equity,
            'system_purpose' => SystemAccountPurpose::OpeningBalanceEquity,
            'is_system' => true,
        ]);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  list<string>  $lines
     */
    #[DataProvider('numberConventionProvider')]
    public function test_opening_balances_round_trip_posts_identical_eu_and_us_amounts(array $lines): void
    {
        $jobId = $this->runImport($lines, ImportType::OpeningBalances->value);

        $batch = OpeningBalanceBatch::query()
            ->where('company_id', $this->company->id)
            ->where('type', OpeningBatchType::Accounting)
            ->firstOrFail();
        $this->assertSame(OpeningBatchStatus::Locked, $batch->status);
        $this->assertSame('unified-import', $batch->source_system);
        // m-6: assertEquals (not …Canonicalizing) so values attached to the wrong key fail.
        // NOT assertSame: PostgreSQL jsonb does not preserve key order.
        $this->assertEquals(
            ['import_job_id' => $jobId, 'source' => 'unified-import'],
            $batch->import_file_reference,
        );

        // m-4: cardinality first — a double-post regression must fail here, not pass silently.
        $this->assertSame(1, JournalEntry::query()->where('company_id', $this->company->id)->count());
        $entry = JournalEntry::query()->where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame('opening_balance', $entry->source_type);
        $this->assertSame($batch->id, $entry->source_id);
        $this->assertTrue($entry->is_historical);

        $linesByAccount = $entry->lines()->get()->keyBy('account_id');
        $this->assertCount(2, $linesByAccount);
        $cashLine = $linesByAccount->get($this->cashAccount->id);
        $obeLine = $linesByAccount->get($this->obeAccount->id);
        $this->assertNotNull($cashLine);
        $this->assertNotNull($obeLine);
        $this->assertSame(0, bccomp($cashLine->debit, '12.500', 3));
        $this->assertSame(0, bccomp($cashLine->credit, '0', 3));
        $this->assertSame(0, bccomp($obeLine->debit, '0', 3));
        $this->assertSame(0, bccomp($obeLine->credit, '12.500', 3));
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function numberConventionProvider(): array
    {
        return [
            'European semicolon and decimal comma' => [[
                'account_code;debit;credit;description;reference',
                '512000;12,500;0,000;Solde initial caisse;EU-GL',
            ]],
            'US comma and decimal point' => [[
                'account_code,debit,credit,description,reference',
                '512000,12.500,0.000,Opening cash balance,US-GL',
            ]],
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
