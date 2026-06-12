<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Owner decision 2026-06-11 (docs/superpowers/tickets/
     * 2026-06-11-web-pos-demo-only-gate.md): the browser POS surface is
     * demo-account-only. `is_demo` marks a demo tenant; the web POS
     * mutating routes are gated on it (EnsureWebPosDemoTenant). Default
     * FALSE — every real tenant is locked out of the web POS by default.
     *
     * Central table (tenants directory lives in synerivia_central).
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->boolean('is_demo')->default(false)->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('is_demo');
        });
    }
};
