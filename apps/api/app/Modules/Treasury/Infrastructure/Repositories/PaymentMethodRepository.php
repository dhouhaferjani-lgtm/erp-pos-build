<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Infrastructure\Repositories;

use App\Modules\Treasury\Domain\PaymentMethod;

final class PaymentMethodRepository
{
    public function findById(string $id): ?PaymentMethod
    {
        return PaymentMethod::find($id);
    }
}
