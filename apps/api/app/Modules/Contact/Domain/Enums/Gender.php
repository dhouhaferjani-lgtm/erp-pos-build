<?php

declare(strict_types=1);

namespace App\Modules\Contact\Domain\Enums;

enum Gender: string
{
    case Male = 'male';
    case Female = 'female';
    case Other = 'other';
}
