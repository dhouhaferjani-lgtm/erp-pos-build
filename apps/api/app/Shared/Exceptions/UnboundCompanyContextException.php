<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

/**
 * Thrown by CurrencyScaleResolver (and other context-sensitive resolvers) when
 * called without an explicit scope AND without a bound CompanyContext.
 *
 * This is the canonical signal that a queued job / console command / async handler
 * forgot to call CompanyContext::setCompanyId() before invoking a scale-aware service.
 * See audit finding F-RES-1.
 */
final class UnboundCompanyContextException extends \RuntimeException {}
