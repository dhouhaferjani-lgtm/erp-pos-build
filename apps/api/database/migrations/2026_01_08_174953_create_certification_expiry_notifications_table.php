<?php

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
        Schema::create('certification_expiry_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('product_id');
            $table->uuid('certification_id');
            $table->date('expiry_date');
            $table->timestamp('notification_sent_at')->nullable();
            $table->string('notification_type', 20)->comment('60_days, 30_days, 7_days');
            $table->timestamp('created_at');

            // Foreign keys
            $table->foreign('product_id')
                ->references('id')->on('products')
                ->onDelete('cascade');

            $table->foreign('certification_id')
                ->references('id')->on('certifications')
                ->onDelete('cascade');

            // Indexes
            $table->index('expiry_date', 'idx_expiry_notifications_date');
            $table->index('notification_sent_at', 'idx_expiry_notifications_sent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('certification_expiry_notifications');
    }
};
