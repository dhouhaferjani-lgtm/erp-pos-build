<?php

declare(strict_types=1);

namespace Tests\Unit\Taxation;

use App\Modules\Taxation\Domain\Services\VatCreditService;
use PHPUnit\Framework\TestCase;

class VatCreditServiceTest extends TestCase
{
    private VatCreditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new VatCreditService;
    }

    public function test_net_positive_no_credit_forward(): void
    {
        // Output 9500 - Input 5700 = 3800 payable, 0 credit
        $result = $this->service->calculate('9500.000', '5700.000', '0.000');

        $this->assertSame('3800.000', $result['net_vat']);
        $this->assertSame('3800.000', $result['amount_payable']);
        $this->assertSame('0.000', $result['credit_carried_forward']);
    }

    public function test_net_negative_generates_credit(): void
    {
        // Output 3000 - Input 5000 = -2000 net, 2000 credit, 0 payable
        $result = $this->service->calculate('3000.000', '5000.000', '0.000');

        $this->assertSame('-2000.000', $result['net_vat']);
        $this->assertSame('0.000', $result['amount_payable']);
        $this->assertSame('2000.000', $result['credit_carried_forward']);
    }

    public function test_credit_fully_covers_liability(): void
    {
        // Output 5000 - Input 3000 = 2000 net, 3000 b/f → 0 payable, 1000 credit
        $result = $this->service->calculate('5000.000', '3000.000', '3000.000');

        $this->assertSame('2000.000', $result['net_vat']);
        $this->assertSame('0.000', $result['amount_payable']);
        $this->assertSame('1000.000', $result['credit_carried_forward']);
    }

    public function test_credit_partially_covers_liability(): void
    {
        // Output 5000 - Input 2000 = 3000 net, 1000 b/f → 2000 payable, 0 credit
        $result = $this->service->calculate('5000.000', '2000.000', '1000.000');

        $this->assertSame('3000.000', $result['net_vat']);
        $this->assertSame('2000.000', $result['amount_payable']);
        $this->assertSame('0.000', $result['credit_carried_forward']);
    }

    public function test_credit_accumulates_with_negative_net(): void
    {
        // Output 1000 - Input 3000 = -2000 net, 500 b/f → 0 payable, 2500 credit
        $result = $this->service->calculate('1000.000', '3000.000', '500.000');

        $this->assertSame('-2000.000', $result['net_vat']);
        $this->assertSame('0.000', $result['amount_payable']);
        $this->assertSame('2500.000', $result['credit_carried_forward']);
    }
}
