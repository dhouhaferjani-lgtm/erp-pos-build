<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_lines', function (Blueprint $table): void {
            $table->decimal('free_quantity', 15, 4)
                ->default('0.0000')
                ->after('quantity')
                ->comment('Same-product purchase bonus units ordered on this commercial line');

            $table->decimal('free_quantity_received', 15, 4)
                ->default('0.0000')
                ->after('quantity_received')
                ->comment('Same-product purchase bonus units received at zero incremental value');

            $table->decimal('free_quantity_invoiced', 15, 4)
                ->default('0.0000')
                ->after('quantity_invoiced')
                ->comment('Free units represented on supplier invoices as remise-en-nature bonus lines');

            $table->string('price_entry_mode', 8)
                ->default('unit')
                ->after('free_quantity_invoiced')
                ->comment('Purchase line price-entry mode: unit or total');

            $table->boolean('is_bonus_line')
                ->default(false)
                ->after('price_entry_mode')
                ->comment('Marks explicit zero-value supplier invoice / credit-note bonus lines');
        });
    }

    public function down(): void
    {
        Schema::table('document_lines', function (Blueprint $table): void {
            $table->dropColumn([
                'free_quantity',
                'free_quantity_received',
                'free_quantity_invoiced',
                'price_entry_mode',
                'is_bonus_line',
            ]);
        });
    }
};
