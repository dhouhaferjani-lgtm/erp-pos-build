<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_metadata', function (Blueprint $table): void {
            $table->foreignUuid('recurrence_template_id')
                ->nullable()
                ->constrained('expense_recurrence_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expense_metadata', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recurrence_template_id');
        });
    }
};
