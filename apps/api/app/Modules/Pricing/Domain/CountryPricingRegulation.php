<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

use App\Modules\Pricing\Domain\Enums\RegulatoryEnforcement;
use App\Modules\Pricing\Domain\Enums\RegulatoryRuleType;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $country_code
 * @property RegulatoryRuleType $rule_type
 * @property RegulatoryEnforcement $enforcement
 * @property bool $active
 * @property array<string, mixed>|null $metadata
 */
class CountryPricingRegulation extends Model
{
    protected $fillable = [
        'country_code',
        'rule_type',
        'enforcement',
        'active',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rule_type' => RegulatoryRuleType::class,
            'enforcement' => RegulatoryEnforcement::class,
            'active' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
