<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->text('facturx_xml')->nullable()->after('fiscal_hash');
            $table->string('facturx_profile', 20)->nullable()->after('facturx_xml');
            $table->timestampTz('facturx_generated_at')->nullable()->after('facturx_profile');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['facturx_xml', 'facturx_profile', 'facturx_generated_at']);
        });
    }
};
