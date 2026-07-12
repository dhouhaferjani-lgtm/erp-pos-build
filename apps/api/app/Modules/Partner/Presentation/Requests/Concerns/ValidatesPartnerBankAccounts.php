<?php

declare(strict_types=1);

namespace App\Modules\Partner\Presentation\Requests\Concerns;

use App\Modules\Partner\Application\DTOs\PartnerBankAccountInputData;
use App\Shared\Banking\Contracts\BankAccountValidatorInterface;
use App\Shared\Presentation\Validation\ScopedExists;

trait ValidatesPartnerBankAccounts
{
    /** @return array<string, array<int, object|string>> */
    private function bankAccountRules(string $tenantId): array
    {
        return [
            'bank_accounts' => ['sometimes', 'array'],
            'bank_accounts.*.id' => ['sometimes', 'nullable', 'uuid'],
            'bank_accounts.*.label' => ['nullable', 'string', 'max:100'],
            'bank_accounts.*.bank_id' => ['nullable', 'uuid', ScopedExists::tenant('banks', $tenantId)],
            'bank_accounts.*.bank_name' => ['nullable', 'string', 'max:255'],
            'bank_accounts.*.rib' => ['nullable', 'string', 'max:64'],
            'bank_accounts.*.iban' => ['nullable', 'string', 'max:64'],
            'bank_accounts.*.bic' => ['nullable', 'string', 'max:32'],
            'bank_accounts.*.currency' => ['required_with:bank_accounts', 'string', 'size:3'],
            'bank_accounts.*.is_primary' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, PartnerBankAccountInputData>
     */
    public function bankAccounts(): array
    {
        /** @var array<int, array<string, scalar|null>> $rows */
        $rows = $this->validated('bank_accounts', []);

        return array_map(
            static fn (array $row): PartnerBankAccountInputData => new PartnerBankAccountInputData(
                id: isset($row['id']) ? (string) $row['id'] : null,
                label: isset($row['label']) ? (string) $row['label'] : null,
                bank_id: isset($row['bank_id']) ? (string) $row['bank_id'] : null,
                bank_name: isset($row['bank_name']) ? (string) $row['bank_name'] : null,
                rib: isset($row['rib']) ? (string) $row['rib'] : null,
                iban: isset($row['iban']) ? (string) $row['iban'] : null,
                bic: isset($row['bic']) ? (string) $row['bic'] : null,
                currency: isset($row['currency']) ? (string) $row['currency'] : '',
                is_primary: isset($row['is_primary']) && (bool) $row['is_primary'],
            ),
            $rows,
        );
    }

    /**
     * Exercise the shared validator after structural validation without adding
     * failures: legacy and foreign identifiers remain saveable.
     */
    private function recordBankAccountValidity(BankAccountValidatorInterface $validator): void
    {
        $country = strtoupper((string) ($this->input('country_code') ?: 'TN'));
        foreach ($this->bankAccounts() as $account) {
            $validator->validateRib($account->rib ?? '', $country);
            $validator->validateIban($account->iban ?? '');
            if ($account->bic !== null && $account->bic !== '') {
                $validator->validateBic($account->bic);
            }
        }
    }
}
