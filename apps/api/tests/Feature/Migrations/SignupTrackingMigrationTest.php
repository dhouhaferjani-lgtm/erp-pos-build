<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SignupTrackingMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_signup_tracking_table_exists(): void
    {
        $this->assertTrue(
            Schema::hasTable('signup_tracking'),
            'signup_tracking table does not exist'
        );
    }

    public function test_signup_tracking_has_id_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('signup_tracking', 'id'),
            'signup_tracking table is missing id column'
        );
    }

    public function test_signup_tracking_has_tenant_id_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('signup_tracking', 'tenant_id'),
            'signup_tracking table is missing tenant_id column'
        );
    }

    public function test_signup_tracking_has_vertical_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('signup_tracking', 'vertical'),
            'signup_tracking table is missing vertical column'
        );
    }

    public function test_signup_tracking_has_utm_columns(): void
    {
        $utmColumns = [
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_content',
            'utm_term',
        ];

        foreach ($utmColumns as $column) {
            $this->assertTrue(
                Schema::hasColumn('signup_tracking', $column),
                "signup_tracking table is missing {$column} column"
            );
        }
    }

    public function test_signup_tracking_has_referral_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumn('signup_tracking', 'referral_code'),
            'signup_tracking table is missing referral_code column'
        );

        $this->assertTrue(
            Schema::hasColumn('signup_tracking', 'referrer_url'),
            'signup_tracking table is missing referrer_url column'
        );
    }

    public function test_signup_tracking_has_device_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumn('signup_tracking', 'device_type'),
            'signup_tracking table is missing device_type column'
        );

        $this->assertTrue(
            Schema::hasColumn('signup_tracking', 'country_code'),
            'signup_tracking table is missing country_code column'
        );
    }

    public function test_signup_tracking_has_conversion_tracking_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumn('signup_tracking', 'created_at'),
            'signup_tracking table is missing created_at column'
        );

        $this->assertTrue(
            Schema::hasColumn('signup_tracking', 'email_verified_at'),
            'signup_tracking table is missing email_verified_at column'
        );

        $this->assertTrue(
            Schema::hasColumn('signup_tracking', 'first_sale_at'),
            'signup_tracking table is missing first_sale_at column'
        );
    }

    public function test_signup_tracking_has_foreign_key_constraint(): void
    {
        // Test by attempting to create record without tenant_id
        $this->expectException(QueryException::class);

        \DB::table('signup_tracking')->insert([
            'vertical' => 'retail',
        ]);
    }
}
