<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Infrastructure\Persistence;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Sidecar model for the `scheduling_appointment_sequences` table — one row per
 * `(tenant_id, company_id, year)` carrying a monotonic `last_number`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property int $year
 * @property int $last_number
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AppointmentSequence extends Model
{
    use HasUuids;

    protected $table = 'scheduling_appointment_sequences';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'year',
        'last_number',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'last_number' => 'integer',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
