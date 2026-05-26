<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->text('receipt_header')->nullable()->after('receipt_footer');
            $table->boolean('receipt_show_vat_breakdown')->default(true)->after('receipt_header');
            $table->boolean('receipt_show_fiscal_info')->default(true)->after('receipt_show_vat_breakdown');
            $table->boolean('receipt_show_payment_details')->default(true)->after('receipt_show_fiscal_info');
            $table->boolean('receipt_show_customer')->default(true)->after('receipt_show_payment_details');
            $table->text('receipt_thank_you')->nullable()->after('receipt_show_customer');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn([
                'receipt_header',
                'receipt_show_vat_breakdown',
                'receipt_show_fiscal_info',
                'receipt_show_payment_details',
                'receipt_show_customer',
                'receipt_thank_you',
            ]);
        });
    }
};
