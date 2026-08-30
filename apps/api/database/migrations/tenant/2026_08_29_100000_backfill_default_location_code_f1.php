<?php

declare(strict_types=1);

use App\Shared\Database\MigrationOutput;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backfill MAIN on code-less default locations without violating the
     * company/code unique index. A collision is left untouched and emitted as
     * a census line so operators can resolve that tenant explicitly.
     */
    public function up(): void
    {
        if (! Schema::hasTable('locations')) {
            return;
        }

        foreach (['id', 'company_id', 'code', 'is_default'] as $column) {
            if (! Schema::hasColumn('locations', $column)) {
                return;
            }
        }

        $locations = DB::table('locations')
            ->where('is_default', true)
            ->where(static function (Builder $query): void {
                $query->whereNull('code')->orWhere('code', '');
            })
            ->orderBy('id')
            ->cursor();

        foreach ($locations as $location) {
            if (! is_string($location->id) || ! is_string($location->company_id)) {
                continue;
            }

            $hasMainLocation = DB::table('locations')
                ->where('company_id', $location->company_id)
                ->where('id', '!=', $location->id)
                ->where('code', 'MAIN')
                ->exists();

            if ($hasMainLocation) {
                $line = "default-location-code-collision company_id={$location->company_id} location_id={$location->id}";
                MigrationOutput::info($line);

                continue;
            }

            DB::table('locations')
                ->where('id', $location->id)
                ->where('company_id', $location->company_id)
                ->where('is_default', true)
                ->where(static function (Builder $query): void {
                    $query->whereNull('code')->orWhere('code', '');
                })
                ->update([
                    'code' => 'MAIN',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Data backfill: reverting would erase valid location codes.
    }
};
