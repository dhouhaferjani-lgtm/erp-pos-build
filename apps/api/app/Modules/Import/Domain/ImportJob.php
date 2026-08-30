<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain;

use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\Casts\ColumnMappingCast;
use App\Modules\Import\Domain\Casts\ImportJobOptionsCast;
use App\Modules\Import\Domain\Data\ImportErrorDetailData;
use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\Enums\ImportRowOutcome;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $user_id
 * @property ImportType $type
 * @property ImportStatus $status
 * @property string $original_filename
 * @property string $file_path
 * @property string|null $source_hash
 * @property int $total_rows
 * @property int $processed_rows
 * @property int $successful_rows
 * @property int $skipped_rows
 * @property int $failed_rows
 * @property array<string, string>|null $column_mapping
 * @property array<string, mixed>|null $options
 * @property string|null $error_message
 * @property ImportErrorCode|null $error_code
 * @property ImportErrorDetailData|null $error_detail
 * @property Carbon|null $started_at
 * @property Carbon|null $claimed_at
 * @property Carbon|null $worker_started_at
 * @property Carbon|null $source_purged_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read User $user
 * @property-read Collection<int, ImportRow> $rows
 */
class ImportJob extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'import_jobs';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'user_id',
        'type',
        'status',
        'original_filename',
        'file_path',
        'source_hash',
        'total_rows',
        'processed_rows',
        'successful_rows',
        'skipped_rows',
        'failed_rows',
        'column_mapping',
        'options',
        'error_message',
        'error_code',
        'error_detail',
        'started_at',
        'claimed_at',
        'worker_started_at',
        'source_purged_at',
        'completed_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'processed_rows' => 0,
        'successful_rows' => 0,
        'skipped_rows' => 0,
        'failed_rows' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ImportType::class,
            'status' => ImportStatus::class,
            'column_mapping' => ColumnMappingCast::class,
            'options' => ImportJobOptionsCast::class,
            'error_code' => ImportErrorCode::class,
            'error_detail' => ImportErrorDetailData::class,
            'started_at' => 'datetime',
            'claimed_at' => 'datetime',
            'worker_started_at' => 'datetime',
            'source_purged_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class)->orderBy('row_number');
    }

    /**
     * Check if import can be started.
     *
     * Import can start if status allows and there are valid rows to import.
     * Partial imports are supported - invalid rows will be skipped.
     */
    public function canStart(): bool
    {
        return $this->status->canStartImport() && $this->getValidRowsCount() > 0;
    }

    /**
     * Get count of valid rows ready for import.
     */
    public function getValidRowsCount(): int
    {
        return $this->rows()
            ->where('is_valid', true)
            ->where('outcome', ImportRowOutcome::Pending)
            ->count();
    }

    /**
     * Get progress percentage
     */
    public function getProgressPercentage(): float
    {
        if ($this->total_rows === 0) {
            return 0.0;
        }

        return round(($this->processed_rows / $this->total_rows) * 100, 2);
    }
}
