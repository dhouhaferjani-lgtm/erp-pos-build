<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Treasury\Domain\Enums\InstrumentAccountPurpose;
use App\Modules\Treasury\Domain\Exceptions\MissingInstrumentAccountException;
use Illuminate\Database\DatabaseManager;

final readonly class InstrumentAccountResolver
{
    public function __construct(private DatabaseManager $database) {}

    public function resolve(InstrumentAccountPurpose $purpose, string $companyId): ?string
    {
        $countryCode = $this->database->table('companies')
            ->where('id', $companyId)
            ->value('country_code');

        if (! is_string($countryCode)) {
            return null;
        }

        $accountId = $this->database->table('accounts')
            ->where('company_id', $companyId)
            ->where('code', $this->accountCode($purpose, $countryCode))
            ->where('type', $this->accountType($purpose))
            ->where('is_active', true)
            ->value('id');

        return is_string($accountId) ? $accountId : null;
    }

    public function resolveOrFail(InstrumentAccountPurpose $purpose, string $companyId): string
    {
        return $this->resolve($purpose, $companyId)
            ?? throw MissingInstrumentAccountException::forPurpose($purpose, $companyId);
    }

    private function accountCode(InstrumentAccountPurpose $purpose, string $countryCode): string
    {
        $isTunisia = strtoupper($countryCode) === 'TN';

        return match ($purpose) {
            InstrumentAccountPurpose::ChecksToCollect => $isTunisia ? '5312' : '5112',
            InstrumentAccountPurpose::ChecksToPay => '4035',
            InstrumentAccountPurpose::EffectsReceivable => '413',
            InstrumentAccountPurpose::EffetsPayable => '403',
            InstrumentAccountPurpose::EffectsInCollection => $isTunisia ? '5313' : '5113',
            InstrumentAccountPurpose::EffectsDiscounted => $isTunisia ? '5314' : '5114',
            InstrumentAccountPurpose::InstrumentBankFees => $isTunisia ? '6275' : '627',
            InstrumentAccountPurpose::VatRecoverableOnFees => $isTunisia ? '43666' : '44566',
            InstrumentAccountPurpose::DoubtfulReceivables => '416',
        };
    }

    private function accountType(InstrumentAccountPurpose $purpose): string
    {
        return match ($purpose) {
            InstrumentAccountPurpose::ChecksToPay,
            InstrumentAccountPurpose::EffetsPayable => 'liability',
            InstrumentAccountPurpose::InstrumentBankFees => 'expense',
            InstrumentAccountPurpose::ChecksToCollect,
            InstrumentAccountPurpose::EffectsReceivable,
            InstrumentAccountPurpose::EffectsInCollection,
            InstrumentAccountPurpose::EffectsDiscounted,
            InstrumentAccountPurpose::VatRecoverableOnFees,
            InstrumentAccountPurpose::DoubtfulReceivables => 'asset',
        };
    }
}
