<?php

declare(strict_types=1);

namespace Tests\Unit\Voucher\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Voucher\Domain\Enums\RedemptionMode;
use App\Modules\Voucher\Domain\Enums\VoucherKind;
use App\Modules\Voucher\Domain\Enums\VoucherSource;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Unit tests for Voucher domain entity.
 *
 * Covers: casts, relationships, and isRedeemable() single-terminal guard.
 */
final class VoucherTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Cast tests
    // -------------------------------------------------------------------------

    public function test_voucher_status_is_cast_to_enum(): void
    {
        $voucher = Voucher::factory()->create(['status' => VoucherStatus::Issued]);
        $voucher->refresh();

        $this->assertInstanceOf(VoucherStatus::class, $voucher->status);
        $this->assertSame(VoucherStatus::Issued, $voucher->status);
    }

    public function test_voucher_source_is_cast_to_enum(): void
    {
        $voucher = Voucher::factory()->create(['source' => VoucherSource::Goodwill]);
        $voucher->refresh();

        $this->assertInstanceOf(VoucherSource::class, $voucher->source);
        $this->assertSame(VoucherSource::Goodwill, $voucher->source);
    }

    public function test_voucher_kind_is_cast_to_enum(): void
    {
        $voucher = Voucher::factory()->create(['voucher_kind' => VoucherKind::MPV]);
        $voucher->refresh();

        $this->assertInstanceOf(VoucherKind::class, $voucher->voucher_kind);
        $this->assertSame(VoucherKind::MPV, $voucher->voucher_kind);
    }

    public function test_voucher_redemption_mode_is_cast_to_enum(): void
    {
        $voucher = Voucher::factory()->create(['redemption_mode' => RedemptionMode::Bearer]);
        $voucher->refresh();

        $this->assertInstanceOf(RedemptionMode::class, $voucher->redemption_mode);
        $this->assertSame(RedemptionMode::Bearer, $voucher->redemption_mode);
    }

    public function test_voucher_balance_fields_are_decimal_strings(): void
    {
        $voucher = Voucher::factory()->create([
            'initial_balance' => '75.50000',
            'current_balance' => '25.25000',
        ]);
        $voucher->refresh();

        $this->assertIsString($voucher->initial_balance);
        $this->assertIsString($voucher->current_balance);
        $this->assertStringContainsString('.', $voucher->initial_balance);
    }

    public function test_voucher_expires_at_is_carbon_when_set(): void
    {
        $expiry = Carbon::now()->addYear();
        $voucher = Voucher::factory()->create(['expires_at' => $expiry]);
        $voucher->refresh();

        $this->assertInstanceOf(Carbon::class, $voucher->expires_at);
    }

    public function test_voucher_expires_at_is_null_when_not_set(): void
    {
        $voucher = Voucher::factory()->create(['expires_at' => null]);
        $voucher->refresh();

        $this->assertNull($voucher->expires_at);
    }

    // -------------------------------------------------------------------------
    // Relationship tests
    // -------------------------------------------------------------------------

    public function test_voucher_has_many_ledger_entries(): void
    {
        $voucher = Voucher::factory()->create();
        VoucherLedger::factory()->count(3)->create(['voucher_id' => $voucher->id]);

        $this->assertCount(3, $voucher->ledger);
    }

    // -------------------------------------------------------------------------
    // isRedeemable() tests
    // -------------------------------------------------------------------------

    private function makeTerminal(): Terminal
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);

        return Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);
    }

    public function test_is_redeemable_returns_false_on_terminal_mismatch(): void
    {
        $terminalA = $this->makeTerminal();
        $terminalB = $this->makeTerminal();

        $voucher = Voucher::factory()
            ->forTerminal($terminalA)
            ->create([
                'status' => VoucherStatus::Issued,
                'current_balance' => '50.00000',
                'expires_at' => null,
            ]);

        $this->assertFalse($voucher->isRedeemable($terminalB));
    }

    public function test_is_redeemable_returns_false_when_expired(): void
    {
        $terminal = $this->makeTerminal();

        $voucher = Voucher::factory()
            ->forTerminal($terminal)
            ->create([
                'status' => VoucherStatus::Issued,
                'current_balance' => '50.00000',
                'expires_at' => now()->subHour(),
            ]);

        $this->assertFalse($voucher->isRedeemable($terminal));
    }

    public function test_is_redeemable_returns_false_when_voided(): void
    {
        $terminal = $this->makeTerminal();

        $voucher = Voucher::factory()
            ->forTerminal($terminal)
            ->voided()
            ->create();

        $this->assertFalse($voucher->isRedeemable($terminal));
    }

    public function test_is_redeemable_returns_false_when_fully_redeemed(): void
    {
        $terminal = $this->makeTerminal();

        $voucher = Voucher::factory()
            ->forTerminal($terminal)
            ->fullyRedeemed()
            ->create();

        $this->assertFalse($voucher->isRedeemable($terminal));
    }

    public function test_is_redeemable_returns_true_when_partially_redeemed_with_balance(): void
    {
        $terminal = $this->makeTerminal();

        $voucher = Voucher::factory()
            ->forTerminal($terminal)
            ->partiallyRedeemed('20.00000')
            ->create([
                'expires_at' => now()->addYear(),
            ]);

        $this->assertTrue($voucher->isRedeemable($terminal));
    }

    public function test_is_redeemable_returns_true_when_issued_with_balance(): void
    {
        $terminal = $this->makeTerminal();

        $voucher = Voucher::factory()
            ->forTerminal($terminal)
            ->create([
                'status' => VoucherStatus::Issued,
                'current_balance' => '50.00000',
                'expires_at' => null,
            ]);

        $this->assertTrue($voucher->isRedeemable($terminal));
    }

    public function test_is_redeemable_returns_false_when_balance_is_zero(): void
    {
        $terminal = $this->makeTerminal();

        $voucher = Voucher::factory()
            ->forTerminal($terminal)
            ->create([
                'status' => VoucherStatus::Issued,
                'current_balance' => '0.00000',
                'expires_at' => null,
            ]);

        $this->assertFalse($voucher->isRedeemable($terminal));
    }

    public function test_is_redeemable_returns_false_when_redeemable_terminal_is_null(): void
    {
        $terminal = $this->makeTerminal();

        $voucher = Voucher::factory()->create([
            'status' => VoucherStatus::Issued,
            'current_balance' => '50.00000',
            'expires_at' => null,
            'redeemable_at_terminal_id' => null,
        ]);

        $this->assertFalse($voucher->isRedeemable($terminal));
    }
}
