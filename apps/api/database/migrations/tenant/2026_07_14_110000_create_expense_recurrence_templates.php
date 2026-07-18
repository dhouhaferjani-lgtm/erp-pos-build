<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_recurrence_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignUuid('expense_category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $table->foreignUuid('partner_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->foreignUuid('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->foreignUuid('payment_repository_id')->nullable()->constrained('payment_repositories')->nullOnDelete();
            $table->string('vendor_name')->nullable();
            $table->decimal('amount', 15, 3);
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->decimal('vat_deductible_percent', 5, 2)->nullable();
            $table->decimal('vat_amount', 15, 3)->nullable();
            $table->text('notes')->nullable();
            $table->string('frequency');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->smallInteger('lead_days')->default(3);
            $table->string('status')->default('active');
            $table->date('next_due_date');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'company_id']);
            $table->index(
                ['tenant_id', 'company_id', 'status', 'next_due_date'],
                'expense_recurrence_templates_due_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_recurrence_templates');
    }
};
