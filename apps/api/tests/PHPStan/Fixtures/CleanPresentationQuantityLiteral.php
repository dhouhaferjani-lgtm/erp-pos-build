<?php

declare(strict_types=1);

namespace App\Modules\Demo\Presentation\Resources;

final class CleanPresentationQuantityLiteral
{
    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'label' => '7',
            'ratio' => '1.000',
            'wide' => '1.00000',
            'text' => 'not a number',
        ];
    }
}
