<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Domain;

use Database\Factories\Workshop\TechnicianCertificationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $technician_profile_id
 * @property string $certification_name
 * @property string|null $issuing_body
 * @property string|null $certificate_number
 * @property Carbon|null $issued_at
 * @property Carbon|null $expires_at
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read TechnicianProfile $profile
 */
final class TechnicianCertification extends Model
{
    /** @use HasFactory<TechnicianCertificationFactory> */
    use HasFactory;

    use HasUuids;

    /** @var string */
    protected $table = 'workshop_technician_certifications';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'technician_profile_id',
        'certification_name',
        'issuing_body',
        'certificate_number',
        'issued_at',
        'expires_at',
        'notes',
    ];

    protected static function newFactory(): TechnicianCertificationFactory
    {
        return TechnicianCertificationFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'expires_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<TechnicianProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(TechnicianProfile::class, 'technician_profile_id');
    }

    /**
     * @return HasMany<TechnicianCertificationAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TechnicianCertificationAttachment::class, 'certification_id');
    }
}
