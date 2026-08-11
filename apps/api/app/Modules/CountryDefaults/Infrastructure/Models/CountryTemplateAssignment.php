<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Infrastructure\Models;

use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

final class CountryTemplateAssignment extends Model
{
    use CentralConnection;
    use HasUuids;

    protected $fillable = [
        'country_code',
        'domain',
        'template_id',
    ];

    protected static function booted(): void
    {
        self::saving(static function (self $assignment): void {
            $countryCode = strtoupper(trim((string) $assignment->country_code));
            if ($countryCode !== '*' && preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
                throw new InvalidArgumentException('Assignment country code must be two letters or wildcard.');
            }

            $assignment->country_code = $countryCode;
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'domain' => TemplateDomain::class,
        ];
    }

    /** @return BelongsTo<AdminTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(AdminTemplate::class, 'template_id');
    }
}
