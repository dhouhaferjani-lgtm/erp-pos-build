<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            // At most one local brand per canonical platform brand per tenant.
            // PostgreSQL treats NULLs as distinct, so unmapped brands are unconstrained.
            $table->unique(['tenant_id', 'canonical_brand_id'], 'brands_tenant_canonical_unique');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropUnique('brands_tenant_canonical_unique');
        });
    }
};
