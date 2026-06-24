<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Media;

use App\Modules\Media\Domain\Enums\RenditionFormat;
use App\Modules\Media\Domain\Enums\RenditionName;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MediaRendition extends Model
{
    use HasUuids;

    protected $table = 'media_renditions';

    protected $fillable = [
        'tenant_id', 'media_asset_id', 'name', 'format', 'storage_disk', 'storage_path',
        'width', 'height', 'file_size',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => RenditionName::class,
            'format' => RenditionFormat::class,
            'width' => 'integer',
            'height' => 'integer',
            'file_size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}
