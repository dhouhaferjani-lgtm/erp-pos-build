<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use RuntimeException;

final class OpeningAlreadyExistsException extends RuntimeException {}
