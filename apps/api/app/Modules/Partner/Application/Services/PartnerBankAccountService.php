<?php

declare(strict_types=1);

namespace App\Modules\Partner\Application\Services;

use App\Modules\Partner\Application\DTOs\PartnerBankAccountInputData;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Partner\Domain\PartnerBankAccount;
use App\Shared\Banking\Contracts\BankAccountValidatorInterface;

final class PartnerBankAccountService
{
    public function __construct(
        private readonly BankAccountValidatorInterface $validator,
    ) {}

    /**
     * @param  array<int, PartnerBankAccountInputData>  $accounts
     */
    public function sync(Partner $partner, array $accounts, string $actorId, string $country): void
    {
        $retainedIds = [];
        $primaryAssigned = false;

        foreach ($accounts as $input) {
            $account = $input->id === null
                ? new PartnerBankAccount
                : $partner->bankAccounts()->whereKey($input->id)->first() ?? new PartnerBankAccount;

            $ribResult = $this->validator->validateRib($input->rib ?? '', $country);
            $iban = $input->iban;
            if (($iban === null || trim($iban) === '') && $ribResult->valid) {
                $iban = $ribResult->iban;
            }

            $isPrimary = $input->is_primary && ! $primaryAssigned;
            $primaryAssigned = $primaryAssigned || $isPrimary;

            $account->fill([
                'tenant_id' => $partner->tenant_id,
                'partner_id' => $partner->id,
                'label' => $input->label,
                'bank_id' => $input->bank_id,
                'bank_name' => $input->bank_name,
                'rib' => $ribResult->normalized === '' ? null : $ribResult->normalized,
                'iban' => $iban === null || trim($iban) === '' ? null : strtoupper(str_replace(' ', '', $iban)),
                'bic' => $input->bic === null || trim($input->bic) === '' ? null : strtoupper(trim($input->bic)),
                'currency' => strtoupper($input->currency),
                'is_primary' => $isPrimary,
                'created_by' => $account->exists ? $account->created_by : $actorId,
            ]);
            $account->save();
            $retainedIds[] = $account->id;
        }

        $partner->bankAccounts()->whereNotIn('id', $retainedIds)->delete();
    }
}
