<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain;

use App\Modules\Import\Domain\Casts\ImportErrorDetailCast;
use App\Modules\Import\Domain\Casts\ImportRowErrorBagCast;
use App\Modules\Import\Domain\Casts\ImportRowSourceCast;
use App\Modules\Import\Domain\Casts\ImportRowWarningCollectionCast;
use App\Modules\Import\Domain\Enums\DuplicateBucket;
use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\Enums\ImportRowOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $import_job_id
 * @property int $row_number
 * @property array<string, mixed> $data
 * @property bool $is_valid
 * @property array<string, array<string>>|null $errors
 * @property list<array{code: string|null, detail: string}>|null $warnings
 * @property bool $is_imported
 * @property string|null $imported_entity_id
 * @property string|null $import_error
 * @property ImportRowOutcome $outcome
 * @property DuplicateBucket|null $duplicate_bucket
 * @property ImportErrorCode|null $import_error_code
 * @property array<string, mixed>|null $import_error_detail
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ImportJob $importJob
 */
class ImportRow extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'import_rows';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'import_job_id',
        'row_number',
        'data',
        'is_valid',
        'errors',
        'warnings',
        'is_imported',
        'imported_entity_id',
        'import_error',
        'outcome',
        'duplicate_bucket',
        'import_error_code',
        'import_error_detail',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_valid' => false,
        'is_imported' => false,
        'outcome' => ImportRowOutcome::Pending->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => ImportRowSourceCast::class,
            'errors' => ImportRowErrorBagCast::class,
            'warnings' => ImportRowWarningCollectionCast::class,
            'is_valid' => 'boolean',
            'is_imported' => 'boolean',
            'outcome' => ImportRowOutcome::class,
            'duplicate_bucket' => DuplicateBucket::class,
            'import_error_code' => ImportErrorCode::class,
            'import_error_detail' => ImportErrorDetailCast::class,
        ];
    }

    /**
     * @return BelongsTo<ImportJob, $this>
     */
    public function importJob(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class);
    }

    /**
     * @param  Builder<ImportRow>  $query
     * @return Builder<ImportRow>
     */
    public function scopeHasWarnings(Builder $query): Builder
    {
        return $this->getConnection()->getDriverName() === 'sqlite'
            ? $query->whereNotNull('warnings')->where('warnings', '!=', '[]')
            : $query->whereRaw('jsonb_array_length(warnings) > 0');
    }

    /**
     * Check if row has validation errors
     */
    public function hasErrors(): bool
    {
        return ! empty($this->errors);
    }

    /**
     * Get flat list of all error messages
     *
     * @return array<string>
     */
    public function getErrorMessages(): array
    {
        if (empty($this->errors)) {
            return [];
        }

        $messages = [];
        foreach ($this->errors as $field => $fieldErrors) {
            foreach ($fieldErrors as $error) {
                $messages[] = "{$field}: {$error}";
            }
        }

        return $messages;
    }
}
