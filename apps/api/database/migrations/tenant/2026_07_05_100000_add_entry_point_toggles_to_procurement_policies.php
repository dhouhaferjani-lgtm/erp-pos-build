<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_policies', function (Blueprint $table): void {
            $table->boolean('allow_receipt_first')->default(false)->after('variance_tolerance_max_amount');
            $table->boolean('allow_invoice_first')->default(false)->after('allow_receipt_first');
            $table->boolean('invoice_first_requires_approval')->default(true)->after('allow_invoice_first');
        });

        DB::table('procurement_policies')->update([
            'allow_receipt_first' => false,
            'allow_invoice_first' => false,
            'invoice_first_requires_approval' => true,
        ]);
    }

    public function down(): void
    {
        Schema::table('procurement_policies', function (Blueprint $table): void {
            $table->dropColumn([
                'allow_receipt_first',
                'allow_invoice_first',
                'invoice_first_requires_approval',
            ]);
        });
    }
};
