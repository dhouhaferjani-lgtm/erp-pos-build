/**
 * POS Feature Module
 *
 * Complete Point of Sale component library built with:
 * - Test-Driven Development (TDD)
 * - Atomic Design principles
 * - Touch optimization for tablet/mobile use
 * - TypeScript strict mode
 *
 * Component Hierarchy:
 * - Atoms: POSButton, MoneyInput, StockBadge
 * - Molecules: ProductCard, CartLineItem
 * - Organisms: ProductGrid, TransactionCart, Calculator
 * - Pages: POSPage, ShiftDashboardPage
 */

// Atoms
export { POSButton, type POSButtonProps } from './atoms/POSButton'
export { MoneyInput, type MoneyInputProps } from './atoms/MoneyInput'
export { StockBadge, type StockBadgeProps } from './atoms/StockBadge'

// Molecules
export { ProductCard, type ProductCardProps, type Product } from './molecules/ProductCard'
export { CartLineItem, type CartLineItemProps, type CartItem } from './molecules/CartLineItem'

// Organisms
export { ProductGrid, type ProductGridProps } from './organisms/ProductGrid'
export { TransactionCart, type TransactionCartProps, type Customer } from './organisms/TransactionCart'
export { Calculator, type CalculatorProps } from './organisms/Calculator'
export { PaymentPanel, type PaymentPanelProps } from './organisms/PaymentPanel'
export {
  AdvancedPaymentsModal,
  type AdvancedPaymentsModalProps,
  type PaymentData,
  type PaymentMethodAmount,
  type DiscountData
} from './organisms/AdvancedPaymentsModal'

// Pages
export { POSPage, type POSPageProps } from './pages/POSPage'
export { ShiftDashboardPage, type ShiftDashboardPageProps, type Shift, type Terminal } from './pages/ShiftDashboardPage'

// Re-export common types used across components
export type { CartItem as POSCartItem } from './molecules/CartLineItem'
export type { Product as POSProduct } from './molecules/ProductCard'
export type { Customer as POSCustomer } from './organisms/TransactionCart'
