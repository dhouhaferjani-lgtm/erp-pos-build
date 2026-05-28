<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies');
            $table->char('country_code', 2);
            $table->string('period_type', 20);
            $table->string('label', 50);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('OPEN');

            $table->decimal('total_output_vat', 15, 3)->nullable();
            $table->decimal('total_input_vat', 15, 3)->nullable();
            $table->decimal('net_vat', 15, 3)->nullable();
            $table->decimal('credit_brought_forward', 15, 3)->default(0);
            $table->decimal('credit_carried_forward', 15, 3)->default(0);
            $table->decimal('amount_payable', 15, 3)->default(0);

            $table->jsonb('special_items')->nullable();
            $table->jsonb('declaration_data')->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('filed_at')->nullable();
            $table->foreignUuid('filed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('filing_reference')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->foreign('country_code')->references('code')->on('countries');

            $table->unique(['company_id', 'period_start', 'period_end']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'country_code', 'period_start']);
        });

        Schema::create('vat_period_breakdowns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('vat_period_id')->constrained('vat_periods')->cascadeOnDelete();
            $table->string('direction', 10);
            $table->decimal('tax_rate', 5, 2);
            $table->foreignUuid('tax_configuration_id')->nullable()->constrained('tax_configurations')->nullOnDelete();
            $table->decimal('base_amount', 15, 3);
            $table->decimal('vat_amount', 15, 3);
            $table->integer('document_count');
            $table->boolean('is_recoverable')->default(true);
            $table->timestamp('created_at')->nullable();

            $table->index('vat_period_id');
            $table->index(['vat_period_id', 'direction']);
            $table->unique(['vat_period_id', 'direction', 'tax_rate', 'is_recoverable']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_period_breakdowns');
        Schema::dropIfExists('vat_periods');
    }
};
