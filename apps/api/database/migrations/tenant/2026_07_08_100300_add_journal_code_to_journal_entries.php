<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add journal_code (FEC-readiness — French Fichier des Écritures Comptables
     * export requires a "code journal" per entry, e.g. VT/AC/BQ/CA/OD) to
     * journal_entries. Nullable: existing rows stay null; every new post
     * populates it via GeneralLedgerService + JournalCode::fromSourceType()
     * (Treasury spine Task 9).
     */
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->string('journal_code', 4)->nullable()->after('source_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropColumn('journal_code');
        });
    }
};
