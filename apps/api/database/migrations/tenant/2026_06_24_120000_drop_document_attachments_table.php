<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the legacy document_attachments table.
     *
     * Owner-approved data discard: this table was superseded by
     * media_attachments (Phase 2, media subsystem unification).
     * No production data exists; discard is unconditional.
     */
    public function up(): void
    {
        Schema::dropIfExists('document_attachments');
    }

    /**
     * Recreate the legacy document_attachments table (exact schema
     * from 2025_12_14_000001_create_document_attachments_table.php).
     */
    public function down(): void
    {
        Schema::create('document_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('uploaded_by')->constrained('users')->cascadeOnDelete();

            $table->string('filename');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('file_size');
            $table->string('storage_path');
            $table->string('storage_disk')->default('local');

            $table->string('description')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'document_id']);
            $table->index('uploaded_by');
        });
    }
};
