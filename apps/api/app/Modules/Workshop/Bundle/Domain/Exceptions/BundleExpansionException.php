<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Domain\Exceptions;

use RuntimeException;

final class BundleExpansionException extends RuntimeException
{
    public static function bundleNotFound(string $bundleId): self
    {
        return new self(sprintf('Bundle %s not found for expansion.', $bundleId));
    }

    public static function missingComponent(string $componentId): self
    {
        return new self(sprintf('Bundle component %s could not be resolved for expansion.', $componentId));
    }

    public static function maxDepthExceeded(int $depth): self
    {
        return new self(sprintf('Bundle expansion exceeded maximum nesting depth of %d.', $depth));
    }
}
