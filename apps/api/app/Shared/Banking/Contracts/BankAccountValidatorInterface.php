<?php

declare(strict_types=1);

namespace App\Shared\Banking\Contracts;

use App\Shared\Banking\Domain\ValueObjects\IbanValidationResult;
use App\Shared\Banking\Domain\ValueObjects\RibValidationResult;

interface BankAccountValidatorInterface
{
    public function validateRib(string $rib, string $country): RibValidationResult;

    public function toIban(string $rib, string $country): string;

    public function validateIban(string $iban): IbanValidationResult;

    public function validateBic(string $bic): bool;
}
