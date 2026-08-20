<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Exceptions;

use InvalidArgumentException;

final class InvalidDeliveryNoteClaimRequestException extends InvalidArgumentException {}
