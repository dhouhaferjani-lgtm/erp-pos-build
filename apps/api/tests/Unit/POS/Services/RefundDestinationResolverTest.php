<?php

declare(strict_types=1);

namespace Tests\Unit\POS\Services;

use App\Modules\Company\Domain\ValueObjects\ReservationSettings;
use App\Modules\POS\Domain\Enums\RefundDestination;
use App\Modules\POS\Domain\Exceptions\RefundDestinationNotAllowedException;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Services\RefundDestinationResolver;
use Illuminate\Support\Carbon;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Unit tests for RefundDestinationResolver.
 *
 * The resolver is a pure domain service with no DB or infrastructure
 * dependencies — it receives its inputs via constructor/method arguments.
 * We use Mockery to create Receipt stubs rather than a real factory.
 */
final class RefundDestinationResolverTest extends TestCase
{
    private RefundDestinationResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new RefundDestinationResolver;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // In-window tests
    // -------------------------------------------------------------------------

    public function test_in_window_returns_requested_when_policy_allows(): void
    {
        $original = $this->makeReceipt(postedAt: Carbon::now()->subDays(3));

        $policy = new ReservationSettings(
            customerReturnExpiryDays: 14,
            outOfWindowPolicy: 'voucher_only',
            allowedRefundDestinations: ['original_payment', 'cash', 'store_voucher'],
        );

        $result = $this->resolver->resolve(
            requested: RefundDestination::OriginalPayment,
            original: $original,
            policy: $policy,
            cashierPermissions: [],
        );

        $this->assertSame(RefundDestination::OriginalPayment, $result);
    }

    public function test_in_window_throws_when_destination_not_in_allowed_list(): void
    {
        $this->expectException(RefundDestinationNotAllowedException::class);

        $original = $this->makeReceipt(postedAt: Carbon::now()->subDays(5));

        $policy = new ReservationSettings(
            customerReturnExpiryDays: 14,
            outOfWindowPolicy: 'voucher_only',
            allowedRefundDestinations: ['original_payment', 'store_voucher'],
        );

        $this->resolver->resolve(
            requested: RefundDestination::Cash,
            original: $original,
            policy: $policy,
            cashierPermissions: [],
        );
    }

    // -------------------------------------------------------------------------
    // Out-of-window tests — voucher_only policy
    // -------------------------------------------------------------------------

    public function test_out_of_window_voucher_only_forces_store_voucher(): void
    {
        $original = $this->makeReceipt(postedAt: Carbon::now()->subDays(30));

        $policy = new ReservationSettings(
            customerReturnExpiryDays: 14,
            outOfWindowPolicy: 'voucher_only',
            allowedRefundDestinations: ['original_payment', 'cash', 'store_voucher'],
        );

        $result = $this->resolver->resolve(
            requested: RefundDestination::OriginalPayment,
            original: $original,
            policy: $policy,
            cashierPermissions: [],
        );

        $this->assertSame(RefundDestination::StoreVoucher, $result);
    }

    public function test_out_of_window_voucher_only_returns_store_voucher_when_already_requested(): void
    {
        $original = $this->makeReceipt(postedAt: Carbon::now()->subDays(30));

        $policy = new ReservationSettings(
            customerReturnExpiryDays: 14,
            outOfWindowPolicy: 'voucher_only',
            allowedRefundDestinations: ['original_payment', 'cash', 'store_voucher'],
        );

        $result = $this->resolver->resolve(
            requested: RefundDestination::StoreVoucher,
            original: $original,
            policy: $policy,
            cashierPermissions: [],
        );

        $this->assertSame(RefundDestination::StoreVoucher, $result);
    }

    // -------------------------------------------------------------------------
    // Out-of-window tests — refuse policy
    // -------------------------------------------------------------------------

    public function test_out_of_window_refuse_blocks_without_permission(): void
    {
        $this->expectException(RefundDestinationNotAllowedException::class);

        $original = $this->makeReceipt(postedAt: Carbon::now()->subDays(30));

        $policy = new ReservationSettings(
            customerReturnExpiryDays: 14,
            outOfWindowPolicy: 'refuse',
            allowedRefundDestinations: ['original_payment', 'cash', 'store_voucher'],
        );

        $this->resolver->resolve(
            requested: RefundDestination::Cash,
            original: $original,
            policy: $policy,
            cashierPermissions: ['pos.operate_terminal', 'pos.process_returns'],
        );
    }

    public function test_out_of_window_refuse_allows_with_permission(): void
    {
        $original = $this->makeReceipt(postedAt: Carbon::now()->subDays(30));

        $policy = new ReservationSettings(
            customerReturnExpiryDays: 14,
            outOfWindowPolicy: 'refuse',
            allowedRefundDestinations: ['original_payment', 'cash', 'store_voucher'],
        );

        $result = $this->resolver->resolve(
            requested: RefundDestination::OriginalPayment,
            original: $original,
            policy: $policy,
            cashierPermissions: ['pos.refund_above_threshold'],
        );

        $this->assertSame(RefundDestination::OriginalPayment, $result);
    }

    public function test_out_of_window_refuse_with_permission_still_validates_allowed_list(): void
    {
        $this->expectException(RefundDestinationNotAllowedException::class);

        $original = $this->makeReceipt(postedAt: Carbon::now()->subDays(30));

        // Cash is NOT in the allowed destinations list
        $policy = new ReservationSettings(
            customerReturnExpiryDays: 14,
            outOfWindowPolicy: 'refuse',
            allowedRefundDestinations: ['original_payment', 'store_voucher'],
        );

        $this->resolver->resolve(
            requested: RefundDestination::Cash,
            original: $original,
            policy: $policy,
            cashierPermissions: ['pos.refund_above_threshold'],
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a Receipt mock with just the posted_at attribute populated.
     * We avoid database dependencies to keep these as true unit tests.
     */
    private function makeReceipt(Carbon $postedAt): Receipt
    {
        /** @var Receipt&MockInterface $receipt */
        $receipt = Mockery::mock(Receipt::class)->makePartial();
        $receipt->posted_at = $postedAt;

        return $receipt;
    }
}
