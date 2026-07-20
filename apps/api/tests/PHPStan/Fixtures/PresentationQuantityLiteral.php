<?php

declare(strict_types=1);

namespace App\Modules\Demo\Presentation\Resources;

final class PresentationQuantityLiteral
{
    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'requested_qty' => '1.0000',
            'suggested_qty' => '12.0000',
            'label' => '7',
            'ratio' => '1.000',
            'wide' => '1.00000',
        ];
    }
}
