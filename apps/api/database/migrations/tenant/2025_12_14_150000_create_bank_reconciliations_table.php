<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bank reconciliation sessions
        Schema::create('bank_reconciliations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('repository_id');
            $table->date('statement_date');
            $table->decimal('opening_balance', 15, 2);
            $table->decimal('closing_balance', 15, 2);
            $table->decimal('statement_balance', 15, 2);
            $table->decimal('difference', 15, 2)->default(0);
            $table->enum('status', ['draft', 'completed', 'cancelled'])->default('draft');
            $table->uuid('created_by');
            $table->uuid('completed_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('repository_id')->references('id')->on('payment_repositories')->onDelete('restrict');
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('completed_by')->references('id')->on('users');

            $table->index(['tenant_id', 'repository_id', 'statement_date']);
        });

        // Items in a reconciliation session
        Schema::create('bank_reconciliation_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('reconciliation_id');
            $table->uuid('payment_id');
            $table->boolean('is_matched')->default(false);
            $table->string('bank_reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->uuid('matched_by')->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();

            $table->foreign('reconciliation_id')->references('id')->on('bank_reconciliations')->onDelete('cascade');
            $table->foreign('payment_id')->references('id')->on('payments')->onDelete('restrict');
            $table->foreign('matched_by')->references('id')->on('users');

            $table->unique(['reconciliation_id', 'payment_id']);
        });

        // Add reconciliation tracking to payments
        Schema::table('payments', function (Blueprint $table): void {
            $table->boolean('is_reconciled')->default(false)->after('status');
            $table->timestamp('reconciled_at')->nullable()->after('is_reconciled');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn(['is_reconciled', 'reconciled_at']);
        });
        Schema::dropIfExists('bank_reconciliation_items');
        Schema::dropIfExists('bank_reconciliations');
    }
};
