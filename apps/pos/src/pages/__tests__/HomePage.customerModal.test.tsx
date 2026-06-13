/**
 * Task 4 (P4): Customer search moved to cart-header modal.
 *
 * Asserts:
 * 1. CartCustomerControl trigger renders in the cart header when no customer is attached.
 * 2. The old inline CustomerAttachPanel create-form fields are NOT in the document before
 *    the modal is opened (proxy: `customer.modalTitle` is absent initially).
 * 3. Clicking the trigger opens the CustomerSearchModal (`customer.modalTitle` appears).
 */
import type { ReactNode } from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, act } from '@testing-library/react';

// ---------------------------------------------------------------------------
// Module mocks — must appear before any component import
// ---------------------------------------------------------------------------

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: (_ns?: string) => ({
    t: (key: string) => key,
    i18n: { language: 'en', changeLanguage: vi.fn() },
  }),
}));

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn().mockResolvedValue([]),
  apiPost: vi.fn().mockResolvedValue({}),
  getErrorMessage: (e: unknown) => (e instanceof Error ? e.message : 'error'),
  ApiRequestError: class ApiRequestError extends Error {
    constructor(
      public readonly status: number,
      public readonly apiMessage: string,
      public readonly code: string,
    ) {
      super(apiMessage);
      this.name = 'ApiRequestError';
    }
  },
}));

vi.mock('@/lib/currency', () => ({
  getCurrencyDecimals: (_currency: string) => 2,
  getActiveCurrency: () => 'EUR',
  getActiveCurrencyDecimals: () => 2,
  formatCurrency: (amount: number | string) => String(amount),
  useCurrency: () => ({ currency: 'EUR', decimals: 2, format: (v: number | string) => String(v) }),
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue(null),
}));

vi.mock('@/lib/db/repositories/offlineReceiptRepository', () => ({
  getOfflineReceiptById: vi.fn().mockResolvedValue(null),
}));

vi.mock('@/lib/db/repositories/refundDraftRepository', () => ({
  deleteRefundDraft: vi.fn().mockResolvedValue(undefined),
  getLatestRefundDraft: vi.fn().mockResolvedValue(null),
}));

vi.mock('@/lib/scan/dispatcher', () => ({
  dispatchScan: vi.fn(),
}));

vi.mock('@/lib/scan/resolveScannedCode', () => ({
  resolveScannedCode: vi.fn().mockResolvedValue(null),
}));

vi.mock('@/lib/scan/scanResolutionCache', () => ({
  setCachedScan: vi.fn(),
}));

vi.mock('@/lib/refundFlow/hydrateFromReceipt', () => ({
  hydrateFromReceipt: vi.fn(),
}));

vi.mock('@/lib/refundFlow/cartClassification', () => ({
  decidePayInterception: vi.fn().mockReturnValue(false),
  mustBlockMidModalSettlement: vi.fn().mockReturnValue(false),
}));

vi.mock('@/lib/refundFlow/refundReceiptPrinting', () => ({
  printRefundSettlementArtifacts: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/buildReceiptData', () => ({
  buildEscPosReceiptData: vi.fn().mockResolvedValue(null),
}));

vi.mock('@/lib/discountPermissions', () => ({
  resolveDiscountAccess: vi.fn().mockReturnValue({
    canDiscount: false,
    maxDiscountPercent: 0,
    disabledReason: null,
  }),
}));

vi.mock('@/lib/errorLogging', () => ({
  serializeErrorForLog: vi.fn().mockReturnValue({}),
}));

vi.mock('@/api/receiptApi', () => ({
  fetchReceipt: vi.fn().mockResolvedValue(null),
}));

vi.mock('@/hooks/useBarcodeScanner', () => ({
  useBarcodeScanner: vi.fn(),
}));

// ---------------------------------------------------------------------------
// Heavy component mocks — render as minimal placeholders
// ---------------------------------------------------------------------------

vi.mock('@/components/organisms/ProductGrid', () => ({
  ProductGrid: () => <div data-testid="product-grid" />,
}));

vi.mock('@/components/organisms/TransactionCart', () => ({
  // The customer control is now rendered inside the cart header via the
  // `customerControl` prop, so the stub must surface it for the trigger to
  // be findable.
  TransactionCart: ({ customerControl }: { customerControl?: ReactNode }) => (
    <div data-testid="transaction-cart">{customerControl}</div>
  ),
}));

vi.mock('@/components/organisms/CashPaymentScreen', () => ({
  CashPaymentScreen: () => null,
}));

