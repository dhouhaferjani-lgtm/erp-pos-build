<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_supplier_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->string('platform_supplier_brand');
            $table->foreignUuid('partner_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->foreignUuid('marketplace_seller_id')->nullable()->constrained('marketplace_sellers')->nullOnDelete();
            $table->boolean('auto_order_enabled')->default(false);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies');

            $table->unique(['tenant_id', 'company_id', 'platform_supplier_brand'], 'psm_tenant_company_brand_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_supplier_mappings');
    }
};
