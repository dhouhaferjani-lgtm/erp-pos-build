<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Sidecar for certification attachments. Media/DocumentAttachment in this codebase is
     * scoped to fiscal Documents, so certifications use their own standalone attachment table.
     */
    public function up(): void
    {
        Schema::create('workshop_technician_certification_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('certification_id')
                ->constrained('workshop_technician_certifications')
                ->cascadeOnDelete();

            $table->string('filename', 255);
            $table->string('mime_type', 100);
            $table->bigInteger('byte_size');
            $table->string('storage_disk', 32);
            $table->string('storage_path', 512);
            $table->foreignUuid('uploaded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestampTz('created_at')->useCurrent();

            $table->index('certification_id', 'idx_wtca_cert');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workshop_technician_certification_attachments');
    }
};
