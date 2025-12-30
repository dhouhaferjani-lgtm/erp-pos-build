<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            // Drop the unique constraint first
            $table->dropUnique(['tenant_id', 'type', 'document_number']);

            // Make document_number nullable since drafts don't have numbers yet
            $table->string('document_number', 50)->nullable()->change();

            // Recreate unique constraint (NULL values are allowed and don't conflict)
            $table->unique(['tenant_id', 'type', 'document_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            // Drop the unique constraint
            $table->dropUnique(['tenant_id', 'type', 'document_number']);

            // Revert to NOT NULL (this may fail if there are NULL values)
            $table->string('document_number', 50)->nullable(false)->change();

            // Recreate unique constraint
            $table->unique(['tenant_id', 'type', 'document_number']);
        });
    }
};
