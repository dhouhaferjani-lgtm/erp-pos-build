<?php

declare(strict_types=1);

namespace App\Modules\Coupon\Domain\Exceptions;

final class CouponInvalidException extends \DomainException
{
    public static function notFound(string $code): self
    {
        return new self("Coupon code '{$code}' not found.");
    }

    public static function expired(string $code): self
    {
        return new self("Coupon code '{$code}' has expired.");
    }

    public static function exhausted(string $code): self
    {
        return new self("Coupon code '{$code}' has been fully redeemed.");
    }

    public static function customerLimitReached(string $code): self
    {
        return new self("You have already used coupon '{$code}' the maximum number of times.");
    }

    public static function minimumNotMet(string $code, string $minAmount): self
    {
        return new self("Order does not meet minimum amount of {$minAmount} for coupon '{$code}'.");
    }

    public static function revoked(string $code): self
    {
        return new self("Coupon code '{$code}' has been revoked.");
    }

    public static function noQualifyingItems(string $code): self
    {
        return new self("No items in the cart qualify for coupon '{$code}'.");
    }
}
