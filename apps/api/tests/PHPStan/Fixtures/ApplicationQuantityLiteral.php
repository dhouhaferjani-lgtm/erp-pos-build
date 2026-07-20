<?php

declare(strict_types=1);

namespace App\Modules\Demo\Application\Services;

final class ApplicationQuantityLiteral
{
    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'requested_qty' => '1.0000',
            'suggested_qty' => '12.0000',
        ];
    }
}
