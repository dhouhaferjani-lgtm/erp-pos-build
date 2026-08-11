<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Infrastructure\Models;

use App\Models\SuperAdmin;
use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

final class AdminTemplate extends Model
{
    use CentralConnection;
    use HasUuids;

    protected $fillable = [
        'domain',
        'name',
        'description',
        'status',
        'cloned_from_id',
        'content_hash',
        'standard_ref',
        'certified_country_codes',
        'capability_registry_version',
        'certified_by',
        'published_at',
        'created_by',
    ];

    protected static function booted(): void
    {
        self::creating(static function (self $template): void {
            if ($template->status !== TemplateStatus::Draft) {
                throw new LogicException('Templates must be created as drafts; lifecycle transitions require the publishing service.');
            }
        });

        self::updating(static function (self $template): void {
            $originalBootstrapKey = $template->getRawOriginal('bootstrap_key');
            if ($originalBootstrapKey !== null && $template->isDirty('bootstrap_key')) {
                throw new LogicException('Template bootstrap_key is immutable.');
            }

            $originalStatus = $template->getRawOriginal('status');
            if ($originalStatus === TemplateStatus::Draft->value && $template->isDirty('status')) {
                throw new LogicException('Template lifecycle transitions require the publishing service.');
            }
            if (! in_array($originalStatus, [TemplateStatus::Published->value, TemplateStatus::Archived->value], true)) {
                return;
            }

            throw new LogicException('Published and archived template content and certification are immutable.');
        });

        self::deleting(static function (self $template): void {
            if ($template->status !== TemplateStatus::Draft) {
                throw new LogicException('Published and archived templates are immutable and require lifecycle services.');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'domain' => TemplateDomain::class,
            'status' => TemplateStatus::class,
            'certified_country_codes' => 'array',
            'published_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<AdminTemplateAccount, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(AdminTemplateAccount::class, 'template_id');
    }

    /** @return HasMany<CountryTemplateAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(CountryTemplateAssignment::class, 'template_id');
    }

    /** @return BelongsTo<AdminTemplate, $this> */
    public function clonedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'cloned_from_id');
    }

    /** @return BelongsTo<SuperAdmin, $this> */
    public function certifiedBy(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'certified_by');
    }

    /** @return BelongsTo<SuperAdmin, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'created_by');
    }
}
