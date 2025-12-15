<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

use App\Modules\Accounting\Domain\Enums\OpeningImportRowStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Opening Balance Import Row - staging for imported data before posting
 *
 * @property string $id
 * @property string $batch_id
 * @property string $row_type
 * @property int $row_number
 * @property array<string, mixed> $raw_data
 * @property OpeningImportRowStatus $status
 * @property array<string, array<string>>|null $validation_errors
 * @property array<string, mixed>|null $mapped_data
 * @property string|null $mapped_entity_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OpeningBalanceBatch $batch
 */
class OpeningBalanceImportRow extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'opening_balance_import_rows';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'batch_id',
        'row_type',
        'row_number',
        'raw_data',
        'status',
        'validation_errors',
        'mapped_data',
        'mapped_entity_id',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => OpeningImportRowStatus::Pending,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OpeningImportRowStatus::class,
            'raw_data' => 'array',
            'validation_errors' => 'array',
            'mapped_data' => 'array',
        ];
    }

    /**
     * @return BelongsTo<OpeningBalanceBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(OpeningBalanceBatch::class, 'batch_id');
    }

    /**
     * Check if row has validation errors
     */
    public function hasErrors(): bool
    {
        return ! empty($this->validation_errors);
    }

    /**
     * Check if row is valid
     */
    public function isValid(): bool
    {
        return $this->status === OpeningImportRowStatus::Valid;
    }

    /**
     * Check if row is invalid
     */
    public function isInvalid(): bool
    {
        return $this->status === OpeningImportRowStatus::Invalid;
    }

    /**
     * Check if row is pending validation
     */
    public function isPending(): bool
    {
        return $this->status === OpeningImportRowStatus::Pending;
    }

    /**
     * Check if row was skipped
     */
    public function isSkipped(): bool
    {
        return $this->status === OpeningImportRowStatus::Skipped;
    }

    /**
     * Check if row has been posted
     */
    public function isPosted(): bool
    {
        return $this->status === OpeningImportRowStatus::Posted;
    }

    /**
     * Get flat list of all error messages
     *
     * @return array<string>
     */
    public function getErrorMessages(): array
    {
        if (empty($this->validation_errors)) {
            return [];
        }

        $messages = [];
        foreach ($this->validation_errors as $field => $fieldErrors) {
            foreach ($fieldErrors as $error) {
                $messages[] = "{$field}: {$error}";
            }
        }

        return $messages;
    }

    /**
     * Get a specific value from raw_data
     */
    public function getRawValue(string $key, mixed $default = null): mixed
    {
        return $this->raw_data[$key] ?? $default;
    }

    /**
     * Get a specific value from mapped_data
     */
    public function getMappedValue(string $key, mixed $default = null): mixed
    {
        return $this->mapped_data[$key] ?? $default;
    }

    /**
     * Scope to filter by batch
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForBatch(Builder $query, string $batchId): Builder
    {
        return $query->where('batch_id', $batchId);
    }

    /**
     * Scope to filter by row type
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeOfRowType(Builder $query, string $rowType): Builder
    {
        return $query->where('row_type', $rowType);
    }

    /**
     * Scope to filter by status
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeInStatus(Builder $query, OpeningImportRowStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter valid rows only
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query->where('status', OpeningImportRowStatus::Valid);
    }

    /**
     * Scope to filter invalid rows only
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeInvalid(Builder $query): Builder
    {
        return $query->where('status', OpeningImportRowStatus::Invalid);
    }

    /**
     * Scope to filter pending rows only
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', OpeningImportRowStatus::Pending);
    }
}
