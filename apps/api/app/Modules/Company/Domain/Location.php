<?php

declare(strict_types=1);

namespace App\Modules\Company\Domain;

use App\Modules\Company\Domain\Enums\LocationType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Location model - represents a physical place.
 *
 * Location = Physical place (shop, warehouse, office, mobile).
 * Each company can have multiple locations. Stock is tracked per location.
 *
 * @property string $id UUID of the location
 * @property string $company_id UUID of the owning company
 * @property string $name Location name
 * @property string|null $code Internal location code
 * @property LocationType $type Location type (shop, warehouse, office, mobile)
 * @property string|null $phone Contact phone
 * @property string|null $email Contact email
 * @property string|null $address_street Street address
 * @property string|null $address_city City
 * @property string|null $address_postal_code Postal/ZIP code
 * @property string|null $address_country ISO 3166-1 alpha-2 country code
 * @property float|null $latitude Latitude coordinate
 * @property float|null $longitude Longitude coordinate
 * @property bool $is_default Whether this is the default location
 * @property bool $is_active Whether the location is active
 * @property bool $pos_enabled Whether POS is enabled at this location
 * @property string|null $receipt_header Custom receipt header
 * @property string|null $receipt_footer Custom receipt footer
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Company $company
 * @property-read string|null $full_address Computed full address
 */
class Location extends Model
{
    /** @use HasFactory<\Database\Factories\LocationFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): \Database\Factories\LocationFactory
    {
        return \Database\Factories\LocationFactory::new();
    }

    /**
     * The table associated with the model.
     */
    protected $table = 'locations';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'name',
        'code',
        'type',
        'phone',
        'email',
        'address_street',
        'address_city',
        'address_postal_code',
        'address_country',
        'latitude',
        'longitude',
        'is_default',
        'is_active',
        'pos_enabled',
        'receipt_header',
        'receipt_footer',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LocationType::class,
            'latitude' => 'float',
            'longitude' => 'float',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'pos_enabled' => 'boolean',
        ];
    }

    /**
     * Get the company that owns this location.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get formatted full address.
     */
    public function getFullAddressAttribute(): ?string
    {
        $parts = array_filter([
            $this->address_street,
            $this->address_city,
            $this->address_postal_code,
            $this->address_country,
        ]);

        return count($parts) > 0 ? implode(', ', $parts) : null;
    }

    /**
     * Check if the location is a shop.
     */
    public function isShop(): bool
    {
        return $this->type === LocationType::Shop;
    }

    /**
     * Check if the location is a warehouse.
     */
    public function isWarehouse(): bool
    {
        return $this->type === LocationType::Warehouse;
    }

    /**
     * Scope to filter by company.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeForCompany(\Illuminate\Database\Eloquent\Builder $query, string $companyId): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('company_id', $companyId);
    }
}