vi.mock('@/components/organisms/CheckoutSuccessModal', () => ({
  CheckoutSuccessModal: () => null,
}));

vi.mock('@/components/organisms/AdvancedPaymentsModal', () => ({
  AdvancedPaymentsModal: () => null,
}));

vi.mock('@/components/organisms/HeldTransactionsModal', () => ({
  HeldTransactionsModal: () => null,
}));

vi.mock('@/components/organisms/DiscountModal', () => ({
  DiscountModal: () => null,
}));

vi.mock('@/components/organisms/LineDiscountModal', () => ({
  LineDiscountModal: () => null,
}));

vi.mock('@/components/organisms/ModifierSelectionModal', () => ({
  ModifierSelectionModal: () => null,
}));

vi.mock('@/components/organisms/QuantityNumpad', () => ({
  QuantityNumpad: () => null,
}));

vi.mock('@/components/organisms/ToastSmartPrompts', () => ({
  ToastSmartPrompts: () => null,
}));

vi.mock('@/components/molecules/BarcodeChooserModal/BarcodeChooserModal', () => ({
  BarcodeChooserModal: () => null,
}));

vi.mock('@/components/pos/RefundCheckoutFlow', () => ({
  RefundCheckoutFlow: () => null,
}));

vi.mock('@/components/pos/ReceiptScanConfirmationSheet', () => ({
  ReceiptScanConfirmationSheet: () => null,
}));

vi.mock('@/components/pos/ReceiptLocatorScreen', () => ({
  ReceiptLocatorScreen: () => null,
}));

vi.mock('@/components/pos/ResumeRefundDraftBanner', () => ({
  ResumeRefundDraftBanner: () => null,
}));

vi.mock('@/components/atoms/ChainBreakAlert', () => ({
  ChainBreakAlert: () => null,
}));

vi.mock('@/components/atoms/TerminalNotReadyBanner', () => ({
  TerminalNotReadyBanner: () => null,
}));

vi.mock('@/components/atoms/ConsumptionModeToggle', () => ({
  ConsumptionModeToggle: () => null,
}));

vi.mock('@/components/atoms/TableSelector', () => ({
  TableSelector: () => null,
}));

vi.mock('@/components/atoms/MoneyInput', () => ({
  MoneyInput: () => null,
}));

vi.mock('@/components/pos/VariantPickerModal', () => ({
  VariantPickerModal: () => null,
}));

// ---------------------------------------------------------------------------
// Lazy Tauri import — unavailable in jsdom
// ---------------------------------------------------------------------------

vi.mock('@tauri-apps/plugin-sql', () => ({
  default: class Database {},
}));

// ---------------------------------------------------------------------------
// Store + hook mock helpers
// ---------------------------------------------------------------------------

import { useTerminalStore } from '@/stores/terminalStore';
import { useAuthStore } from '@/stores/authStore';
import { useProductStore } from '@/stores/productStore';
import { useCartStore } from '@/stores/cartStore';
import { usePaymentStore } from '@/stores/paymentStore';
import { useHoldStore } from '@/stores/holdStore';
import { useScannerStore } from '@/stores/scannerStore';
import { useRefundFlowStore } from '@/stores/refundFlowStore';
import { useRefundDraftStore } from '@/stores/refundDraftStore';
import { useRefundCheckoutStore } from '@/stores/refundCheckoutStore';
import { useSmartPromptsStore } from '@/stores/smartPromptsStore';
import { useSettingsStore } from '@/stores/settingsStore';
import { useOperatorStore } from '@/stores/operatorStore';

