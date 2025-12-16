<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add billing-specific columns to existing tenant_subscriptions table
        Schema::table('tenant_subscriptions', function (Blueprint $table): void {
            if (! Schema::hasColumn('tenant_subscriptions', 'billing_cycle')) {
                $table->string('billing_cycle', 20)->default('monthly')->after('status');
            }
            if (! Schema::hasColumn('tenant_subscriptions', 'price')) {
                $table->decimal('price', 10, 2)->nullable()->after('billing_cycle');
            }
            if (! Schema::hasColumn('tenant_subscriptions', 'currency')) {
                $table->string('currency', 3)->default('EUR')->after('price');
            }
            if (! Schema::hasColumn('tenant_subscriptions', 'current_period_start')) {
                $table->timestamp('current_period_start')->nullable();
            }
            if (! Schema::hasColumn('tenant_subscriptions', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable();
            }
            if (! Schema::hasColumn('tenant_subscriptions', 'ends_at')) {
                $table->timestamp('ends_at')->nullable();
            }
            if (! Schema::hasColumn('tenant_subscriptions', 'stripe_subscription_id')) {
                $table->string('stripe_subscription_id')->nullable()->unique();
            }
            if (! Schema::hasColumn('tenant_subscriptions', 'stripe_customer_id')) {
                $table->string('stripe_customer_id')->nullable();
            }
            if (! Schema::hasColumn('tenant_subscriptions', 'last_payment_at')) {
                $table->timestamp('last_payment_at')->nullable();
            }
            if (! Schema::hasColumn('tenant_subscriptions', 'next_payment_due')) {
                $table->timestamp('next_payment_due')->nullable();
            }
            if (! Schema::hasColumn('tenant_subscriptions', 'metadata')) {
                $table->jsonb('metadata')->default('{}');
            }
            if (! Schema::hasColumn('tenant_subscriptions', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // Add indexes (skip if already exist)
        try {
            Schema::table('tenant_subscriptions', function (Blueprint $table): void {
                $table->index('next_payment_due');
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Index already exists, ignore
        }
    }

    public function down(): void
    {
        Schema::table('tenant_subscriptions', function (Blueprint $table): void {
            $columns = [
                'billing_cycle',
                'price',
                'currency',
                'current_period_start',
                'cancelled_at',
                'ends_at',
                'stripe_subscription_id',
                'stripe_customer_id',
                'last_payment_at',
                'next_payment_due',
                'metadata',
                'deleted_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('tenant_subscriptions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
