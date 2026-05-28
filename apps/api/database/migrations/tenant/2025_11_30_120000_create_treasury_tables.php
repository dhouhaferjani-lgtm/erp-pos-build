<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_repositories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            // Identification
            $table->string('code', 30);
            $table->string('name', 100);
            $table->string('type', 30); // cash_register, safe, bank_account, virtual

            // For bank accounts
            $table->string('bank_name', 100)->nullable();
            $table->string('account_number', 50)->nullable();
            $table->string('iban', 50)->nullable();
            $table->string('bic', 20)->nullable();

            // Balance tracking
            $table->decimal('balance', 15, 2)->default(0);
            $table->timestamp('last_reconciled_at')->nullable();
            $table->decimal('last_reconciled_balance', 15, 2)->nullable();

            // Access control
            $table->uuid('location_id')->nullable();
            $table->uuid('responsible_user_id')->nullable();

            // Linked account
            $table->uuid('account_id')->nullable();

            // Status
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            // Identification
            $table->string('code', 30);
            $table->string('name', 100);

            // Universal switches
            $table->boolean('is_physical')->default(false);
            $table->boolean('has_maturity')->default(false);
            $table->boolean('requires_third_party')->default(false);
            $table->boolean('is_push')->default(true);
            $table->boolean('has_deducted_fees')->default(false);
            $table->boolean('is_restricted')->default(false);

            // Fees
            $table->string('fee_type', 20)->nullable(); // none, fixed, percentage, mixed
            $table->decimal('fee_fixed', 10, 2)->default(0);
            $table->decimal('fee_percent', 5, 2)->default(0);

            // Restrictions
            $table->string('restriction_type', 50)->nullable(); // food, fuel, etc.

            // Linked accounts (nullable, no FK constraint for flexibility)
            $table->uuid('default_journal_id')->nullable();
            $table->uuid('default_account_id')->nullable();
            $table->uuid('fee_account_id')->nullable();

            // Status
            $table->boolean('is_active')->default(true);
            $table->integer('position')->default(0);

            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('payment_instruments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('payment_method_id')->constrained('payment_methods')->onDelete('restrict');

            // Identification
            $table->string('reference', 100);
            $table->uuid('partner_id')->nullable();
            $table->string('drawer_name', 150)->nullable();

            // Amount
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('TND');

            // Dates
            $table->date('received_date');
            $table->date('maturity_date')->nullable();
            $table->date('expiry_date')->nullable();

            // Status & Location
            $table->string('status', 30)->default('received');
            $table->foreignUuid('repository_id')->nullable()->constrained('payment_repositories')->onDelete('restrict');

            // Bank information
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_branch', 100)->nullable();
            $table->string('bank_account', 50)->nullable();

            // Deposit tracking
            $table->timestamp('deposited_at')->nullable();
            $table->uuid('deposited_to_id')->nullable();

            // Clearing/Bouncing
            $table->timestamp('cleared_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->string('bounce_reason', 255)->nullable();

            // Link to payment when used
            $table->uuid('payment_id')->nullable();

            // Audit
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'partner_id']);
            $table->index(['tenant_id', 'maturity_date']);
            $table->index(['tenant_id', 'repository_id']);
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('partner_id')->constrained('partners')->onDelete('restrict');
            $table->foreignUuid('payment_method_id')->constrained('payment_methods')->onDelete('restrict');
            $table->uuid('instrument_id')->nullable();
            $table->uuid('repository_id')->nullable();

            // Amount
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('TND');

            // Date and status
            $table->date('payment_date');
            $table->string('status', 30)->default('pending');

            // Reference
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();

            // GL integration
            $table->uuid('journal_entry_id')->nullable();

            // Audit
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'partner_id']);
            $table->index(['tenant_id', 'payment_date']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->constrained('payments')->onDelete('cascade');
            $table->foreignUuid('document_id')->constrained('documents')->onDelete('restrict');
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->index('payment_id');
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payment_instruments');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('payment_repositories');
    }
};
