<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('company_id')->index();

            // Scannable token — format: TENANT-<12 alphanum>-<1 check digit>
            $table->string('code', 64)->unique();

            // Monetary precision: internal = currency_scale + 2.
            // TND (scale 3) → 5 decimal places; EUR (scale 2) → 4 decimal places.
            // DECIMAL(20,5) comfortably holds both.
            $table->decimal('initial_balance', 20, 5);
            $table->decimal('current_balance', 20, 5);
            $table->char('currency', 3);

            // Enums stored as strings — VoucherStatus
            $table->string('status', 32);

            // bearer | customer_bound — RedemptionMode
            $table->string('redemption_mode', 32);

            // MPV | SPV — VoucherKind
            $table->string('voucher_kind', 8)->default('MPV');

            // Voucher source discriminator — VoucherSource
            $table->string('source', 32);

            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->nullable();

            // Current holder — set when customer is identified
            $table->uuid('partner_id')->nullable()->index();

            // Identified-at-issuance partner (distinct from current holder for transferable cases)
            $table->uuid('issued_to_partner_id')->nullable();

            // The credit note that birthed this voucher (nullable for goodwill)
            $table->uuid('source_receipt_id')->nullable()->index();

            // Phase 1.5+ wiring — set when source = loyalty_credit
            $table->uuid('source_loyalty_transaction_id')->nullable();

            // Phase 2+ wiring — set when source = promotional
            $table->uuid('source_promotional_campaign_id')->nullable();

            $table->uuid('issued_by_user_id');

            // Terminal that issued — null = back-office goodwill
            $table->uuid('issued_at_terminal_id')->nullable();

            // Phase 1: single-terminal scope.
            // Defaults to issued_at_terminal_id at issuance time.
            // null = back-office issued, not yet assigned to a terminal.
            $table->uuid('redeemable_at_terminal_id')->nullable();

            $table->text('notes')->nullable();

            // Audit / override fields
            $table->uuid('authorized_by_user_id')->nullable();
            $table->string('override_reason')->nullable();
            $table->string('policy_trigger')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Composite index: "Vouchers & Credits" back-office page filter
            $table->index(['tenant_id', 'source', 'status'], 'vouchers_tenant_source_status_idx');

            // POS-side single-terminal lookup
            $table->index(['redeemable_at_terminal_id', 'status'], 'vouchers_terminal_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
