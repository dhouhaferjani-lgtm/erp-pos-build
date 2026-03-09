<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_seller_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('seller_id')->constrained('marketplace_sellers');
            $table->uuid('buyer_tenant_id');
            $table->uuid('buyer_company_id');
            $table->uuid('buyer_partner_id')->nullable();
            $table->uuid('seller_partner_id')->nullable();
            $table->timestamps();

            $table->foreign('buyer_tenant_id')->references('id')->on('tenants');
            $table->foreign('buyer_company_id')->references('id')->on('companies');
            $table->foreign('buyer_partner_id')->references('id')->on('partners')->nullOnDelete();
            $table->foreign('seller_partner_id')->references('id')->on('partners')->nullOnDelete();

            $table->unique(['seller_id', 'buyer_tenant_id', 'buyer_company_id'], 'bsm_seller_buyer_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_seller_mappings');
    }
};
