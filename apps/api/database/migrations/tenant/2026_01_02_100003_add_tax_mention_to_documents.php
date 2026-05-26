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
        Schema::table('documents', function (Blueprint $table): void {
            // Add tax_mention for legal tax exemption mentions
            if (! Schema::hasColumn('documents', 'tax_mention')) {
                $table->text('tax_mention')->nullable()->after('stamp_duty_amount');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            if (Schema::hasColumn('documents', 'tax_mention')) {
                $table->dropColumn('tax_mention');
            }
        });
    }
};
