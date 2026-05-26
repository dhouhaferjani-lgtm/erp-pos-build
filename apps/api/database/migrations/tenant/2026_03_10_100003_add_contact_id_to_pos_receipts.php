<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add contact_id FK to pos_receipts for contact-level receipt tracking.
     */
    public function up(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->foreignUuid('contact_id')
                ->nullable()
                ->after('partner_id')
                ->constrained('contacts')
                ->nullOnDelete();

            $table->index('contact_id');
        });
    }

    public function down(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->dropForeign(['contact_id']);
            $table->dropIndex(['contact_id']);
            $table->dropColumn('contact_id');
        });
    }
};
