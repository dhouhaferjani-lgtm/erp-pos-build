<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OnboardingChecklistsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_checklists_table_exists(): void
    {
        $this->assertTrue(
            Schema::hasTable('onboarding_checklists'),
            'onboarding_checklists table does not exist'
        );
    }

    public function test_onboarding_checklists_has_required_columns(): void
    {
        $requiredColumns = [
            'id',
            'tenant_id',
            'step_key',
            'title',
            'is_required',
            'is_completed',
            'completed_at',
            'order',
            'created_at',
            'updated_at',
        ];

        foreach ($requiredColumns as $column) {
            $this->assertTrue(
                Schema::hasColumn('onboarding_checklists', $column),
                "onboarding_checklists table is missing {$column} column"
            );
        }
    }

    public function test_step_key_is_string_type(): void
    {
        $columnType = Schema::getColumnType('onboarding_checklists', 'step_key');

        $this->assertContains(
            $columnType,
            ['string', 'varchar'],
            'step_key column should be string/varchar type'
        );
    }

    public function test_title_is_string_type(): void
    {
        $columnType = Schema::getColumnType('onboarding_checklists', 'title');

        $this->assertContains(
            $columnType,
            ['string', 'varchar'],
            'title column should be string/varchar type'
        );
    }

    public function test_is_required_is_boolean_type(): void
    {
        $columnType = Schema::getColumnType('onboarding_checklists', 'is_required');

        $this->assertContains(
            $columnType,
            ['boolean', 'tinyint'],
            'is_required column should be boolean type'
        );
    }

    public function test_is_completed_is_boolean_type(): void
    {
        $columnType = Schema::getColumnType('onboarding_checklists', 'is_completed');

        $this->assertContains(
            $columnType,
            ['boolean', 'tinyint'],
            'is_completed column should be boolean type'
        );
    }

    public function test_completed_at_is_nullable(): void
    {
        // Test by creating a record without completed_at
        $tenant = \App\Modules\Tenant\Domain\Tenant::factory()->create();

        \DB::table('onboarding_checklists')->insert([
            'tenant_id' => $tenant->id,
            'step_key' => 'test_step',
            'title' => 'Test Step',
            'is_required' => false,
            'is_completed' => false,
            'completed_at' => null,
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $record = \DB::table('onboarding_checklists')->first();
        $this->assertNull($record->completed_at);
    }

    public function test_tenant_id_step_key_unique_constraint(): void
    {
        $tenant = \App\Modules\Tenant\Domain\Tenant::factory()->create();

        // Insert first record
        \DB::table('onboarding_checklists')->insert([
            'tenant_id' => $tenant->id,
            'step_key' => 'same_key',
            'title' => 'Test Step 1',
            'is_required' => false,
            'is_completed' => false,
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Expect duplicate key exception
        $this->expectException(\Illuminate\Database\QueryException::class);

        // Attempt to insert duplicate
        \DB::table('onboarding_checklists')->insert([
            'tenant_id' => $tenant->id,
            'step_key' => 'same_key',
            'title' => 'Test Step 2',
            'is_required' => false,
            'is_completed' => false,
            'order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_order_column_default_value(): void
    {
        $tenant = \App\Modules\Tenant\Domain\Tenant::factory()->create();

        \DB::table('onboarding_checklists')->insert([
            'tenant_id' => $tenant->id,
            'step_key' => 'test_order',
            'title' => 'Test Order',
            'is_required' => false,
            'is_completed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $record = \DB::table('onboarding_checklists')->where('step_key', 'test_order')->first();
        $this->assertEquals(0, $record->order);
    }
}
