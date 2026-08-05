<?php

declare(strict_types=1);

namespace Tests\Feature\Channel;

use App\Modules\Channel\Domain\Enums\ChannelConnectionStatus;
use App\Modules\Channel\Domain\Enums\ChannelOrderStatus;
use App\Modules\Channel\Domain\Enums\CredentialType;
use App\Modules\Channel\Domain\Enums\SyncOperationStatus;
use App\Modules\Channel\Domain\Enums\SyncOperationType;
use App\Modules\Channel\Domain\Models\Channel;
use App\Modules\Channel\Domain\Models\ChannelCredential;
use App\Modules\Channel\Domain\Models\ChannelOrder;
use App\Modules\Channel\Domain\Models\ChannelProductMapping;
use App\Modules\Channel\Domain\Models\ChannelSyncOperation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ChannelPersistenceTest extends TestCase
{
    private const TENANT_MIGRATIONS = [
        '2026_05_24_120000_create_channels_table.php',
        '2026_05_24_120001_create_channel_credentials_table.php',
        '2026_05_24_120002_create_channel_product_mappings_table.php',
        '2026_05_24_120003_create_channel_orders_table.php',
        '2026_05_24_120004_create_channel_sync_operations_table.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropChannelTables();
        $this->dropBaseTables();
        $this->createBaseTables();
        $this->runTenantMigrations();
    }

    protected function tearDown(): void
    {
        $this->dropChannelTables();
        $this->dropBaseTables();

        parent::tearDown();
    }

    public function test_all_channel_migrations_live_in_tenant_directory_and_do_not_reference_central_tenants(): void
    {
        foreach (self::TENANT_MIGRATIONS as $migration) {
            $path = database_path('migrations/tenant/'.$migration);

            $this->assertFileExists($path);
            $contents = (string) file_get_contents($path);
            $this->assertStringNotContainsString("constrained('tenants')", $contents);
            $this->assertStringNotContainsString('constrained("tenants")', $contents);
            $this->assertStringNotContainsString("constrained('companies')", $contents);
            $this->assertStringNotContainsString('constrained("companies")', $contents);
            $this->assertStringNotContainsString("constrained('products')", $contents);
            $this->assertStringNotContainsString('constrained("products")', $contents);
            $this->assertStringNotContainsString("constrained('documents')", $contents);
            $this->assertStringNotContainsString('constrained("documents")', $contents);
            $this->assertStringNotContainsString("on('tenants')", $contents);
            $this->assertStringNotContainsString('on("tenants")', $contents);
        }
    }

    public function test_channel_models_cast_enums_and_json_payloads(): void
    {
        $ids = $this->seedBaseRows();

        $channel = Channel::query()->create([
            'company_id' => $ids['company_id'],
            'name' => 'Demo channel',
            'adapter_type' => 'example_test',
            'is_active' => true,
            'connection_status' => ChannelConnectionStatus::Pending,
            'metadata' => ['region' => 'tn'],
        ]);

        $mapping = ChannelProductMapping::query()->create([
            'channel_id' => $channel->id,
            'product_id' => $ids['product_id'],
            'variant_id' => null,
            'is_published' => true,
            'external_id' => 'EXT-1',
            'last_sync_hash' => hash('sha256', 'payload'),
            'price_override' => '42.500',
            'quantity_cap' => 8,
        ]);

        $order = ChannelOrder::query()->create([
            'channel_id' => $channel->id,
            'external_order_id' => 'ORDER-1',
            'received_at' => now(),
            'status' => ChannelOrderStatus::Pending,
            'payload' => ['total' => '42.500'],
        ]);

        $operation = ChannelSyncOperation::query()->create([
            'channel_id' => $channel->id,
            'operation_type' => SyncOperationType::ProductPush,
            'payload_hash' => hash('sha256', 'payload'),
            'idempotency_key' => 'tenant:channel:product',
            'status' => SyncOperationStatus::Pending,
            'attempt_count' => 0,
        ]);

        $this->assertSame(ChannelConnectionStatus::Pending, $channel->refresh()->connection_status);
        $this->assertSame(['region' => 'tn'], $channel->metadata);
        $this->assertTrue($mapping->refresh()->is_published);
        $this->assertSame(ChannelOrderStatus::Pending, $order->refresh()->status);
        $this->assertSame(['total' => '42.500'], $order->payload);
        $this->assertSame(SyncOperationType::ProductPush, $operation->refresh()->operation_type);
        $this->assertSame(SyncOperationStatus::Pending, $operation->status);
    }

    public function test_channel_credentials_encrypt_payload_at_rest(): void
    {
        $ids = $this->seedBaseRows();
        $channel = Channel::query()->create([
            'company_id' => $ids['company_id'],
            'name' => 'Credential channel',
            'adapter_type' => 'example_test',
            'connection_status' => ChannelConnectionStatus::Connected,
        ]);

        $credential = ChannelCredential::query()->create([
            'channel_id' => $channel->id,
            'credential_type' => CredentialType::ApiKey,
            'encrypted_payload' => ['api_key' => 'secret-token'],
            'expires_at' => now()->addDay(),
        ]);

        $raw = DB::table('channel_credentials')->where('id', $credential->id)->value('encrypted_payload');

        $this->assertIsString($raw);
        $this->assertStringNotContainsString('secret-token', $raw);
        $this->assertSame(['api_key' => 'secret-token'], $credential->refresh()->encrypted_payload);
        $this->assertSame(CredentialType::ApiKey, $credential->credential_type);
    }

    private function runTenantMigrations(): void
    {
        foreach (self::TENANT_MIGRATIONS as $migration) {
            $path = database_path('migrations/tenant/'.$migration);
            $instance = require $path;

            $this->assertInstanceOf(Migration::class, $instance);
            $this->assertTrue(method_exists($instance, 'up'));
            $instance->{'up'}();
        }
    }

    private function createBaseTables(): void
    {
        Schema::create('companies', function ($table): void {
            $table->uuid('id')->primary();
            // Read by ChannelWebhookDirectoryRegistrar when a Channel is
            // created, to resolve the owning tenant for the central pointer.
            $table->string('tenant_id')->nullable();
        });

        Schema::create('products', function ($table): void {
            $table->uuid('id')->primary();
        });

        Schema::create('documents', function ($table): void {
            $table->uuid('id')->primary();
        });

        Schema::create('channel_webhook_directory', function ($table): void {
            $table->uuid('channel_id')->primary();
            $table->uuid('tenant_id');
            $table->timestamps();
        });
    }

    /**
     * @return array{company_id: string, product_id: string, document_id: string}
     */
    private function seedBaseRows(): array
    {
        $ids = [
            'company_id' => '11111111-1111-1111-1111-111111111111',
            'product_id' => '22222222-2222-2222-2222-222222222222',
            'document_id' => '33333333-3333-3333-3333-333333333333',
        ];

        DB::table('companies')->insert(['id' => $ids['company_id']]);
        DB::table('products')->insert(['id' => $ids['product_id']]);
        DB::table('documents')->insert(['id' => $ids['document_id']]);

        return $ids;
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
    }
}
