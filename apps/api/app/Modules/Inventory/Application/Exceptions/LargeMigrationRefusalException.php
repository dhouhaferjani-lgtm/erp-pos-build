<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Exceptions;

/**
 * Thrown when a stock-level migration would affect more rows than the configured
 * threshold and the caller has not explicitly opted in via $allowLargeMigration.
 *
 * Extends \DomainException so callers can catch either the specific class or
 * the broader domain-exception family.
 */
final class LargeMigrationRefusalException extends \DomainException {}
