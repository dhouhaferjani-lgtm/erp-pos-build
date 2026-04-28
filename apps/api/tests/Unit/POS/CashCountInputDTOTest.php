<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\POS\Domain\DTOs\CashCountInputDTO;
use Tests\TestCase;

class CashCountInputDTOTest extends TestCase
{
    public function test_from_array_populates_properties(): void
    {
        $dto = CashCountInputDTO::from([
            'paymentMethodId' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
            'currencyCode' => 'EUR',
            'actualAmount' => '1500.0000',
        ]);

        $this->assertSame('a1b2c3d4-e5f6-7890-abcd-ef1234567890', $dto->paymentMethodId);
        $this->assertSame('EUR', $dto->currencyCode);
        $this->assertSame('1500.0000', $dto->actualAmount);
    }

    public function test_from_array_with_tnd_currency(): void
    {
        $dto = CashCountInputDTO::from([
            'paymentMethodId' => 'b2c3d4e5-f6a7-8901-bcde-f12345678901',
            'currencyCode' => 'TND',
            'actualAmount' => '250.7500',
        ]);

        $this->assertSame('TND', $dto->currencyCode);
        $this->assertSame('250.7500', $dto->actualAmount);
    }

    public function test_from_array_missing_required_key_throws(): void
    {
        $this->expectException(\Exception::class);

        CashCountInputDTO::from([
            'paymentMethodId' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
            // currencyCode missing
            'actualAmount' => '100.0000',
        ]);
    }
}