function seedStores() {
  useTerminalStore.setState({
    shift: { id: 'shift-1', terminal_id: 'term-1', status: 'open', opened_at: '', opening_cash: '0', cashier_user_id: 'u-1' } as never,
    terminal: {
      id: 'term-1',
      name: 'POS 1',
      is_training_mode: false,
      max_discount_percent: 0,
      allow_transaction_discounts: false,
      allow_line_discounts: false,
      status: 'active',
    } as never,
    isLoading: false,
    hashChainReady: true,
    openShift: vi.fn().mockResolvedValue(undefined) as never,
  } as never);

  useAuthStore.setState({
    user: {
      id: 'user-1',
      tenantId: 'tenant-1',
      name: 'Cashier',
      email: 'cashier@test.com',
      roles: [],
      permissions: [],
    } as never,
    token: 'tok',
    companyId: 'company-1',
    isAuthenticated: true,
    isLoading: false,
    isInitialized: true,
  } as never);

  useProductStore.setState({
    products: [],
    categories: [],
    isLoading: false,
    companyConfig: null,
    fetchProducts: vi.fn().mockResolvedValue(undefined) as never,
  } as never);

  useCartStore.setState({
    items: [],
    transactionDiscount: null,
    addItem: vi.fn() as never,
    addItemWithDefaults: vi.fn() as never,
    updateLineModifiers: vi.fn() as never,
    updateQuantity: vi.fn() as never,
    removeItem: vi.fn() as never,
    clearCart: vi.fn() as never,
    applyLineDiscount: vi.fn() as never,
    removeLineDiscount: vi.fn() as never,
    subtotal: vi.fn().mockReturnValue('0.000') as never,
    taxAmount: vi.fn().mockReturnValue('0.000') as never,
    discountAmount: vi.fn().mockReturnValue('0.000') as never,
    total: vi.fn().mockReturnValue('0.000') as never,
    itemCount: vi.fn().mockReturnValue(0) as never,
  } as never);

  usePaymentStore.setState({
    selectedCustomer: null,
    paymentMethods: [],
    paymentRepositories: null as never,
    isProcessing: false,
    lastReceipt: null,
    changeDue: '0.000',
    lastReceiptIdempotencyKey: null,
    lastReceiptServerId: null,
    lastReceiptPrintData: null,
    error: null,
    fetchPaymentConfig: vi.fn().mockResolvedValue(undefined) as never,
    processCashCheckout: vi.fn().mockResolvedValue(undefined) as never,
    processAdvancedCheckout: vi.fn().mockResolvedValue(undefined) as never,
    processAccountCharge: vi.fn().mockResolvedValue(undefined) as never,
    clearLastReceipt: vi.fn() as never,
    detachCustomer: vi.fn() as never,
  } as never);

  useHoldStore.setState({
    heldTransactions: [],
    holdCurrentCart: vi.fn().mockResolvedValue(undefined) as never,
    recallTransaction: vi.fn().mockResolvedValue(undefined) as never,
    discardTransaction: vi.fn().mockResolvedValue(undefined) as never,
    loadHeldTransactions: vi.fn().mockResolvedValue(undefined) as never,
  } as never);

  useScannerStore.setState({ lastScan: null } as never);

  useRefundFlowStore.setState({
    pendingScanResult: null,
    activeRefundReceiptUuid: null,
    setPendingScanResult: vi.fn() as never,
    acceptPendingScan: vi.fn() as never,
  } as never);

  useRefundDraftStore.setState({
    existingDraft: null,
    loadDraft: vi.fn().mockResolvedValue(undefined) as never,
  } as never);

  useRefundCheckoutStore.setState({} as never);

  useSmartPromptsStore.setState({
    recommendations: [],
    isLoading: false,
    contextFields: null,
    skinType: null,
    fetchForCart: vi.fn().mockResolvedValue(undefined) as never,
    setSkinType: vi.fn() as never,
    clear: vi.fn() as never,
  } as never);

  useSettingsStore.setState({
    cartPosition: 'start',
  } as never);

  useOperatorStore.setState({
    operator: null,
  } as never);
}

// ---------------------------------------------------------------------------
// Component under test
// ---------------------------------------------------------------------------

import { HomePage } from '../HomePage';

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('HomePage — customer modal (Task P4)', () => {
  beforeEach(() => {
    seedStores();
  });

  it('renders the CartCustomerControl trigger button in the cart header (no customer attached)', () => {
    render(<HomePage />);
    // The trigger renders with aria-label="customer.attach" when no customer is selected.
    // useTranslation t(key) => key, so the button label is the raw i18n key.
    const trigger = screen.getByRole('button', { name: 'customer.attach' });
    expect(trigger).toBeInTheDocument();
  });

  it('does NOT render the customer modal title initially (modal is closed)', () => {
    render(<HomePage />);
    // customer.modalTitle is the title shown inside CustomerSearchModal.
    // With the modal closed it must not appear in the DOM.
    expect(screen.queryByText('customer.modalTitle')).not.toBeInTheDocument();
  });

  it('clicking the trigger opens CustomerSearchModal (modal title appears)', async () => {
    render(<HomePage />);
    const trigger = screen.getByRole('button', { name: 'customer.attach' });
    await act(async () => {
      fireEvent.click(trigger);
    });
    expect(screen.getByText('customer.modalTitle')).toBeInTheDocument();
  });
});
