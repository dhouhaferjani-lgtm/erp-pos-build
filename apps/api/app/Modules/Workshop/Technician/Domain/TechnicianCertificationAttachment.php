<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Domain;

use App\Modules\Identity\Domain\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $certification_id
 * @property string $filename
 * @property string $mime_type
 * @property int $byte_size
 * @property string $storage_disk
 * @property string $storage_path
 * @property string|null $uploaded_by_user_id
 * @property Carbon $created_at
 */
final class TechnicianCertificationAttachment extends Model
{
    use HasUuids;

    /** @var string */
    protected $table = 'workshop_technician_certification_attachments';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'certification_id',
        'filename',
        'mime_type',
        'byte_size',
        'storage_disk',
        'storage_path',
        'uploaded_by_user_id',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TechnicianCertification, $this>
     */
    public function certification(): BelongsTo
    {
        return $this->belongsTo(TechnicianCertification::class, 'certification_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
