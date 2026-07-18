<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum InstrumentAccountPurpose: string
{
    case ChecksToCollect = 'checks_to_collect';
    case ChecksToPay = 'checks_to_pay';
    case EffectsReceivable = 'effects_receivable';
    case EffetsPayable = 'effets_payable';
    case EffectsInCollection = 'effects_in_collection';
    case EffectsDiscounted = 'effects_discounted';
    case InstrumentBankFees = 'instrument_bank_fees';
    case VatRecoverableOnFees = 'vat_recoverable_on_fees';
    case DoubtfulReceivables = 'doubtful_receivables';
}
