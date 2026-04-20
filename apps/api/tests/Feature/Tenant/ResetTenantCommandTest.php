<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ResetTenantCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->tenant = Tenant::factory()->create([
            'slug' => 'test-shop',
            'country_code' => 'TN',
            'currency_code' => 'TND',
        ]);
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
        ]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
    }

    private function requiresPostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Schema operations require PostgreSQL');
        }
    }

    public function test_command_fails_with_nonexistent_slug(): void
    {
        $this->artisan('tenant:reset', ['slug' => 'does-not-exist'])
            ->assertExitCode(1)
            ->expectsOutputToContain('not found');
    }

    public function test_command_lists_available_tenants_on_invalid_slug(): void
    {
        $this->artisan('tenant:reset', ['slug' => 'does-not-exist'])
            ->assertExitCode(1)
            ->expectsOutputToContain($this->tenant->slug);
    }

    public function test_command_aborts_without_confirmation(): void
    {
        $this->artisan('tenant:reset', ['slug' => 'test-shop'])
            ->expectsConfirmation('Are you sure you want to continue?', 'no')
            ->assertExitCode(0)
            ->expectsOutputToContain('Aborted');
    }

    public function test_command_runs_with_force_flag(): void
    {
        $this->requiresPostgres();

        $schemaName = $this->tenant->getDatabaseName();
        DB::statement("CREATE SCHEMA IF NOT EXISTS \"{$schemaName}\"");

        $this->artisan('tenant:reset', ['slug' => 'test-shop', '--force' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('has been reset successfully');
    }

    public function test_command_drops_and_recreates_schema(): void
    {
        $this->requiresPostgres();

        $schemaName = $this->tenant->getDatabaseName();

        // Create schema with a test table
        DB::statement("CREATE SCHEMA IF NOT EXISTS \"{$schemaName}\"");
        DB::statement("CREATE TABLE \"{$schemaName}\".test_marker (id int)");

        $this->artisan('tenant:reset', ['slug' => 'test-shop', '--force' => true])
            ->assertExitCode(0);

        // The test table should be gone (schema was dropped and recreated)
        $tableExists = DB::select(
            "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = 'test_marker')",
            [$schemaName]
        );

        $this->assertFalse($tableExists[0]->exists);
    }

    public function test_command_reports_fresh_hash_chains(): void
    {
        $this->requiresPostgres();

        $schemaName = $this->tenant->getDatabaseName();
        DB::statement("CREATE SCHEMA IF NOT EXISTS \"{$schemaName}\"");

        $this->artisan('tenant:reset', ['slug' => 'test-shop', '--force' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('hash chains will start fresh');
    }
}
