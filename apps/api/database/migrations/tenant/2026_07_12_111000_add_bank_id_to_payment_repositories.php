<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_repositories') || Schema::hasColumn('payment_repositories', 'bank_id')) {
            return;
        }

        Schema::table('payment_repositories', function (Blueprint $table): void {
            $table->foreignUuid('bank_id')->nullable()->constrained('banks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_repositories') || ! Schema::hasColumn('payment_repositories', 'bank_id')) {
            return;
        }

        Schema::table('payment_repositories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bank_id');
        });
    }
};
