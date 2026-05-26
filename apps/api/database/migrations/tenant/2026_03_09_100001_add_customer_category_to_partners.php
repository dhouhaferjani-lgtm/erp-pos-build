<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add customer_category to partners for B2B/B2C differentiation.
     *
     * - individual: B2C customers (lightweight: name, phone, email)
     * - business: B2B customers (rich data: company name, tax ID, addresses, payment terms)
     */
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('customer_category', 20)
                ->nullable()
                ->after('type');
        });

        // Default existing customers to 'business' (back-office created partners are typically B2B)
        DB::table('partners')
            ->whereIn('type', ['customer', 'both'])
            ->whereNull('customer_category')
            ->update(['customer_category' => 'business']);

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE partners ADD CONSTRAINT partners_customer_category_check CHECK (customer_category IN ('individual', 'business') OR customer_category IS NULL)");
            DB::statement("COMMENT ON COLUMN partners.customer_category IS 'B2B/B2C differentiation: individual (B2C, lightweight) or business (B2B, rich data)'");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE partners DROP CONSTRAINT IF EXISTS partners_customer_category_check');
        }

        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('customer_category');
        });
    }
};
