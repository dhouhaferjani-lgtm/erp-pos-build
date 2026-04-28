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
        $driver = DB::connection()->getDriverName();

        Schema::table('fraud_alerts', function (Blueprint $table) use ($driver): void {
            $table->string('description_code', 120)->nullable()->after('description');
            if ($driver === 'pgsql') {
                $table->jsonb('description_params')->nullable()->after('description_code');
            } else {
                $table->json('description_params')->nullable()->after('description_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fraud_alerts', function (Blueprint $table): void {
            $table->dropColumn(['description_code', 'description_params']);
        });
    }
};
