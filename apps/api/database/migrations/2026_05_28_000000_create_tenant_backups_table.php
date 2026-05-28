<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_backups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('status', 32);
            $table->string('file_path', 1024)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'completed_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_backups');
    }
};
