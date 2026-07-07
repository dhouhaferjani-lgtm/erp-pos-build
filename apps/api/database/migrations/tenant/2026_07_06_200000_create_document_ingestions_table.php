<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_ingestions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('status', 20)->default('uploaded');
            $table->uuid('media_asset_id');
            $table->string('checksum', 64);
            $table->string('provider', 40)->nullable();
            $table->string('provider_model', 80)->nullable();
            $table->jsonb('extraction')->nullable();
            $table->jsonb('confidence_summary')->nullable();
            $table->jsonb('suggestions')->nullable();
            $table->string('committed_type', 40)->nullable();
            $table->uuid('committed_id')->nullable();
            $table->jsonb('error')->nullable();
            $table->uuid('created_by');
            $table->timestampsTz();

            $table->foreign('media_asset_id')->references('id')->on('media_assets')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'kind', 'created_at']);
            $table->index('tenant_id');
        });

        DB::statement("
            CREATE UNIQUE INDEX ux_document_ingestions_company_checksum
            ON document_ingestions (company_id, checksum)
            WHERE status NOT IN ('rejected','failed')
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('document_ingestions');
    }
};
