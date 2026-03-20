import { useState, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import type React from 'react'
import {
  LayoutDashboard,
  Users,
  FileText,
  ShoppingCart,
  Receipt,
  CreditCard,
  Truck,
  Package,
  FolderTree,
  BarChart3,
  ArrowLeftRight,
  ClipboardList,
  Tag,
  Wallet,
  PiggyBank,
  BookOpen,
  Columns3,
  PenLine,
  Scale,
  TrendingUp,
  Building2,
  Settings,
  Plus,
  Store,
  FileDown,
} from 'lucide-react'
import { usePermissions } from '../../../hooks/usePermissions'
import type { Permission } from '../../../hooks/usePermissions'

interface CommandItem {
  id: string
  label: string
  translationKey: string
  href: string
  icon: React.ComponentType<{ className?: string }>
  section: 'navigation' | 'actions'
  keywords?: string[]
  moduleKey?: string
  permission?: Permission
}

interface UseCommandPaletteReturn {
  query: string
  setQuery: (q: string) => void
  filteredItems: CommandItem[]
  selectedIndex: number
  setSelectedIndex: (i: number) => void
  recentItems: CommandItem[]
  handleKeyDown: (e: React.KeyboardEvent) => void
  executeItem: (item: CommandItem) => void
}

const RECENT_STORAGE_KEY = 'autoerp-command-palette-recent'
const MAX_RECENT = 5

const NAVIGATION_ITEMS: CommandItem[] = [
  { id: 'nav-dashboard', label: 'Dashboard', translationKey: 'nav.dashboard', href: '/dashboard', icon: LayoutDashboard, section: 'navigation', moduleKey: 'dashboard', keywords: ['home', 'overview'] },
  { id: 'nav-customers', label: 'Customers', translationKey: 'nav.customers', href: '/sales/customers', icon: Users, section: 'navigation', moduleKey: 'sales', keywords: ['clients', 'partners'] },
  { id: 'nav-quotes', label: 'Quotes', translationKey: 'nav.quotes', href: '/sales/quotes', icon: FileText, section: 'navigation', moduleKey: 'sales', keywords: ['estimates', 'proposals'] },
  { id: 'nav-sales-orders', label: 'Sales Orders', translationKey: 'nav.salesOrders', href: '/sales/orders', icon: ShoppingCart, section: 'navigation', moduleKey: 'sales', keywords: ['orders'] },
  { id: 'nav-invoices', label: 'Invoices', translationKey: 'nav.invoices', href: '/sales/invoices', icon: Receipt, section: 'navigation', moduleKey: 'sales', keywords: ['bills', 'billing'] },
  { id: 'nav-credit-notes', label: 'Credit Notes', translationKey: 'nav.creditNotes', href: '/sales/credit-notes', icon: CreditCard, section: 'navigation', moduleKey: 'sales', keywords: ['refunds', 'returns'] },
  { id: 'nav-delivery-notes', label: 'Delivery Notes', translationKey: 'nav.deliveryNotes', href: '/inventory/delivery-notes', icon: Truck, section: 'navigation', moduleKey: 'inventory', keywords: ['shipping', 'dispatch'] },
  { id: 'nav-suppliers', label: 'Suppliers', translationKey: 'nav.suppliers', href: '/purchases/suppliers', icon: Users, section: 'navigation', moduleKey: 'purchases', keywords: ['vendors'] },
  { id: 'nav-purchase-orders', label: 'Purchase Orders', translationKey: 'nav.purchaseOrders', href: '/purchases/orders', icon: ShoppingCart, section: 'navigation', moduleKey: 'purchases', keywords: ['buying'] },
  { id: 'nav-goods-receipts', label: 'Goods Receipts', translationKey: 'nav.goodsReceipts', href: '/purchases/receipts', icon: FileDown, section: 'navigation', moduleKey: 'purchases', keywords: ['receiving'] },
  { id: 'nav-return-notes', label: 'Return Notes', translationKey: 'nav.returnNotes', href: '/inventory/return-notes', icon: ArrowLeftRight, section: 'navigation', moduleKey: 'inventory', keywords: ['returns'] },
  { id: 'nav-products', label: 'Products', translationKey: 'nav.products', href: '/inventory/products', icon: Package, section: 'navigation', moduleKey: 'inventory', keywords: ['items', 'goods'] },
  { id: 'nav-categories', label: 'Categories', translationKey: 'nav.categories', href: '/inventory/categories', icon: FolderTree, section: 'navigation', moduleKey: 'inventory', keywords: ['groups'] },
  { id: 'nav-stock-levels', label: 'Stock Levels', translationKey: 'nav.stockLevels', href: '/inventory/stock', icon: BarChart3, section: 'navigation', moduleKey: 'inventory', keywords: ['quantities', 'availability'] },
  { id: 'nav-stock-movements', label: 'Stock Movements', translationKey: 'nav.stockMovements', href: '/inventory/movements', icon: ArrowLeftRight, section: 'navigation', moduleKey: 'inventory', keywords: ['transfers'] },
  { id: 'nav-inventory-counting', label: 'Inventory Counting', translationKey: 'nav.counting', href: '/inventory/counting', icon: ClipboardList, section: 'navigation', moduleKey: 'inventory', keywords: ['stocktake', 'count'] },
  { id: 'nav-price-lists', label: 'Price Lists', translationKey: 'nav.priceLists', href: '/pricing/price-lists', icon: Tag, section: 'navigation', moduleKey: 'pricing', keywords: ['pricing', 'rates'] },
  { id: 'nav-payments', label: 'Payments', translationKey: 'nav.payments', href: '/treasury/payments', icon: Wallet, section: 'navigation', moduleKey: 'treasury', keywords: ['transactions'] },
  { id: 'nav-expenses', label: 'Expenses', translationKey: 'nav.expenses', href: '/expenses', icon: PiggyBank, section: 'navigation', moduleKey: 'treasury', keywords: ['costs', 'spending'] },
  { id: 'nav-chart-of-accounts', label: 'Chart of Accounts', translationKey: 'nav.chartOfAccounts', href: '/finance/chart-of-accounts', icon: BookOpen, section: 'navigation', moduleKey: 'accounts', keywords: ['coa', 'accounting'] },
  { id: 'nav-general-ledger', label: 'General Ledger', translationKey: 'nav.generalLedger', href: '/finance/ledger', icon: Columns3, section: 'navigation', moduleKey: 'accounts', keywords: ['gl', 'ledger'] },
  { id: 'nav-journal-entries', label: 'Journal Entries', translationKey: 'nav.journalEntries', href: '/finance/journal-entries', icon: PenLine, section: 'navigation', moduleKey: 'finance', keywords: ['je', 'entries'] },
  { id: 'nav-trial-balance', label: 'Trial Balance', translationKey: 'nav.trialBalance', href: '/finance/trial-balance', icon: Scale, section: 'navigation', moduleKey: 'finance', keywords: ['tb'] },
  { id: 'nav-profit-loss', label: 'Profit & Loss', translationKey: 'nav.profitLoss', href: '/finance/profit-loss', icon: TrendingUp, section: 'navigation', moduleKey: 'finance', keywords: ['pnl', 'income statement'] },
  { id: 'nav-balance-sheet', label: 'Balance Sheet', translationKey: 'nav.balanceSheet', href: '/finance/balance-sheet', icon: Building2, section: 'navigation', moduleKey: 'finance', keywords: ['bs', 'financial position'] },
  { id: 'nav-settings', label: 'Settings', translationKey: 'nav.settings', href: '/settings', icon: Settings, section: 'navigation', moduleKey: 'settings', keywords: ['preferences', 'configuration'] },
]

const ACTION_ITEMS: CommandItem[] = [
  { id: 'action-create-invoice', label: 'Create Invoice', translationKey: 'commandPalette.createInvoice', href: '/sales/invoices/new', icon: Plus, section: 'actions', permission: 'sales.create', keywords: ['new invoice', 'bill'] },
  { id: 'action-create-quote', label: 'Create Quote', translationKey: 'commandPalette.createQuote', href: '/sales/quotes/new', icon: Plus, section: 'actions', permission: 'sales.create', keywords: ['new quote', 'estimate'] },
  { id: 'action-create-customer', label: 'Create Customer', translationKey: 'commandPalette.createCustomer', href: '/sales/customers/new', icon: Plus, section: 'actions', permission: 'sales.create', keywords: ['new customer', 'add client'] },
  { id: 'action-create-product', label: 'Create Product', translationKey: 'commandPalette.createProduct', href: '/inventory/products/new', icon: Plus, section: 'actions', permission: 'inventory.create', keywords: ['new product', 'add item'] },
  { id: 'action-create-payment', label: 'Create Payment', translationKey: 'commandPalette.createPayment', href: '/treasury/payments/new', icon: Plus, section: 'actions', permission: 'treasury.create', keywords: ['new payment', 'record payment'] },
  { id: 'action-create-expense', label: 'Create Expense', translationKey: 'commandPalette.createExpense', href: '/expenses/new', icon: Plus, section: 'actions', permission: 'treasury.create', keywords: ['new expense', 'add expense'] },
  { id: 'action-create-purchase-order', label: 'Create Purchase Order', translationKey: 'commandPalette.createPurchaseOrder', href: '/purchases/orders/new', icon: Plus, section: 'actions', permission: 'purchases.create', keywords: ['new po', 'buy'] },
  { id: 'action-open-pos', label: 'Open POS', translationKey: 'nav.openPos', href: '/pos/transactions', icon: Store, section: 'actions', permission: 'pos.operate_terminal', keywords: ['point of sale', 'register', 'cash'] },
]

function loadRecentIds(): string[] {
  try {
    const stored = localStorage.getItem(RECENT_STORAGE_KEY)
    if (!stored) return []
    const parsed: unknown = JSON.parse(stored)
    if (!Array.isArray(parsed)) return []
    return parsed.filter((item): item is string => typeof item === 'string')
  } catch {
    return []
  }
}

function saveRecentIds(ids: string[]): void {
  try {
    localStorage.setItem(RECENT_STORAGE_KEY, JSON.stringify(ids.slice(0, MAX_RECENT)))
  } catch {
    // localStorage might be full or unavailable
  }
}

const ALL_ITEMS = [...NAVIGATION_ITEMS, ...ACTION_ITEMS]

export function useCommandPalette(onClose: () => void): UseCommandPaletteReturn {
  const [query, setQuery] = useState('')
  const [selectedIndex, setSelectedIndex] = useState(0)
  const [recentIds, setRecentIds] = useState<string[]>(loadRecentIds)
  const navigate = useNavigate()
  const { canAccessModule, hasPermission } = usePermissions()

  const accessibleItems = ALL_ITEMS.filter((item) => {
    if (item.section === 'navigation' && item.moduleKey) {
      return canAccessModule(item.moduleKey)
    }
    if (item.section === 'actions' && item.permission) {
      return hasPermission(item.permission)
    }
    return true
  })

  const filteredItems = query.trim() === ''
    ? []
    : accessibleItems.filter((item) => {
        const lowerQuery = query.toLowerCase()
        if (item.label.toLowerCase().includes(lowerQuery)) return true
        if (item.keywords?.some((kw) => kw.toLowerCase().includes(lowerQuery))) return true
        return false
      })

  const recentItems = recentIds
    .map((id) => accessibleItems.find((item) => item.id === id))
    .filter((item): item is CommandItem => item !== undefined)

  // Wrap setQuery to also reset selectedIndex
  const updateQuery = useCallback((newQuery: string) => {
    setQuery(newQuery)
    setSelectedIndex(0)
  }, [])

  const executeItem = useCallback((item: CommandItem) => {
    // Update recent items
    const updatedRecent = [item.id, ...recentIds.filter((id) => id !== item.id)].slice(0, MAX_RECENT)
    setRecentIds(updatedRecent)
    saveRecentIds(updatedRecent)

    setQuery('')
    onClose()
    void navigate(item.href)
  }, [navigate, onClose, recentIds])

  const getVisibleItems = useCallback((): CommandItem[] => {
    if (query.trim() !== '') return filteredItems
    return recentItems
  }, [query, filteredItems, recentItems])

  const handleKeyDown = useCallback((e: React.KeyboardEvent) => {
    const items = getVisibleItems()

    switch (e.key) {
      case 'ArrowDown': {
        e.preventDefault()
        setSelectedIndex((prev) => (prev + 1) % Math.max(items.length, 1))
        break
      }
      case 'ArrowUp': {
        e.preventDefault()
        setSelectedIndex((prev) => (prev - 1 + Math.max(items.length, 1)) % Math.max(items.length, 1))
        break
      }
      case 'Enter': {
        e.preventDefault()
        if (selectedIndex >= 0 && selectedIndex < items.length) {
          executeItem(items[selectedIndex])
        }
        break
      }
      case 'Escape': {
        e.preventDefault()
        onClose()
        break
      }
    }
  }, [getVisibleItems, selectedIndex, executeItem, onClose])

  return {
    query,
    setQuery: updateQuery,
    filteredItems,
    selectedIndex,
    setSelectedIndex,
    recentItems,
    handleKeyDown,
    executeItem,
  }
}

export type { CommandItem, UseCommandPaletteReturn }
