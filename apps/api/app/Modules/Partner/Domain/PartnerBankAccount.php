<?php

declare(strict_types=1);

namespace App\Modules\Partner\Domain;

use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $partner_id
 * @property string|null $label
 * @property string|null $bank_id
 * @property string|null $bank_name
 * @property string|null $rib
 * @property string|null $iban
 * @property string|null $bic
 * @property string $currency
 * @property bool $is_primary
 * @property string|null $created_by
 */
final class PartnerBankAccount extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'partner_id',
        'label',
        'bank_id',
        'bank_name',
        'rib',
        'iban',
        'bic',
        'currency',
        'is_primary',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
