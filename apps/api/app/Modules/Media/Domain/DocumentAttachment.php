<?php

declare(strict_types=1);

namespace App\Modules\Media\Domain;

use App\Modules\Document\Domain\Document;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $document_id
 * @property string $uploaded_by
 * @property string $filename
 * @property string $original_filename
 * @property string $mime_type
 * @property int $file_size
 * @property string $storage_path
 * @property string $storage_disk
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Document $document
 * @property-read User $uploader
 *
 * @method static Builder<static> forTenant(string $tenantId)
 * @method static Builder<static> forDocument(string $documentId)
 */
class DocumentAttachment extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'document_attachments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'document_id',
        'uploaded_by',
        'filename',
        'original_filename',
        'mime_type',
        'file_size',
        'storage_path',
        'storage_disk',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Scope to filter attachments by tenant
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to filter attachments by document
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForDocument(Builder $query, string $documentId): Builder
    {
        return $query->where('document_id', $documentId);
    }

    /**
     * Get the file size formatted for display
     */
    public function getFormattedFileSizeAttribute(): string
    {
        $bytes = $this->file_size;

        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2).' GB';
        }

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return $bytes.' bytes';
    }

    /**
     * Check if the attachment is an image
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * Check if the attachment is a PDF
     */
    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }
}
