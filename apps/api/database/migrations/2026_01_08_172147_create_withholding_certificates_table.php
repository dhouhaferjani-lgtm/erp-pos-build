<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('withholding_certificates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');

            // Certificate identity
            $table->string('certificate_number', 50);
            $table->integer('year');

            // Direction: purchase (we withhold) or sales (they withhold from us)
            $table->string('direction', 20);

            // Parties
            $table->uuid('partner_id');

            // Source transactions
            $table->uuid('document_id')->nullable();
            $table->uuid('payment_id')->nullable();

            // Amounts (stored in payment currency - important for multi-currency)
            $table->char('currency', 3);
            $table->decimal('gross_amount', 15, 3);
            $table->decimal('withholding_rate', 5, 4);
            $table->decimal('withholding_amount', 15, 3);
            $table->decimal('net_amount', 15, 3);

            // Rule applied (nullable if manual override)
            $table->uuid('withholding_rule_id')->nullable();
            $table->text('override_reason')->nullable();

            // TEJ submission (Tunisia)
            $table->string('tej_reference', 100)->nullable();
            $table->timestamp('tej_submitted_at')->nullable();

            // Certificate file (PDF for non-TEJ countries or transition)
            $table->string('certificate_media_id', 255)->nullable();

            // Status
            $table->string('status', 20)->default('draft');

            // Hash chain for fiscal compliance
            $table->string('hash', 64)->nullable();
            $table->string('previous_hash', 64)->nullable();
            $table->integer('chain_sequence')->nullable();

            // Issued info
            $table->timestamp('issued_at')->nullable();
            $table->uuid('issued_by')->nullable();

            $table->timestamps();

            // Foreign keys
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('partner_id')->references('id')->on('partners')->onDelete('restrict');
            $table->foreign('document_id')->references('id')->on('documents')->onDelete('restrict');
            $table->foreign('payment_id')->references('id')->on('payments')->onDelete('restrict');
            $table->foreign('withholding_rule_id')->references('id')->on('withholding_tax_rules')->onDelete('set null');
            $table->foreign('issued_by')->references('id')->on('users')->onDelete('set null');

            // Indexes
            $table->unique(['company_id', 'certificate_number', 'year']);
            $table->index('partner_id');
            $table->index('payment_id');
            $table->index('document_id');
            $table->index(['company_id', 'year']);
            $table->index('tej_submitted_at');
            $table->index('status');
            $table->index('direction');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('withholding_certificates');
    }
};
