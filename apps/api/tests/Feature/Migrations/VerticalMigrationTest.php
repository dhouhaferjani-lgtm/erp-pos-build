<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VerticalMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenants_table_has_vertical_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('tenants', 'vertical'),
            'tenants table is missing vertical column'
        );
    }

    public function test_vertical_column_is_string_type(): void
    {
        $columnType = Schema::getColumnType('tenants', 'vertical');

        $this->assertContains(
            $columnType,
            ['string', 'varchar'],
            'vertical column should be string/varchar type'
        );
    }

    public function test_tenants_table_has_enabled_extras_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('tenants', 'enabled_extras'),
            'tenants table is missing enabled_extras column'
        );
    }

    public function test_enabled_extras_column_is_json_type(): void
    {
        $columnType = Schema::getColumnType('tenants', 'enabled_extras');

        $this->assertContains(
            $columnType,
            ['json', 'jsonb', 'text'],
            'enabled_extras column should be JSON/JSONB type'
        );
    }

    public function test_tenants_table_has_signup_source_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('tenants', 'signup_source'),
            'tenants table is missing signup_source column'
        );
    }

    public function test_signup_source_column_is_nullable(): void
    {
        // Test by attempting to insert with null value
        $tenant = \App\Modules\Tenant\Domain\Tenant::factory()->create([
            'signup_source' => null,
        ]);

        $this->assertNull(
            $tenant->signup_source,
            'signup_source column should accept null values'
        );
    }

    public function test_tenants_table_has_signup_tracking_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('tenants', 'signup_tracking'),
            'tenants table is missing signup_tracking column'
        );
    }

    public function test_signup_tracking_column_is_json_type(): void
    {
        $columnType = Schema::getColumnType('tenants', 'signup_tracking');

        $this->assertContains(
            $columnType,
            ['json', 'jsonb', 'text'],
            'signup_tracking column should be JSON/JSONB type'
        );
    }

    public function test_vertical_column_has_default_value(): void
    {
        // Test by creating a tenant without specifying vertical in attributes
        $tenant = \App\Modules\Tenant\Domain\Tenant::factory()->make();

        // Remove vertical from attributes to test database default
        $attributes = $tenant->getAttributes();
        unset($attributes['vertical']);

        $tenant = new \App\Modules\Tenant\Domain\Tenant($attributes);
        $tenant->save();

        // Refresh from database to get default value
        $tenant->refresh();

        // Should default to 'retail' based on migration
        $this->assertEquals(
            'retail',
            $tenant->vertical,
            'vertical column should default to retail'
        );
    }
}
