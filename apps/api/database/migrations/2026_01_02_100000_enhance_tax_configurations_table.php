<?php

declare(strict_types=1);

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
        Schema::table('tax_configurations', function (Blueprint $table): void {
            // Add sequence_order for tax stacking
            if (! Schema::hasColumn('tax_configurations', 'sequence_order')) {
                $table->integer('sequence_order')->default(1)->after('is_active');
            }

            // Add stacks_on for compound tax calculation
            if (! Schema::hasColumn('tax_configurations', 'stacks_on')) {
                $table->string('stacks_on', 50)->default('SUBTOTAL')->after('sequence_order');
            }

            // Add applicable_document_types for restricting tax to specific document types
            if (! Schema::hasColumn('tax_configurations', 'applicable_document_types')) {
                $table->jsonb('applicable_document_types')->nullable()->after('stacks_on');
            }

            // Add is_stamp_duty flag
            if (! Schema::hasColumn('tax_configurations', 'is_stamp_duty')) {
                $table->boolean('is_stamp_duty')->default(false)->after('applicable_document_types');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tax_configurations', function (Blueprint $table): void {
            if (Schema::hasColumn('tax_configurations', 'sequence_order')) {
                $table->dropColumn('sequence_order');
            }

            if (Schema::hasColumn('tax_configurations', 'stacks_on')) {
                $table->dropColumn('stacks_on');
            }

            if (Schema::hasColumn('tax_configurations', 'applicable_document_types')) {
                $table->dropColumn('applicable_document_types');
            }

            if (Schema::hasColumn('tax_configurations', 'is_stamp_duty')) {
                $table->dropColumn('is_stamp_duty');
            }
        });
    }
};
