<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('partner_bank_accounts')) {
            return;
        }

        Schema::create('partner_bank_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('partner_id');
            $table->string('label')->nullable();
            $table->uuid('bank_id')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('rib')->nullable();
            $table->string('iban')->nullable();
            $table->string('bic')->nullable();
            $table->string('currency', 3);
            $table->boolean('is_primary')->default(false);
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('partner_id')->references('id')->on('partners')->cascadeOnDelete();
            $table->foreign('bank_id')->references('id')->on('banks')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['partner_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_bank_accounts');
    }
};
