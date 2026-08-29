<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use JsonException;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class RegistrationResponseIsPureJsonTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const array CENSUS_MIGRATIONS = [
        '2026_08_26_100100_null_invented_default_lot_expiries',
        '2026_08_27_100000_census_cash_tender_invariant_violations',
        '2026_08_28_100000_enforce_company_scoped_payment_method_codes',
        '2026_08_29_100000_backfill_default_location_code_f1',
        '2026_08_30_100000_enforce_company_scoped_product_skus',
        '2026_08_30_100100_enforce_company_scoped_variant_skus',
        '2026_08_30_100200_enforce_company_scoped_partner_vat_numbers',
        '2026_08_30_100300_ensure_units_visible_per_company',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CountriesSeeder::class);
    }

    /** @throws JsonException */
    public function test_registration_response_is_unprefixed_json(): void
    {
        $response = $this->register('pure-json@example.com', 'Pure JSON Company');
        $content = $response->getContent();

        $response->assertCreated();
        self::assertIsString($content);
        self::assertTrue(
            str_starts_with(ltrim($content), '{'),
            'Registration response must start with JSON; first 120 characters: '.substr($content, 0, 120),
        );

        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['data'] ?? null);
        self::assertArrayHasKey('user', $decoded['data']);
    }

    public function test_dispatching_tenant_migrations_during_tests_emits_no_stdout(): void
    {
        $response = $this->register('migration-output@example.com', 'Migration Output Company');
        $response->assertCreated();

        $tenant = Tenant::query()->where('name', 'Migration Output Company')->firstOrFail();

        // RefreshDatabase has already applied tenant migrations to the shared
        // SQLite/compat connection. Forget only the census migrations so the
        // real Stancl MigrateDatabase job deterministically executes them again.
        DB::table('migrations')->whereIn('migration', self::CENSUS_MIGRATIONS)->delete();

        ob_start();
        try {
            Bus::dispatchSync(new MigrateDatabase($tenant));
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(
            '',
            $output,
            'Synchronous tenant migrations must not emit census bytes; first 120 characters: '.substr((string) $output, 0, 120),
        );
    }

    /** @return TestResponse<Response> */
    private function register(string $email, string $companyName): TestResponse
    {
        return $this->postJson('/api/v1/auth/register', [
            'name' => 'Registration User',
            'email' => $email,
            'password' => 'MyStr0ng!Pass',
            'password_confirmation' => 'MyStr0ng!Pass',
            'company_name' => $companyName,
            'country_code' => 'FR',
            'vertical' => 'retail',
        ]);
    }
}
