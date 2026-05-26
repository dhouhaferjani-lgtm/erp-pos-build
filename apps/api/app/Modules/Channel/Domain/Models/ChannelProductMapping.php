<?php

declare(strict_types=1);

namespace App\Modules\Channel\Domain\Models;

use App\Modules\Product\Domain\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $channel_id
 * @property string $product_id
 * @property string|null $variant_id
 * @property bool $is_published
 * @property Carbon|null $published_at
 * @property Carbon|null $last_synced_at
 * @property string|null $last_sync_hash
 * @property string|null $external_id
 * @property string|null $price_override
 * @property int|null $quantity_cap
 */
final class ChannelProductMapping extends Model
{
    use HasUuids;

    protected $table = 'channel_product_mappings';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'channel_id',
        'product_id',
        'variant_id',
        'is_published',
        'published_at',
        'last_synced_at',
        'last_sync_hash',
        'external_id',
        'price_override',
        'quantity_cap',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'price_override' => 'decimal:3',
            'quantity_cap' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
