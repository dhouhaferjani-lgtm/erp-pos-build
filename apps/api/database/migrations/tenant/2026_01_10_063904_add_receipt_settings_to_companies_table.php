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
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('auto_print_receipts')
                ->default(false)
                ->after('receipt_next_number')
                ->comment('Auto-print receipts after transaction completion');

            $table->string('receipt_logo', 255)
                ->nullable()
                ->after('auto_print_receipts')
                ->comment('Path to logo for receipt printing (can differ from main logo)');

            $table->text('receipt_footer')
                ->nullable()
                ->after('receipt_logo')
                ->comment('Custom footer text for receipt printing');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['auto_print_receipts', 'receipt_logo', 'receipt_footer']);
        });
    }
};
