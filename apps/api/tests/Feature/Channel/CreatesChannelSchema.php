<?php

declare(strict_types=1);

namespace Tests\Feature\Channel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait CreatesChannelSchema
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropChannelTables();
        $this->dropBaseTables();
        $this->createBaseTables();
        $this->seedTenantRow();
        $this->runTenantMigrations();
    }

    protected function tearDown(): void
    {
        $this->dropChannelTables();
        $this->dropBaseTables();

        parent::tearDown();
    }

    /**
     * @return array{tenant_id: string, company_id: string, product_id: string, document_id: string}
     */
    protected function seedBaseRows(): array
    {
        $ids = [
            'tenant_id' => 'tenant-1',
            'company_id' => '11111111-1111-1111-1111-111111111111',
            'product_id' => '22222222-2222-2222-2222-222222222222',
            'document_id' => '33333333-3333-3333-3333-333333333333',
        ];

        DB::table('companies')->insert([
            'id' => $ids['company_id'],
            'tenant_id' => $ids['tenant_id'],
        ]);
        DB::table('products')->insert([
            'id' => $ids['product_id'],
            'company_id' => $ids['company_id'],
            'name' => 'Mapped product',
            'sku' => 'MAP-1',
        ]);
        DB::table('documents')->insert(['id' => $ids['document_id']]);

        return $ids;
    }

    private function runTenantMigrations(): void
    {
        // Match ONLY the channel create migrations. After T6 Phase 0b moved
        // tenant-scoped migrations into migrations/tenant/, a broader glob
        // (e.g. 2026_05_24_12000*_*) also matched unrelated migrations sharing
        // the 120000 timestamp (add_fiscal_event_linkage_to_pos_z_reports),
        // which fail against this trait's minimal schema.
        foreach (glob(database_path('migrations/tenant/2026_05_24_12000*_create_channel*.php')) ?: [] as $path) {
            $instance = require $path;
            $instance->{'up'}();
        }
    }

    private function createBaseTables(): void
    {
        Schema::create('tenants', function ($table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('active');
            $table->string('plan')->default('trial');
            $table->json('data')->nullable();
            $table->timestamps();
        });

        Schema::create('companies', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id');
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('products', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('sku')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('documents', function ($table): void {
            $table->uuid('id')->primary();
        });

        // CENTRAL pointer that makes the unauthenticated channel webhook
        // routable (channel_id -> tenant_id). Maintained by
        // ChannelWebhookDirectoryObserver on every Channel create/delete, so it
        // has to exist for any test that mints a Channel.
        Schema::create('channel_webhook_directory', function ($table): void {
            $table->uuid('channel_id')->primary();
            $table->uuid('tenant_id');
            $table->timestamps();
        });
    }

    private function dropChannelTables(): void
    {
        Schema::dropIfExists('channel_sync_operations');
        Schema::dropIfExists('channel_orders');
        Schema::dropIfExists('channel_product_mappings');
        Schema::dropIfExists('channel_credentials');
        Schema::dropIfExists('channels');
    }

    private function dropBaseTables(): void
    {
        Schema::dropIfExists('channel_webhook_directory');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('products');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('tenants');
    }

    private function seedTenantRow(): void
    {
        DB::table('tenants')->insert([
            'id' => 'tenant-1',
            'name' => 'Test tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
            'plan' => 'trial',
            'data' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
