import { cn } from '@/lib/utils'
import { StockBadge } from '../../atoms'
import { Info, Package, SlidersHorizontal } from 'lucide-react'
import { useCurrency } from '@/hooks/useCurrency'

export interface Product {
  id: string
  name: string
  sku: string
  barcode?: string | null
  sale_price: string | null
  stock_quantity: number
  image_url?: string
  category?: string
  sellableType?: 'product' | 'composite_item'
  modifierGroups?: import('../../hooks/useActiveMenu').MenuModifierGroup[]
}

export interface ProductCardProps {
  product: Product
  onAddToCart: (product: Product) => void
  onShowInfo: (product: Product) => void
  onCustomize?: (product: Product) => void
  isInCart?: boolean
  touchOptimized?: boolean
  className?: string
}

export function ProductCard({
  product,
  onAddToCart,
  onShowInfo,
  onCustomize,
  isInCart = false,
  touchOptimized = false,
  className,
}: ProductCardProps) {
  const { currency } = useCurrency()
  const isOutOfStock = product.stock_quantity <= 0
  const hasModifiers = (product.modifierGroups?.length ?? 0) > 0

  const handleCardClick = () => {
    if (!isOutOfStock) {
      onAddToCart(product)
    }
  }

  const handleInfoClick = (e: React.MouseEvent) => {
    e.stopPropagation() // Prevent card click
    onShowInfo(product)
  }

  const handleCustomizeClick = (e: React.MouseEvent) => {
    e.stopPropagation() // Prevent card click
    onCustomize?.(product)
  }

  return (
    <div
      onClick={handleCardClick}
      className={cn(
        // Base styles
        'relative flex flex-col',
        'bg-white rounded-lg border-2',
        'transition-all duration-150',

        // Touch optimized
        touchOptimized ? 'min-h-[120px] p-4' : 'p-3',

        // Interactive states
        !isOutOfStock && [
          'cursor-pointer',
          'border-gray-200 hover:border-blue-500',
          'hover:shadow-lg',
          'active:scale-98',
        ],

        // Out of stock state
        isOutOfStock && [
          'opacity-60',
          'cursor-not-allowed',
          'border-gray-200',
        ],

        // In cart indicator
        isInCart && 'border-green-500 bg-green-50',

        // Custom classes
        className
      )}
    >
      {/* Image Section */}
      <div className="relative mb-3">
        {product.image_url ? (
          <img
            src={product.image_url}
            alt={product.name}
            className="w-full h-32 object-cover rounded-md"
          />
        ) : (
          <div
            data-testid="image-placeholder"
            className="w-full h-32 bg-gray-100 rounded-md flex items-center justify-center"
          >
            <Package className="w-12 h-12 text-gray-400" />
          </div>
        )}

        {/* Info Button (Top Right) */}
        <button
          onClick={handleInfoClick}
          className={cn(
            'absolute top-2 end-2',
            'p-2 rounded-full',
            'bg-white/90 hover:bg-white',
            'shadow-md hover:shadow-lg',
            'transition-all duration-150',
            touchOptimized && 'p-3'
          )}
          aria-label="Product info"
        >
          <Info className={cn('text-gray-700', touchOptimized ? 'w-6 h-6' : 'w-5 h-5')} />
        </button>

        {/* Category Badge (Top Left) */}
        {product.category && (
          <span className="absolute top-2 start-2 px-2 py-1 bg-blue-100 text-blue-800 text-xs font-medium rounded">
            {product.category}
          </span>
        )}

        {/* In Cart Indicator */}
        {isInCart && (
          <div className="absolute bottom-2 start-2 px-2 py-1 bg-green-600 text-white text-xs font-bold rounded">
            Added
          </div>
        )}

        {/* Customize Button (Bottom Right) — only for items with modifiers */}
        {hasModifiers && onCustomize && (
          <button
            onClick={handleCustomizeClick}
            className={cn(
              'absolute bottom-2 end-2',
              'p-2 rounded-full',
              'bg-blue-600/90 hover:bg-blue-700',
              'shadow-md hover:shadow-lg',
              'transition-all duration-150',
              touchOptimized && 'p-3'
            )}
            aria-label="Customize product"
          >
            <SlidersHorizontal className={cn('text-white', touchOptimized ? 'w-5 h-5' : 'w-4 h-4')} />
          </button>
        )}
      </div>

      {/* Product Info */}
      <div className="flex flex-col gap-2 flex-1">
        {/* Product Name */}
        <h3
          className={cn(
            'font-semibold text-gray-900 line-clamp-2',
            touchOptimized ? 'text-lg' : 'text-base'
          )}
        >
          {product.name}
        </h3>

        {/* SKU */}
        <p
          className={cn(
            'text-gray-500 font-mono',
            touchOptimized ? 'text-sm' : 'text-xs'
          )}
        >
          {product.sku}
        </p>

        {/* Price and Stock */}
        <div className="flex items-center justify-between mt-auto">
          <span
            className={cn(
              'font-bold text-blue-600',
              touchOptimized ? 'text-xl' : 'text-lg'
            )}
          >
            {product.sale_price ? `${product.sale_price} ${currency}` : 'N/A'}
          </span>

          <StockBadge
            quantity={product.stock_quantity}
            threshold={10}
            size={touchOptimized ? 'md' : 'sm'}
          />
        </div>
      </div>
    </div>
  )
}
