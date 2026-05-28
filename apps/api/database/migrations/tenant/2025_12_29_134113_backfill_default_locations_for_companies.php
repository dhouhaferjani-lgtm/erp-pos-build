<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Find all companies without any locations
        $companiesWithoutLocations = DB::table('companies')
            ->leftJoin('locations', 'companies.id', '=', 'locations.company_id')
            ->whereNull('locations.id')
            ->select('companies.*')
            ->get();

        foreach ($companiesWithoutLocations as $company) {
            DB::table('locations')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'name' => 'Main Location',
                'type' => 'warehouse',
                'is_default' => true,
                'is_active' => true,
                'address_street' => $company->address_street ?? null,
                'address_city' => $company->address_city ?? null,
                'address_postal_code' => $company->address_postal_code ?? null,
                'address_country' => $company->country_code ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cannot reliably reverse - locations might have been used
        // Only delete if they were created by this migration (no stock, no documents)
        DB::table('locations')
            ->where('name', 'Main Location')
            ->where('is_default', true)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('stock_levels')
                    ->whereColumn('stock_levels.location_id', 'locations.id');
            })
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('documents')
                    ->whereColumn('documents.location_id', 'locations.id');
            })
            ->delete();
    }
};
