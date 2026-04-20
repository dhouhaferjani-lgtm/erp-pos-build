<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\ReconciliationStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Bank reconciliation session.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $repository_id
 * @property Carbon $statement_date
 * @property numeric-string $opening_balance
 * @property numeric-string $closing_balance
 * @property numeric-string $statement_balance
 * @property numeric-string $difference
 * @property ReconciliationStatus $status
 * @property string $created_by
 * @property string|null $completed_by
 * @property Carbon|null $completed_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read PaymentRepository $repository
 * @property-read User $creator
 * @property-read User|null $completer
 * @property-read Collection<int, BankReconciliationItem> $items
 */
class BankReconciliation extends Model
{
    use HasUuids;

    protected $table = 'bank_reconciliations';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'repository_id',
        'statement_date',
        'opening_balance',
        'closing_balance',
        'statement_balance',
        'difference',
        'status',
        'created_by',
        'completed_by',
        'completed_at',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'opening_balance' => 'decimal:3',
            'closing_balance' => 'decimal:3',
            'statement_balance' => 'decimal:3',
            'difference' => 'decimal:3',
            'status' => ReconciliationStatus::class,
            'completed_at' => 'datetime',
        ];
    }

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
     * @return BelongsTo<PaymentRepository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(PaymentRepository::class, 'repository_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * @return HasMany<BankReconciliationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BankReconciliationItem::class, 'reconciliation_id');
    }

    public function isEditable(): bool
    {
        return $this->status === ReconciliationStatus::Draft;
    }
}
