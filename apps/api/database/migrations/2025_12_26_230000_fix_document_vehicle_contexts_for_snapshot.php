<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Decouples DocumentVehicleContext from hard FK to vehicles table.
     * Stores vehicle snapshot at time of service for immutability.
     */
    public function up(): void
    {
        // Step 1: Add new columns
        Schema::table('document_vehicle_contexts', function (Blueprint $table): void {
            $table->jsonb('vehicle_snapshot')->nullable()->after('vehicle_id');
            $table->unsignedInteger('mileage_at_service')->nullable()->after('vehicle_snapshot');
        });

        // Step 2: Migrate existing data - snapshot vehicle information
        $contexts = DB::table('document_vehicle_contexts')->get();
        foreach ($contexts as $context) {
            $vehicle = DB::table('vehicles')->where('id', $context->vehicle_id)->first();
            if ($vehicle !== null) {
                $snapshot = [
                    'license_plate' => $vehicle->license_plate ?? null,
                    'brand' => $vehicle->brand ?? null,
                    'model' => $vehicle->model ?? null,
                    'year' => $vehicle->year ?? null,
                    'vin' => $vehicle->vin ?? null,
                    'color' => $vehicle->color ?? null,
                    'fuel_type' => $vehicle->fuel_type ?? null,
                ];

                DB::table('document_vehicle_contexts')
                    ->where('id', $context->id)
                    ->update(['vehicle_snapshot' => json_encode($snapshot)]);
            }
        }

        // Step 3: Drop foreign key constraint (soft reference only)
        Schema::table('document_vehicle_contexts', function (Blueprint $table): void {
            $table->dropForeign(['vehicle_id']);
        });

        // Step 4: Add index for performance (replace FK)
        Schema::table('document_vehicle_contexts', function (Blueprint $table): void {
            $table->index('vehicle_id', 'idx_vehicle_contexts_vehicle_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverse: Re-add FK, drop new columns
        Schema::table('document_vehicle_contexts', function (Blueprint $table): void {
            $table->dropIndex('idx_vehicle_contexts_vehicle_id');
            $table->foreignUuid('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->dropColumn(['vehicle_snapshot', 'mileage_at_service']);
        });
    }
};
