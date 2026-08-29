<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain;

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
 * @property list<array{code: string, detail: string}>|null $warnings
 * @property bool $is_imported
 * @property string|null $imported_entity_id
 * @property string|null $import_error
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
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_valid' => false,
        'is_imported' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'errors' => 'array',
            'warnings' => 'array',
            'is_valid' => 'boolean',
            'is_imported' => 'boolean',
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
