<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('company_legal_name', 255)->nullable()->after('customer_category');
            $table->string('business_registration_number', 100)->nullable()->after('company_legal_name');
            $table->string('payment_terms', 20)->nullable()->after('business_registration_number');
            $table->integer('payment_terms_days')->nullable()->after('payment_terms');
            $table->decimal('credit_limit', 15, 4)->nullable()->after('payment_terms_days');
            $table->decimal('discount_percentage', 5, 2)->nullable()->after('credit_limit');
            $table->boolean('invoice_consolidation')->default(false)->after('discount_percentage');
            $table->string('consolidation_frequency', 20)->nullable()->after('invoice_consolidation');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn([
                'company_legal_name',
                'business_registration_number',
                'payment_terms',
                'payment_terms_days',
                'credit_limit',
                'discount_percentage',
                'invoice_consolidation',
                'consolidation_frequency',
            ]);
        });
    }
};
