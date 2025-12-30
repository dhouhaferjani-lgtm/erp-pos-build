<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Entities;

use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $country_code
 * @property DocumentType $document_type
 * @property FiscalCategory|null $fiscal_category
 * @property string $stamp_amount
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon $effective_from
 * @property \Illuminate\Support\Carbon|null $effective_to
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class StampDutyRule extends Model
{
    use HasUuids;

    protected $table = 'stamp_duty_rules';

    protected $fillable = [
        'country_code',
        'document_type',
        'fiscal_category',
        'stamp_amount',
        'is_active',
        'effective_from',
        'effective_to',
        'metadata',
    ];

    protected $casts = [
        'document_type' => DocumentType::class,
        'fiscal_category' => FiscalCategory::class,
        'is_active' => 'boolean',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'metadata' => 'array',
    ];

    /**
     * Check if this rule is currently effective
     */
    public function isCurrentlyEffective(): bool
    {
        $now = now()->startOfDay();

        if ($this->effective_from->greaterThan($now)) {
            return false;
        }

        if ($this->effective_to && $this->effective_to->lessThan($now)) {
            return false;
        }

        return $this->is_active;
    }

    /**
     * Check if this rule applies to a specific date
     */
    public function isEffectiveOn(\Illuminate\Support\Carbon $date): bool
    {
        if ($this->effective_from->greaterThan($date)) {
            return false;
        }

        if ($this->effective_to && $this->effective_to->lessThan($date)) {
            return false;
        }

        return $this->is_active;
    }

    /**
     * Check if this rule matches document type and fiscal category
     */
    public function matchesDocument(DocumentType $documentType, ?FiscalCategory $fiscalCategory): bool
    {
        if ($this->document_type !== $documentType) {
            return false;
        }

        // If rule has no fiscal category specified, it matches all
        if ($this->fiscal_category === null) {
            return true;
        }

        // Otherwise, fiscal categories must match
        return $this->fiscal_category === $fiscalCategory;
    }
}
