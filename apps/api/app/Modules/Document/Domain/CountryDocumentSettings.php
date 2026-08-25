<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain;

use App\Models\Country;
use App\Modules\Document\Domain\DTOs\FiscalAuthorityTypes;
use App\Modules\Document\Domain\Enums\FiscalAuthorityMode;
use App\Modules\Document\Domain\Enums\PolicyExpertiseStatus;
use App\Modules\Document\Domain\Enums\PreDeliveryInvoicingPolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The seeded DOCUMENT-lane country policy row (Wave 3 D-27).
 *
 * Sibling of `Treasury\Domain\CountryPaymentSettings`. This is the family's
 * extension point: the next document-lane country setting adds a COLUMN here
 * rather than a table of its own.
 *
 * @property string $country_code
 * @property PreDeliveryInvoicingPolicy $pre_delivery_invoicing_policy
 * @property FiscalAuthorityMode|null $fiscal_authority_mode
 * @property FiscalAuthorityTypes|null $fiscal_authority_types
 * @property PolicyExpertiseStatus|null $policy_expertise_status
 */
class CountryDocumentSettings extends Model
{
    use HasUuids;

    protected $table = 'country_document_settings';

    /**
     * C-QR0a ships the authority columns UNACTIVATED and deliberately leaves them
     * OUT of `$fillable`. The casts below make them readable and typed; mass
     * assignment arrives in C-QR0b together with the seeder that owns the write.
     * Until then a stray payload naming them is dropped rather than honoured —
     * F-112: nothing but the seeder decides a country's authority policy.
     */
    protected $fillable = [
        'country_code',
        'pre_delivery_invoicing_policy',
    ];

    protected $casts = [
        'pre_delivery_invoicing_policy' => PreDeliveryInvoicingPolicy::class,
        'fiscal_authority_mode' => FiscalAuthorityMode::class,
        'fiscal_authority_types' => FiscalAuthorityTypes::class,
        'policy_expertise_status' => PolicyExpertiseStatus::class,
    ];

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_code', 'code');
    }
}
