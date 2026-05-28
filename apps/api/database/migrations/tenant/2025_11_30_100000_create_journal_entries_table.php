<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('entry_number')->index();
            $table->date('entry_date');
            $table->text('description')->nullable();
            $table->string('status')->default('draft');

            // Source reference for document integration
            $table->string('source_type')->nullable();
            $table->uuid('source_id')->nullable();

            // Hash chain for compliance
            $table->string('hash')->nullable();
            $table->string('previous_hash')->nullable();

            // Posting audit trail
            $table->timestamp('posted_at')->nullable();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();

            // Reversal audit trail
            $table->timestamp('reversed_at')->nullable();
            $table->foreignUuid('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('reversal_entry_id')->nullable();

            $table->timestamps();

            // Unique entry number per tenant
            $table->unique(['tenant_id', 'entry_number']);

            // Index for source lookups
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignUuid('account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->text('description')->nullable();
            $table->unsignedInteger('line_order')->default(0);
            $table->timestamps();

            // Index for account balance queries
            $table->index(['account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
    }
};
