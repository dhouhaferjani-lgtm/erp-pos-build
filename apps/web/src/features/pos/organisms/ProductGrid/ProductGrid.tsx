import { useState, useMemo } from 'react'
import { cn } from '@/lib/utils'
import { ProductCard, type Product } from '../../molecules'
import { POSButton } from '../../atoms'
import { Search, X, Package } from 'lucide-react'

export interface ProductGridProps {
  products: Product[]
  onAddToCart: (product: Product) => void
  onShowProductInfo: (product: Product) => void
  cartProductIds: string[]
  isLoading?: boolean
  showSearch?: boolean
  showCategoryFilter?: boolean
  showProductCount?: boolean
  touchOptimized?: boolean
  className?: string
}

export function ProductGrid({
  products,
  onAddToCart,
  onShowProductInfo,
  cartProductIds,
  isLoading = false,
  showSearch = true,
  showCategoryFilter = true,
  showProductCount = true,
  touchOptimized = false,
  className,
}: ProductGridProps) {
  const [searchQuery, setSearchQuery] = useState('')
  const [selectedCategory, setSelectedCategory] = useState<string | null>(null)

  // Extract unique categories
  const categories = useMemo(() => {
    const uniqueCategories = new Set(
      products
        .map((p) => p.category)
        .filter((c): c is string => c !== undefined)
    )
    return ['All', ...Array.from(uniqueCategories)]
  }, [products])

  // Filter products
  const filteredProducts = useMemo(() => {
    let filtered = products

    // Filter by category
    if (selectedCategory && selectedCategory !== 'All') {
      filtered = filtered.filter((p) => p.category === selectedCategory)
    }

    // Filter by search query
    if (searchQuery.trim()) {
      const query = searchQuery.toLowerCase()
      filtered = filtered.filter(
        (p) =>
          p.name.toLowerCase().includes(query) ||
          p.sku.toLowerCase().includes(query)
      )
    }

    return filtered
  }, [products, selectedCategory, searchQuery])

  const handleClearSearch = () => {
    setSearchQuery('')
  }

  const handleCategorySelect = (category: string) => {
    setSelectedCategory(category === 'All' ? null : category)
  }

  // Loading state
  if (isLoading) {
    return (
      <div className={cn('flex items-center justify-center py-12', className)}>
        <div className="text-center">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto mb-4" />
          <p className="text-gray-600">Loading products...</p>
        </div>
      </div>
    )
  }

  // Empty state
  if (products.length === 0) {
    return (
      <div className={cn('flex items-center justify-center py-12', className)}>
        <div className="text-center">
          <Package className="w-16 h-16 text-gray-400 mx-auto mb-4" />
          <p className="text-gray-600 text-lg">No products available</p>
        </div>
      </div>
    )
  }

  return (
    <div className={cn('flex flex-col gap-4', className)}>
      {/* Header Section */}
      <div className="flex flex-col gap-3">
        {/* Search Bar */}
        {showSearch && (
          <div className="relative">
            <Search className="absolute start-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Search products..."
              className={cn(
                'w-full ps-10 pe-10 py-3 rounded-lg border border-gray-300',
                'focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500',
                touchOptimized && 'py-4 text-lg'
              )}
            />
            {searchQuery && (
              <button
                onClick={handleClearSearch}
                className="absolute end-3 top-1/2 -translate-y-1/2"
                aria-label="Clear search"
              >
                <X className="w-5 h-5 text-gray-400 hover:text-gray-600" />
              </button>
            )}
          </div>
        )}

        {/* Category Filter */}
        {showCategoryFilter && (
          <div className="flex gap-2 flex-wrap">
            {categories.map((category) => (
              <POSButton
                key={category}
                variant={
                  (selectedCategory === category) ||
                  (selectedCategory === null && category === 'All')
                    ? 'primary'
                    : 'secondary'
                }
                size={touchOptimized ? 'md' : 'sm'}
                onClick={() => handleCategorySelect(category)}
              >
                {category}
              </POSButton>
            ))}
          </div>
        )}

        {/* Product Count */}
        {showProductCount && (
          <div className="text-sm text-gray-600">
            {filteredProducts.length} {filteredProducts.length === 1 ? 'product' : 'products'}
          </div>
        )}
      </div>

      {/* Products Grid */}
      {filteredProducts.length === 0 ? (
        <div className="flex items-center justify-center py-12">
          <div className="text-center">
            <Package className="w-16 h-16 text-gray-400 mx-auto mb-4" />
            <p className="text-gray-600 text-lg">No products found</p>
            <p className="text-gray-500 text-sm mt-2">
              Try adjusting your search or filters
            </p>
          </div>
        </div>
      ) : (
        <div
          className={cn(
            'grid gap-4',
            touchOptimized
              ? 'grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6'
              : 'grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5'
          )}
        >
          {filteredProducts.map((product) => (
            <ProductCard
              key={product.id}
              product={product}
              onAddToCart={onAddToCart}
              onShowInfo={onShowProductInfo}
              isInCart={cartProductIds.includes(product.id)}
              touchOptimized={touchOptimized}
            />
          ))}
        </div>
      )}
    </div>
  )
}
