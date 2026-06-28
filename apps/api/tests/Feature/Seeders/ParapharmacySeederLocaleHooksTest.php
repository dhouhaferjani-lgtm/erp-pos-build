<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\ParapharmacySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Characterization test: verifies that extracting locale hooks from
 * ParapharmacySeeder does NOT change France output.
 */
final class ParapharmacySeederLocaleHooksTest extends TestCase
{
    use RefreshDatabase;

    public function test_parapharmacy_seeder_still_produces_french_company(): void
    {
        $this->seed(ParapharmacySeeder::class);

        $tenant = Tenant::where('slug', 'pharmabio-france')->firstOrFail();

        $tenant->run(function () {
            $company = Company::firstOrFail();
            $this->assertSame('FR', $company->country_code);
            $this->assertSame('EUR', $company->currency);
        });
    }
}
