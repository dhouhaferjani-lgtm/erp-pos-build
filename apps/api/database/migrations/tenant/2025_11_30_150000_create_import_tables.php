<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('user_id')->index();
            $table->string('type', 50);
            $table->string('status', 50)->default('pending');
            $table->string('original_filename', 255);
            $table->string('file_path', 500);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('successful_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->jsonb('column_mapping')->nullable();
            $table->jsonb('options')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'type']);
        });

        Schema::create('import_rows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('import_job_id')->index();
            $table->unsignedInteger('row_number');
            $table->jsonb('data');
            $table->boolean('is_valid')->default(false);
            $table->jsonb('errors')->nullable();
            $table->boolean('is_imported')->default(false);
            $table->uuid('imported_entity_id')->nullable();
            $table->text('import_error')->nullable();
            $table->timestamps();

            $table->foreign('import_job_id')
                ->references('id')
                ->on('import_jobs')
                ->onDelete('cascade');

            $table->index(['import_job_id', 'is_valid']);
            $table->index(['import_job_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_jobs');
    }
};
