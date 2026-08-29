<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
                Log::warning($line);
                echo $line.PHP_EOL;

                continue;
            }

            DB::table('locations')
                ->where('id', $location->id)
                ->where('company_id', $location->company_id)
                ->where('is_default', true)
                ->where(static function (Builder $query): void {
                    $query->whereNull('code')->orWhere('code', '');
                })
                ->update(['code' => 'MAIN']);
        }
    }

    public function down(): void
    {
        // Data backfill: reverting would erase valid location codes.
    }
};
