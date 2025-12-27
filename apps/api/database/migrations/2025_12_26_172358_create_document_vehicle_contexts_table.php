<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Three-step process:
     * 1. Create document_vehicle_contexts table
     * 2. Migrate existing vehicle_id data from documents table
     * 3. Drop vehicle_id column from documents table
     */
    public function up(): void
    {
        // Step 1: Create the new linking table
        Schema::create('document_vehicle_contexts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->jsonb('context_data')->nullable();
            $table->timestamps();

            // Ensure one context per document (unique constraint)
            $table->unique('document_id');

            // Index for queries by vehicle
            $table->index('vehicle_id');
        });

        // Step 2: Migrate existing vehicle_id data to the new table
        // Use Laravel's query builder for cross-database compatibility
        $documentsWithVehicles = DB::table('documents')
            ->whereNotNull('vehicle_id')
            ->select('id', 'vehicle_id', 'created_at', 'updated_at')
            ->get();

        foreach ($documentsWithVehicles as $document) {
            DB::table('document_vehicle_contexts')->insert([
                'id' => Str::uuid()->toString(),
                'document_id' => $document->id,
                'vehicle_id' => $document->vehicle_id,
                'created_at' => $document->created_at,
                'updated_at' => $document->updated_at,
            ]);
        }

        // Step 3: Drop the vehicle_id column from documents table
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropForeign(['vehicle_id']);
            $table->dropColumn('vehicle_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Three-step process (reverse order):
     * 1. Re-add vehicle_id column to documents table
     * 2. Migrate data back from document_vehicle_contexts
     * 3. Drop document_vehicle_contexts table
     */
    public function down(): void
    {
        // Step 1: Re-add vehicle_id column to documents table
        Schema::table('documents', function (Blueprint $table): void {
            $table->foreignUuid('vehicle_id')->nullable()->after('partner_id')->constrained('vehicles')->nullOnDelete();
        });

        // Step 2: Migrate data back from document_vehicle_contexts to documents
        $contexts = DB::table('document_vehicle_contexts')
            ->select('document_id', 'vehicle_id')
            ->get();

        foreach ($contexts as $context) {
            DB::table('documents')
                ->where('id', $context->document_id)
                ->update(['vehicle_id' => $context->vehicle_id]);
        }

        // Step 3: Drop the document_vehicle_contexts table
        Schema::dropIfExists('document_vehicle_contexts');
    }
};
