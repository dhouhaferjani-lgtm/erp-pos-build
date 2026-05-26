<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pos_held_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('terminal_id');
            $table->uuid('shift_id');
            $table->uuid('cashier_id');
            $table->string('label', 255)->nullable();
            $table->jsonb('cart_snapshot');
            $table->string('status', 20)->default('held');
            $table->timestamp('held_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('recalled_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('terminal_id')->references('id')->on('pos_terminals')->onDelete('cascade');
            $table->foreign('shift_id')->references('id')->on('pos_shifts')->onDelete('cascade');
            $table->foreign('cashier_id')->references('id')->on('users')->onDelete('cascade');

            $table->index(['terminal_id', 'status']);
            $table->index(['shift_id']);
            $table->index(['cashier_id']);
            $table->index(['expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_held_orders');
    }
};
