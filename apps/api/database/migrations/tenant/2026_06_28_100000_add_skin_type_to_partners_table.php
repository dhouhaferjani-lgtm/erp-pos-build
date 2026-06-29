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
        Schema::table('partners', function (Blueprint $table): void {
            $table->string('skin_type', 32)->nullable()->after('notes');
            $table->text('skin_advice_note')->nullable()->after('skin_type');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE partners
                ADD CONSTRAINT partners_skin_type_check
                CHECK (skin_type IN ('normal', 'oily', 'dry', 'combination', 'sensitive'))
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE partners DROP CONSTRAINT IF EXISTS partners_skin_type_check');
        }

        Schema::table('partners', function (Blueprint $table): void {
            $table->dropColumn(['skin_type', 'skin_advice_note']);
        });
    }
};
