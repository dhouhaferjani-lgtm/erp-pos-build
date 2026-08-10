<?php

declare(strict_types=1);

namespace App\Modules\Company\Application\Services;

use App\Modules\Company\Domain\Company;
use Illuminate\Validation\ValidationException;
use LogicException;

final class CompanyFiscalIdentityService
{
    /**
     * @param  array<string, string|null>  $candidateAttributes
     * @return array<string, array{old: string|null, new: string|null}>
     */
    public function changedFields(Company $company, array $candidateAttributes): array
    {
        $changes = [];

        foreach ($candidateAttributes as $attribute => $newValue) {
            $oldValue = $this->currentValue($company, $attribute);

            if ($oldValue === $newValue) {
                continue;
            }

            $changes[$attribute] = [
                'old' => $oldValue,
                'new' => $newValue,
            ];
        }

        return $changes;
    }

    /**
     * @param  array<string, string|null>  $candidateAttributes
     * @param  array<string, string>  $validationKeys
     */
    public function assertImmutableFieldsUnchanged(
        Company $company,
        array $candidateAttributes,
        array $validationKeys = [],
    ): void {
        $errors = [];

        foreach (array_keys($this->changedFields($company, $candidateAttributes)) as $attribute) {
            $errors[$validationKeys[$attribute] ?? $attribute] = match ($attribute) {
                'country_code' => (string) __('company.identity.country_immutable'),
                'currency' => (string) __('company.identity.currency_immutable'),
                default => throw new LogicException("{$attribute} is not an immutable company identity field."),
            };
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function currentValue(Company $company, string $attribute): ?string
    {
        if (! in_array($attribute, [
            'country_code',
            'currency',
            'legal_name',
            'tax_id',
            'registration_number',
            'vat_number',
        ], true)) {
            throw new LogicException("{$attribute} is not a company fiscal identity field.");
        }

        $value = $company->getAttribute($attribute);

        return is_string($value) ? $value : null;
    }
}
