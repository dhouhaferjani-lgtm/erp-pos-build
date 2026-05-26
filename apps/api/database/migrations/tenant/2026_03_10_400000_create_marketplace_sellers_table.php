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
        Schema::create('marketplace_sellers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('seller_type', 30);
            $table->string('seller_status', 30)->default('pending_review');
            $table->string('display_name');
            $table->string('country_code', 2);
            $table->string('currency', 3);
            $table->decimal('commission_rate', 5, 2)->default(5.00);
            $table->decimal('total_gmv', 15, 3)->default(0);
            $table->integer('total_orders')->default(0);
            $table->integer('total_items_sold')->default(0);
            $table->decimal('gmv_current_month', 15, 3)->default(0);
            $table->integer('orders_current_month')->default(0);
            $table->decimal('average_rating', 3, 2)->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->jsonb('settings')->nullable();
            $table->timestamps();
        });

        // Partial unique index: only one seller per tenant+company for erp_tenant type
        DB::statement("
            CREATE UNIQUE INDEX marketplace_sellers_tenant_company_unique
            ON marketplace_sellers (tenant_id, company_id)
            WHERE seller_type = 'erp_tenant'
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_sellers');
    }
};
