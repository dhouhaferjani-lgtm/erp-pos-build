<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain;

use App\Modules\Company\Domain\Company;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $company_id
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $image_path
 * @property string $path
 * @property int $depth
 * @property int $sort_order
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read Company $company
 * @property-read Category|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Category> $children
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Product> $products
 * @property-read int|null $products_count
 */
class Category extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'parent_id',
        'name',
        'slug',
        'description',
        'image_path',
        'path',
        'depth',
        'sort_order',
        'is_active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'path' => '',
        'depth' => 0,
        'sort_order' => 0,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'depth' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): \Database\Factories\CategoryFactory
    {
        return \Database\Factories\CategoryFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (Category $category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });

        static::created(function (Category $category) {
            $category->updatePath();
        });

        static::updated(function (Category $category) {
            if ($category->wasChanged('parent_id')) {
                $category->updatePath();
                $category->updateDescendantPaths();
            }
        });
    }

    // Relationships

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * @return HasMany<Category>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('sort_order');
    }

    /**
     * @return HasMany<Product>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    // Tree Methods

    public function updatePath(): void
    {
        if ($this->parent_id) {
            $parent = $this->parent;
            $this->path = $parent->path ? "{$parent->path}/{$this->id}" : (string) $this->id;
            $this->depth = $parent->depth + 1;
        } else {
            $this->path = (string) $this->id;
            $this->depth = 0;
        }
        $this->saveQuietly();
    }

    public function updateDescendantPaths(): void
    {
        foreach ($this->children as $child) {
            $child->updatePath();
            $child->updateDescendantPaths();
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Category>
     */
    public function getAncestors(): \Illuminate\Database\Eloquent\Collection
    {
        if (empty($this->path)) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        $ancestorIds = explode('/', $this->path);
        array_pop($ancestorIds); // Remove self

        if (empty($ancestorIds)) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        // Get categories and sort in memory to maintain order
        $categories = static::whereIn('id', $ancestorIds)->get();

        // Sort by position in path
        return $categories->sortBy(function ($category) use ($ancestorIds) {
            return array_search($category->id, $ancestorIds);
        })->values();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Category>
     */
    public function getDescendants(): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('path', 'like', $this->path . '/%')
            ->orderBy('path')
            ->get();
    }

    /**
     * @return array<int, int>
     */
    public function getDescendantIds(): array
    {
        return $this->getDescendants()->pluck('id')->all();
    }

    /**
     * @return array<int, array{id: int, name: string, slug: string}>
     */
    public function getBreadcrumb(): array
    {
        $ancestors = $this->getAncestors();
        $breadcrumb = $ancestors->map(fn ($cat) => [
            'id' => $cat->id,
            'name' => $cat->name,
            'slug' => $cat->slug,
        ])->all();

        $breadcrumb[] = [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
        ];

        return $breadcrumb;
    }

    public function isAncestorOf(Category $category): bool
    {
        return str_starts_with($category->path, $this->path . '/');
    }

    public function isDescendantOf(Category $category): bool
    {
        return str_starts_with($this->path, $category->path . '/');
    }

    // Query Scopes

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Category>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Category>
     */
    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Category>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Category>
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Category>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Category>
     */
    public function scopeWithProductCount($query)
    {
        return $query->withCount('products');
    }

    // Helpers

    public function getFullPath(): string
    {
        return $this->getAncestors()
            ->pluck('name')
            ->push($this->name)
            ->implode(' > ');
    }
}
