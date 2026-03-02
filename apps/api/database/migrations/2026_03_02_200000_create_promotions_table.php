<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('company_id')->nullable()->constrained('companies');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type'); // PromotionType enum
            $table->string('status')->default('draft'); // PromotionStatus enum
            $table->integer('priority')->default(0);
            $table->boolean('is_exclusive')->default(false);
            $table->string('stacking_group')->default('default');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->jsonb('days_of_week')->nullable(); // [1,2,3,4,5] = Mon-Fri
            $table->string('time_from')->nullable(); // "09:00"
            $table->string('time_until')->nullable(); // "17:00"
            $table->jsonb('conditions')->default('{}');
            $table->string('discount_type'); // DiscountType enum
            $table->decimal('discount_value', 12, 4);
            $table->decimal('max_discount_amount', 15, 2)->nullable();
            $table->string('applies_to'); // DiscountAppliesTo enum
            $table->integer('usage_limit')->nullable();
            $table->integer('usage_count')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['company_id', 'status']);
            $table->index(['status', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
