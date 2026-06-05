<?php

declare(strict_types=1);

namespace Tests\Feature\Channel;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ChannelProductMappingsPartialUniqueTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_product_level_mappings_to_same_channel_should_be_rejected_after_fix(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial unique indexes require PostgreSQL.');
        }

        $channelId = $this->createChannel();
        $productId = '22222222-2222-2222-2222-222222222222';

        $this->insertMapping($channelId, $productId, null);

        $this->expectException(QueryException::class);

        $this->insertMapping($channelId, $productId, null);
    }

    public function test_variant_and_non_variant_mappings_coexist(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial unique indexes require PostgreSQL.');
        }

        $channelId = $this->createChannel();
        $productId = '22222222-2222-2222-2222-222222222222';
        $variantId = '44444444-4444-4444-4444-444444444444';

        $this->insertMapping($channelId, $productId, null);
        $this->insertMapping($channelId, $productId, $variantId);

        $count = DB::table('channel_product_mappings')
            ->where('channel_id', $channelId)
            ->where('product_id', $productId)
            ->count();

        $this->assertSame(2, $count);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createChannel(): string
    {
        $id = Str::uuid()->toString();

        DB::table('channels')->insert([
            'id' => $id,
            'company_id' => '11111111-1111-1111-1111-111111111111',
            'name' => 'Test channel',
            'adapter_type' => 'test',
            'is_active' => true,
            'connection_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertMapping(string $channelId, string $productId, ?string $variantId): void
    {
        DB::table('channel_product_mappings')->insert([
            'id' => Str::uuid()->toString(),
            'channel_id' => $channelId,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'is_published' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
