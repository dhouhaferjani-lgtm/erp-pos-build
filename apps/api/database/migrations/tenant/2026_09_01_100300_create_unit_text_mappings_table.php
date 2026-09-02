<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('unit_text_mappings')) {
            return;
        }

        Schema::create('unit_text_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('source_text');
            $table->foreignUuid('target_unit_id')->constrained('units')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'source_text'], 'unit_text_mappings_company_source_unique');
            $table->index(['tenant_id', 'company_id'], 'unit_text_mappings_tenant_company_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_text_mappings');
    }
};
