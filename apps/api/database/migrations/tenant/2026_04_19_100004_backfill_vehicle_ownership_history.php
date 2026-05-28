<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Backfill vehicle_ownership_history from the legacy vehicles.partner_id column.
 *
 * For every vehicle whose partner_id points at a customer-type or both-type partner
 * and which has no existing open ownership row, insert an "initial_registration"
 * open row dated at the vehicle's creation time.
 *
 * Driver-aware: PostgreSQL uses a single INSERT ... SELECT with gen_random_uuid();
 * SQLite (testing) requires row-by-row PHP-generated UUIDs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicles') || ! Schema::hasTable('vehicle_ownership_history')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                INSERT INTO vehicle_ownership_history (id, tenant_id, company_id, vehicle_id, owner_partner_id, acquired_at, released_at, reason_code, notes, created_at, updated_at)
                SELECT
                    gen_random_uuid(),
                    v.tenant_id,
                    v.company_id,
                    v.id,
                    v.partner_id,
                    COALESCE(v.created_at, NOW()),
                    NULL,
                    'initial_registration',
                    'Backfilled from vehicles.partner_id on 2026-04-19',
                    NOW(),
                    NOW()
                FROM vehicles v
                JOIN partners p ON p.id = v.partner_id
                WHERE v.partner_id IS NOT NULL
                  AND v.deleted_at IS NULL
                  AND p.type IN ('customer', 'both')
                  AND NOT EXISTS (
                      SELECT 1 FROM vehicle_ownership_history voh
                      WHERE voh.vehicle_id = v.id AND voh.released_at IS NULL
                  )
            SQL);

            return;
        }

        // SQLite/MySQL fallback: row-by-row.
        $rows = DB::table('vehicles')
            ->join('partners', 'partners.id', '=', 'vehicles.partner_id')
            ->whereNotNull('vehicles.partner_id')
            ->whereNull('vehicles.deleted_at')
            ->whereIn('partners.type', ['customer', 'both'])
            ->select(
                'vehicles.id as vehicle_id',
                'vehicles.tenant_id',
                'vehicles.company_id',
                'vehicles.partner_id',
                'vehicles.created_at',
            )
            ->get();

        foreach ($rows as $row) {
            $alreadyOpen = DB::table('vehicle_ownership_history')
                ->where('vehicle_id', $row->vehicle_id)
                ->whereNull('released_at')
                ->exists();
            if ($alreadyOpen) {
                continue;
            }

            DB::table('vehicle_ownership_history')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $row->tenant_id,
                'company_id' => $row->company_id,
                'vehicle_id' => $row->vehicle_id,
                'owner_partner_id' => $row->partner_id,
                'acquired_at' => $row->created_at ?? now(),
                'released_at' => null,
                'reason_code' => 'initial_registration',
                'notes' => 'Backfilled from vehicles.partner_id on 2026-04-19',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('vehicle_ownership_history')
            ->where('notes', 'Backfilled from vehicles.partner_id on 2026-04-19')
            ->delete();
    }
};
