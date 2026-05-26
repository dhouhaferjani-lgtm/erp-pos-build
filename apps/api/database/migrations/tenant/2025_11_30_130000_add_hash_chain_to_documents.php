<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            // Hash chain columns for fiscal compliance
            $table->string('fiscal_hash', 64)->nullable()->after('balance_due');
            $table->string('previous_hash', 64)->nullable()->after('fiscal_hash');
            $table->unsignedBigInteger('chain_sequence')->nullable()->after('previous_hash');

            // Index for chain verification queries
            $table->index(['tenant_id', 'type', 'chain_sequence']);
        });

        Schema::table('journal_entries', function (Blueprint $table): void {
            // Add chain_sequence for ordering (hash and previous_hash already exist)
            $table->unsignedBigInteger('chain_sequence')->nullable()->after('previous_hash');

            // Index for chain verification queries
            $table->index(['tenant_id', 'chain_sequence']);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'type', 'chain_sequence']);
            $table->dropColumn(['fiscal_hash', 'previous_hash', 'chain_sequence']);
        });

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'chain_sequence']);
            $table->dropColumn(['entry_hash', 'previous_hash', 'chain_sequence']);
        });
    }
};
