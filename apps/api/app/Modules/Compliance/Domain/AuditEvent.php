<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain;

use App\Modules\Company\Domain\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string|null $user_id
 * @property string $event_type
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property array<string, mixed> $payload
 * @property array<string, mixed> $metadata
 * @property string $event_hash
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AuditEvent extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'audit_events';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'user_id',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'payload',
        'metadata',
        'event_hash',
        'occurred_at',
    ];

    /**
     * Virtual properties for constructor-based creation (non-persisted)
     */
    public string $tenantId = '';

    public string $companyId = '';

    public ?string $userId = null;

    public string $eventType = '';

    public string $aggregateType = '';

    public string $aggregateId = '';

    public ?Carbon $occurredAt = null;

    public string $eventHash = '';

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        ?string $companyId = null,
        ?string $userId = null,
        ?string $eventType = null,
        ?string $aggregateType = null,
        ?string $aggregateId = null,
        array $payload = [],
        array $metadata = [],
        array $attributes = []
    ) {
        parent::__construct($attributes);

        // Handle both constructor-style and Eloquent-style creation
        if ($companyId !== null) {
            $this->companyId = $companyId;
            $this->userId = $userId;
            $this->eventType = $eventType ?? '';
            $this->aggregateType = $aggregateType ?? '';
            $this->aggregateId = $aggregateId ?? '';
            $this->occurredAt = now();
            $this->eventHash = $this->calculateHash($payload);

            // Accept tenant_id directly if provided in attributes (optimization)
            // Otherwise look up from company (backward compatibility)
            if (isset($attributes['tenant_id'])) {
                $tenantId = $attributes['tenant_id'];
            } else {
                $company = Company::find($companyId);
                if ($company === null) {
                    throw new \InvalidArgumentException("Company not found with ID: {$companyId}");
                }
                $tenantId = $company->tenant_id;
            }
            $this->tenantId = $tenantId;

            // Set attributes for persistence
            $this->attributes['tenant_id'] = $tenantId;
            $this->attributes['company_id'] = $companyId;
            $this->attributes['user_id'] = $userId;
            $this->attributes['event_type'] = $eventType;
            $this->attributes['aggregate_type'] = $aggregateType;
            $this->attributes['aggregate_id'] = $aggregateId;
            $this->attributes['payload'] = json_encode($payload);
            $this->attributes['metadata'] = json_encode($metadata);
            $this->attributes['event_hash'] = $this->eventHash;
            $this->attributes['occurred_at'] = $this->occurredAt->format('Y-m-d H:i:s');
        }
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Calculate SHA-256 hash for the event
     *
     * @param  array<string, mixed>  $payload
     */
    private function calculateHash(array $payload): string
    {
        $data = json_encode([
            'company_id' => $this->companyId,
            'user_id' => $this->userId,
            'event_type' => $this->eventType,
            'aggregate_type' => $this->aggregateType,
            'aggregate_id' => $this->aggregateId,
            'payload' => $payload,
            'occurred_at' => ($this->occurredAt ?? now())->format('Y-m-d H:i:s.u'),
        ], JSON_THROW_ON_ERROR);

        return hash('sha256', $data);
    }

    /**
     * Scope to filter by company.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}
