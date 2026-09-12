<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

enum TransferActorRole: string
{
    case Receiver = 'receiver';
    case Closer = 'closer';
}
