<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Bank;
use Database\Seeders\BanksSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BankDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_tunisian_bank_seed_is_idempotent_and_repairs_changed_fields(): void
    {
        self::assertTrue(class_exists(BanksSeeder::class), 'BanksSeeder must exist.');

        $seeder = $this->app->make(BanksSeeder::class);
        $seeder->run($this->company);

        $initialCount = Bank::query()->count();
        $amen = Bank::query()->where('rib_bank_code', '07')->firstOrFail();

        self::assertSame('AMEN BANK', $amen->name);
        self::assertSame('CFCTTNTT', $amen->bic);

        $amen->forceFill([
            'name' => 'Stale Amen Name',
            'bic' => 'STALETNT',
        ])->save();

        $seeder->run($this->company);

        self::assertSame($initialCount, Bank::query()->count());
        self::assertGreaterThan(20, $initialCount);
        self::assertDatabaseHas('banks', [
            'id' => $amen->id,
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
            'rib_bank_code' => '07',
            'name' => 'AMEN BANK',
            'bic' => 'CFCTTNTT',
            'is_custom' => false,
        ]);
    }

    public function test_authenticated_user_can_search_active_banks_without_a_bank_permission(): void
    {
        self::assertTrue(class_exists(BanksSeeder::class), 'BanksSeeder must exist.');

        $this->app->make(BanksSeeder::class)->run($this->company);
        Bank::query()->where('rib_bank_code', '07')->update(['is_active' => false]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/banks?country=tn&q=internationale');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.country_code', 'TN');

        $names = collect($response->json('data'))->pluck('name');
        self::assertTrue($names->contains('BANQUE INTERNATIONALE ARABE DE TUNISIE'));
        self::assertTrue($names->contains('UNION INTERNATIONALE DE BANQUES'));
        self::assertFalse($names->contains('AMEN BANK'));
    }
}
