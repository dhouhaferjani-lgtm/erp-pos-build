<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            // Payment type for better tracking and reporting
            $table->string('payment_type', 30)->default('document_payment')->after('status');

            $table->index('payment_type');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['payment_type']);
            $table->dropColumn('payment_type');
        });
    }
};
