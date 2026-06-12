<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('type');
            $table->string('source');
            $table->string('status');
            $table->string('storage_disk');
            $table->string('storage_path')->nullable();
            $table->string('external_url', 2048)->nullable();
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('frame_count')->nullable();
            $table->string('title')->nullable();
            $table->uuid('uploaded_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'type']);
            $table->index('checksum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
