<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

use App\Modules\Accounting\Domain\Enums\OpeningBatchStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Opening Balance Batch - groups imported opening balance data
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property OpeningBatchType $type
 * @property string $name
 * @property Carbon $cutover_date
 * @property OpeningBatchStatus $status
 * @property string|null $source_system
 * @property array<string, mixed>|null $import_file_reference
 * @property string|null $hash
 * @property string|null $previous_hash
 * @property Carbon|null $validated_at
 * @property string|null $validated_by
 * @property Carbon|null $locked_at
 * @property string|null $locked_by
 * @property string $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read User $creator
 * @property-read User|null $validator
 * @property-read User|null $locker
 * @property-read Collection<int, OpeningBalanceImportRow> $rows
 */
class OpeningBalanceBatch extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'opening_balance_batches';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'type',
        'name',
        'cutover_date',
        'status',
        'source_system',
        'import_file_reference',
        'hash',
        'previous_hash',
        'validated_at',
        'validated_by',
        'locked_at',
        'locked_by',
        'created_by',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => OpeningBatchStatus::Draft,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OpeningBatchType::class,
            'status' => OpeningBatchStatus::class,
            'cutover_date' => 'date',
            'import_file_reference' => 'array',
            'validated_at' => 'datetime',
            'locked_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /**
     * @return HasMany<OpeningBalanceImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(OpeningBalanceImportRow::class, 'batch_id')->orderBy('row_number');
    }

    /**
     * Check if the batch can be edited
     */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * Check if the batch can be deleted
     */
    public function isDeletable(): bool
    {
        return $this->status->isDeletable();
    }

    /**
     * Check if the batch can be posted
     */
    public function canPost(): bool
    {
        return $this->status->canPost();
    }

    /**
     * Check if the batch can be locked
     */
    public function canLock(): bool
    {
        return $this->status->canLock();
    }

    /**
     * Check if the batch is immutable
     */
    public function isImmutable(): bool
    {
        return $this->status->isImmutable();
    }

    /**
     * Check if the batch is locked
     */
    public function isLocked(): bool
    {
        return $this->status === OpeningBatchStatus::Locked;
    }

    /**
     * Check if the batch is draft
     */
    public function isDraft(): bool
    {
        return $this->status === OpeningBatchStatus::Draft;
    }

    /**
     * Check if the batch is validated
     */
    public function isValidated(): bool
    {
        return $this->status === OpeningBatchStatus::Validated;
    }

    /**
     * Get count of valid rows
     */
    public function getValidRowCount(): int
    {
        return $this->rows()->where('status', 'VALID')->count();
    }

    /**
     * Get count of invalid rows
     */
    public function getInvalidRowCount(): int
    {
        return $this->rows()->where('status', 'INVALID')->count();
    }

    /**
     * Get count of pending rows
     */
    public function getPendingRowCount(): int
    {
        return $this->rows()->where('status', 'PENDING')->count();
    }

    /**
     * Scope to filter by tenant
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to filter by company
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Scope to filter by type
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, OpeningBatchType $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to filter by status
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeInStatus(Builder $query, OpeningBatchStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter unlocked batches
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeUnlocked(Builder $query): Builder
    {
        return $query->where('status', '!=', OpeningBatchStatus::Locked);
    }
}
