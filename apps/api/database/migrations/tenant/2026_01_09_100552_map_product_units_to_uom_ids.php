<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        // Get all system units
        $units = DB::table('units')
            ->whereNull('tenant_id')
            ->where('is_system', true)
            ->get()
            ->keyBy('code');

        // Mapping of common text variations to unit codes
        $mapping = [
            'kg' => 'kg',
            'kilogram' => 'kg',
            'kilograms' => 'kg',
            'kilo' => 'kg',
            'g' => 'g',
            'gram' => 'g',
            'grams' => 'g',
            'gramme' => 'g',
            'grammes' => 'g',
            'mg' => 'mg',
            'milligram' => 'mg',
            'milligrams' => 'mg',
            'milligramme' => 'mg',
            'milligrammes' => 'mg',
            'l' => 'l',
            'liter' => 'l',
            'litre' => 'l',
            'liters' => 'l',
            'litres' => 'l',
            'ml' => 'ml',
            'milliliter' => 'ml',
            'millilitre' => 'ml',
            'milliliters' => 'ml',
            'millilitres' => 'ml',
            'cl' => 'cl',
            'centiliter' => 'cl',
            'centilitre' => 'cl',
            'pc' => 'pc',
            'pcs' => 'pc',
            'piece' => 'pc',
            'pieces' => 'pc',
            'pièce' => 'pc',
            'pièces' => 'pc',
            'unit' => 'pc',
            'units' => 'pc',
            'unité' => 'pc',
            'unités' => 'pc',
            'pair' => 'pair',
            'pairs' => 'pair',
            'paire' => 'pair',
            'paires' => 'pair',
            'doz' => 'doz',
            'dozen' => 'doz',
            'douzaine' => 'doz',
            'lb' => 'lb',
            'pound' => 'lb',
            'pounds' => 'lb',
            'oz' => 'oz',
            'ounce' => 'oz',
            'ounces' => 'oz',
            'floz' => 'floz',
            'fl oz' => 'floz',
            'fluid ounce' => 'floz',
            'fluid ounces' => 'floz',
            'mm' => 'mm',
            'millimeter' => 'mm',
            'millimetre' => 'mm',
            'millimeters' => 'mm',
            'millimetres' => 'mm',
            'cm' => 'cm',
            'centimeter' => 'cm',
            'centimetre' => 'cm',
            'centimeters' => 'cm',
            'centimetres' => 'cm',
            'm' => 'm',
            'meter' => 'm',
            'metre' => 'm',
            'meters' => 'm',
            'metres' => 'm',
            'in' => 'in',
            'inch' => 'in',
            'inches' => 'in',
            'pouce' => 'in',
            'pouces' => 'in',
            'min' => 'min',
            'minute' => 'min',
            'minutes' => 'min',
            'hr' => 'hr',
            'hour' => 'hr',
            'hours' => 'hr',
            'heure' => 'hr',
            'heures' => 'hr',
            'day' => 'day',
            'days' => 'day',
            'jour' => 'day',
            'jours' => 'day',
        ];

        // Track statistics
        $totalProducts = 0;
        $mappedProducts = 0;
        $unmappedUnits = [];

        // Update products with mapped units
        DB::table('products')
            ->whereNotNull('unit')
            ->whereNull('unit_id')
            ->chunkById(1000, function ($products) use ($units, $mapping, &$totalProducts, &$mappedProducts, &$unmappedUnits) {
                foreach ($products as $product) {
                    $totalProducts++;
                    $unitText = strtolower(trim($product->unit ?? ''));

                    if (empty($unitText)) {
                        continue;
                    }

                    if (isset($mapping[$unitText])) {
                        $unitCode = $mapping[$unitText];

                        if (isset($units[$unitCode])) {
                            DB::table('products')
                                ->where('id', $product->id)
                                ->update(['unit_id' => $units[$unitCode]->id]);

                            $mappedProducts++;
                        }
                    } else {
                        // Track unmapped units for reporting
                        if (! isset($unmappedUnits[$unitText])) {
                            $unmappedUnits[$unitText] = 0;
                        }
                        $unmappedUnits[$unitText]++;
                    }
                }
            });

        // Log migration results. Use the framework logger instead of `echo`:
        // tenant migrations also run via MigrateDatabase during HTTP signup
        // (T6 database-per-tenant), and any stdout output during a request is
        // streamed straight into the HTTP response body — corrupting the JSON
        // axios tries to parse and silently breaking the register flow on the
        // SPA. Logging keeps the artisan-migrate signal in storage/logs/laravel.log.
        $summary = sprintf(
            'Product unit migration: %d products, %d mapped, %d unmapped',
            $totalProducts,
            $mappedProducts,
            count($unmappedUnits),
        );
        Log::info($summary);

        if (count($unmappedUnits) > 0) {
            arsort($unmappedUnits);
            Log::info('Unmapped product units (top 20)', array_slice($unmappedUnits, 0, 20));
        }
    }

    public function down(): void
    {
        // Reset unit_id to null for all products
        DB::table('products')->update(['unit_id' => null]);
    }
};
