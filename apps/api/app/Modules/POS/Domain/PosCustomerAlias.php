<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * POS-owned durable mapping from a device-created customer UUID to the
 * canonical server Partner UUID.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $client_customer_uuid
 * @property string $server_partner_id
 */
final class PosCustomerAlias extends Model
{
    use HasUuids;

    protected $table = 'pos_customer_aliases';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'client_customer_uuid',
        'server_partner_id',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function serverPartner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'server_partner_id');
    }

    public static function resolveServerPartnerId(
        string $tenantId,
        string $companyId,
        string $clientCustomerUuid,
    ): ?string {
        /** @var self|null $alias */
        $alias = self::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('client_customer_uuid', $clientCustomerUuid)
            ->first();

        if (! $alias instanceof self) {
            return null;
        }

        $partnerExists = Partner::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereIn('type', [PartnerType::Customer, PartnerType::Both])
            ->whereKey($alias->server_partner_id)
            ->exists();

        if (! $partnerExists) {
            return null;
        }

        return $alias->server_partner_id;
    }
}
