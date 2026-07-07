<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\DocumentIngestion\Domain\Enums\DocumentKind;
use App\Modules\DocumentIngestion\Domain\Enums\IngestionStatus;
use App\Modules\DocumentIngestion\Domain\Exceptions\InvalidIngestionTransition;
use App\Modules\Identity\Domain\User;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property DocumentKind $kind
 * @property IngestionStatus $status
 * @property string $media_asset_id
 * @property string $checksum
 * @property string|null $provider
 * @property string|null $provider_model
 * @property array<string, mixed>|null $extraction
 * @property array<string, mixed>|null $confidence_summary
 * @property array<string, mixed>|null $suggestions
 * @property string|null $committed_type
 * @property string|null $committed_id
 * @property array<string, mixed>|null $error
 * @property string $created_by
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read MediaAsset $mediaAsset
 * @property-read User $creator
 */
final class DocumentIngestion extends Model
{
    use HasUuids;

    protected $table = 'document_ingestions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'kind',
        'status',
        'media_asset_id',
        'checksum',
        'provider',
        'provider_model',
        'extraction',
        'confidence_summary',
        'suggestions',
        'committed_type',
        'committed_id',
        'error',
        'created_by',
    ];

    public function transitionTo(IngestionStatus $next): void
    {
        if (! in_array($next, $this->status->allowedNext(), true)) {
            throw InvalidIngestionTransition::from($this->status, $next);
        }

        $this->status = $next;
        $this->save();
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
     * @return BelongsTo<MediaAsset, $this>
     */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DocumentKind::class,
            'status' => IngestionStatus::class,
            'extraction' => 'array',
            'confidence_summary' => 'array',
            'suggestions' => 'array',
            'error' => 'array',
        ];
    }
}
