<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Fraud alert representing a detected suspicious pattern.
 *
 * Created when:
 * - User exceeds abandoned draft threshold
 * - Suspicious product patterns detected
 * - Rapid cycling behavior identified
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $user_id
 * @property string $alert_type
 * @property string $severity
 * @property string $description
 * @property Carbon $detected_at
 * @property array<array{product_id: string, product_name: string, count: int}>|null $flagged_products
 * @property array<string, mixed>|null $metadata
 * @property string $status
 * @property string|null $assigned_to
 * @property Carbon|null $resolved_at
 * @property string|null $resolution_notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Company $company
 * @property-read User $user
 * @property-read User|null $assignedUser
 */
class FraudAlert extends Model
{
    use HasUuids;

    protected $table = 'fraud_alerts';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'user_id',
        'alert_type',
        'severity',
        'description',
        'detected_at',
        'flagged_products',
        'metadata',
        'status',
        'assigned_to',
        'resolved_at',
        'resolution_notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
            'flagged_products' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * Get the company this alert belongs to.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the user who triggered this alert.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the admin assigned to investigate this alert.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Mark alert as dismissed (false positive).
     */
    public function dismiss(string $notes): void
    {
        $this->update([
            'status' => 'dismissed',
            'resolved_at' => now(),
            'resolution_notes' => $notes,
        ]);
    }

    /**
     * Mark alert as resolved (confirmed and handled).
     */
    public function resolve(string $notes): void
    {
        $this->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolution_notes' => $notes,
        ]);
    }

    /**
     * Assign alert to admin for investigation.
     */
    public function assignTo(string $userId): void
    {
        $this->update([
            'status' => 'investigating',
            'assigned_to' => $userId,
        ]);
    }
}
