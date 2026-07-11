/**
 * Cart-always-foreground v1 — HomePage pane invariant (spec §5).
 *
 * The product detail view is now an in-pane sibling of the always-visible cart
 * (not a `fixed inset-0` overlay). jsdom cannot hit-test, so "cart clickable"
 * is asserted via its structural proxy: with a pane open there is NO
 * fixed+inset-0 element anywhere in the document, the TransactionCart is still
 * in the tree, and the grid is hidden but NOT unmounted. Real pointer
 * reachability is verified in the final playwright/on-device pass (plan Task 8).
 *
 * Harness copied verbatim from HomePage.customerModal.test.tsx with five mock
 * changes (see inline notes).
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

// Task 4 (Rev 2 U3): the vanished-line confirm guard toasts via sonner.
// HomePage does not mount <Toaster> (that lives in App.tsx:440), so mocking
// only `toast` is safe.
vi.mock('sonner', () => ({
  toast: { error: vi.fn(), success: vi.fn(), warning: vi.fn() },
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

// Change (1): ProductGrid stub exposes trigger buttons for the pane callbacks.
vi.mock('@/components/organisms/ProductGrid', () => ({
  ProductGrid: ({
    onViewDetails,
    onCustomize,
  }: {
    onViewDetails?: (p: unknown) => void;
    onCustomize?: (p: unknown) => void;
  }) => (
    <div data-testid="product-grid">
      <button
        data-testid="open-detail-trigger"
        onClick={() =>
          onViewDetails?.({ id: 'p1', name: 'Widget', sku: 'W1', sale_price: '9.990', stock_quantity: 5 })
        }
      >
        open detail
      </button>
      <button
        data-testid="open-customize-trigger"
        onClick={() =>
          onCustomize?.({ id: 'p2', name: 'Gadget', sku: 'G1', sale_price: '5.000', stock_quantity: 3 })
        }
      >
        open customize
      </button>
    </div>
  ),
}));

// Change (2): mock the drawer barrel so the real sheet (which pulls
// product/operator/terminal stores + cross-location hooks) stays out of this
// structural test — the sheet's own semantics are pinned in Task 1's suite.
vi.mock('@/components/organisms/ProductDetailDrawer', () => ({
  ProductDetailSheet: ({ product }: { product: { name: string } }) => (
    <div data-testid="product-detail-sheet-stub">{product.name}</div>
  ),
}));

vi.mock('@/components/organisms/TransactionCart', () => ({
  // The customer control is now rendered inside the cart header via the
  // `customerControl` prop, so the stub must surface it for the trigger to
  // be findable. Task 4 (Rev 2 U3): also surface onEditModifiers so tests can
  // drive handleEditModifiers → the customize-EDIT path.
  TransactionCart: ({
    customerControl,
    onEditModifiers,
  }: {
    customerControl?: ReactNode;
    onEditModifiers?: (itemId: string) => void;
  }) => (
    <div data-testid="transaction-cart">
      {customerControl}
      <button data-testid="edit-line-trigger" onClick={() => onEditModifiers?.('line-1')}>
        edit line
      </button>
    </div>
  ),
}));

vi.mock('@/components/organisms/CashPaymentScreen', () => ({
  CashPaymentScreen: () => null,
}));

// Change (4) (Rev 2, U4): CheckoutSuccessModal stub exposes a trigger so tests
// can drive handleNewSale (HomePage passes it as onClose; mount gated on
// lastReceipt, which the settle test seeds).
vi.mock('@/components/organisms/CheckoutSuccessModal', () => ({
  CheckoutSuccessModal: ({ onClose }: { onClose: () => void }) => (
    <button data-testid="new-sale-trigger" onClick={onClose}>
      new sale
    </button>
  ),
}));

vi.mock('@/components/organisms/AdvancedPaymentsModal', () => ({
  AdvancedPaymentsModal: () => null,
}));

// Change (5) (Rev 2, U3/U4): HeldTransactionsModal stub exposes a recall
// trigger (HomePage passes onRecall={(id) => void handleRecall(id)}).
vi.mock('@/components/organisms/HeldTransactionsModal', () => ({
  HeldTransactionsModal: ({ onRecall }: { onRecall: (id: string) => void }) => (
    <button data-testid="recall-trigger" onClick={() => onRecall('held-1')}>
      recall
    </button>
  ),
}));

vi.mock('@/components/organisms/DiscountModal', () => ({
  DiscountModal: () => null,
}));

vi.mock('@/components/organisms/LineDiscountModal', () => ({
  LineDiscountModal: () => null,
}));

// Change (3) (Task 4, Rev 2 U3): the composer stub surfaces the confirm
// callback so the customize-EDIT guard tests can drive onConfirm.
vi.mock('@/components/organisms/ModifierSelectionModal', () => ({
  ModifierComposerSheet: ({
    product,
    onConfirm,
  }: {
    product: { name: string };
    onConfirm: (selectedModifiers: unknown[]) => void;
  }) => (
    <div data-testid="modifier-composer-stub">
      {product.name}
      <button data-testid="composer-confirm-trigger" onClick={() => onConfirm([])}>
        confirm
      </button>
    </div>
  ),
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
// Cart-always-foreground v1 — THE story's regression guard (spec §5).
// jsdom cannot hit-test, so "cart clickable" is asserted via its structural
// proxy: with a pane open there is NO fixed+inset-0 element anywhere in the
// document, the TransactionCart is still in the tree, and the grid is hidden
// but NOT unmounted. Real pointer reachability is verified in the final
// playwright/on-device pass (plan Task 8).
// ---------------------------------------------------------------------------
describe('HomePage — pane invariant (cart always foreground)', () => {
  beforeEach(() => {
    seedStores();
  });

  it('opens the detail pane with the cart present and no fixed-inset overlay mounted', async () => {
    render(<HomePage />);
    await act(async () => {
      fireEvent.click(screen.getByTestId('open-detail-trigger'));
    });

    // Detail renders IN the pane host, not an overlay.
    expect(screen.getByTestId('product-detail-sheet-stub')).toHaveTextContent('Widget');
    expect(screen.getByTestId('product-pane-detail')).toBeInTheDocument();

    // THE invariant: no fixed inset-0 surface exists while the pane is open.
    const fixedInsetOverlays = Array.from(document.querySelectorAll('[class]')).filter(
      (el) => el.classList.contains('fixed') && el.classList.contains('inset-0'),
    );
    expect(fixedInsetOverlays).toHaveLength(0);

    // Cart untouched beside the pane.
    expect(screen.getByTestId('transaction-cart')).toBeInTheDocument();

    // Grid hidden, NOT unmounted (state preservation, spec §1).
    expect(screen.getByTestId('product-grid')).toBeInTheDocument();
    expect(screen.getByTestId('product-pane-grid')).toHaveClass('hidden');
  });

  it('Escape closes the detail pane and restores the grid', async () => {
    render(<HomePage />);
    await act(async () => {
      fireEvent.click(screen.getByTestId('open-detail-trigger'));
    });
    expect(screen.getByTestId('product-detail-sheet-stub')).toBeInTheDocument();

    await act(async () => {
      fireEvent.keyDown(window, { key: 'Escape' });
    });
    expect(screen.queryByTestId('product-detail-sheet-stub')).toBeNull();
    expect(screen.getByTestId('product-pane-grid')).not.toHaveClass('hidden');
  });

  it('opening customize closes the detail pane (setter mutual exclusion, spec §1)', async () => {
    render(<HomePage />);
    await act(async () => {
      fireEvent.click(screen.getByTestId('open-detail-trigger'));
    });
    expect(screen.getByTestId('product-detail-sheet-stub')).toBeInTheDocument();

    await act(async () => {
      fireEvent.click(screen.getByTestId('open-customize-trigger'));
    });
    // detailProduct must have been cleared by handleCustomize.
    expect(screen.queryByTestId('product-detail-sheet-stub')).toBeNull();
  });

  it('settle/new-sale closes the detail pane — the next sale starts on the grid (Rev 2, U4)', async () => {
    // CheckoutSuccessModal only mounts when lastReceipt is set (HomePage.tsx:1549-1558).
    usePaymentStore.setState({
      lastReceipt: { receipt_number: 'R-1', total: '10.000' } as never,
    } as never);
    render(<HomePage />);
    await act(async () => {
      fireEvent.click(screen.getByTestId('open-detail-trigger'));
    });
    expect(screen.getByTestId('product-detail-sheet-stub')).toBeInTheDocument();

    await act(async () => {
      fireEvent.click(screen.getByTestId('new-sale-trigger'));
    });
    expect(screen.queryByTestId('product-detail-sheet-stub')).toBeNull();
    expect(screen.getByTestId('product-pane-grid')).not.toHaveClass('hidden');
  });

  it('recall leaves the DETAIL pane open (spec §4: detail is product context, not cart state)', async () => {
    // seedStores' recallTransaction resolves undefined (early return before
    // replaceCart) — override it so handleRecall reaches the replaceCart path.
    useHoldStore.setState({
      recallTransaction: vi
        .fn()
        .mockResolvedValue({ items: [], transactionDiscount: undefined }) as never,
    } as never);
    render(<HomePage />);
    await act(async () => {
      fireEvent.click(screen.getByTestId('open-detail-trigger'));
    });
    await act(async () => {
      fireEvent.click(screen.getByTestId('recall-trigger'));
    });
    expect(screen.getByTestId('product-detail-sheet-stub')).toBeInTheDocument();
  });

  it('hosts customize as an in-pane view (no fixed-inset overlay) and detail↔customize stay exclusive', async () => {
    render(<HomePage />);
    await act(async () => {
      fireEvent.click(screen.getByTestId('open-customize-trigger'));
    });

    expect(screen.getByTestId('modifier-composer-stub')).toHaveTextContent('Gadget');
    expect(screen.getByTestId('product-pane-customize')).toBeInTheDocument();
    const fixedInsetOverlays = Array.from(document.querySelectorAll('[class]')).filter(
      (el) => el.classList.contains('fixed') && el.classList.contains('inset-0'),
    );
    expect(fixedInsetOverlays).toHaveLength(0);
    expect(screen.getByTestId('transaction-cart')).toBeInTheDocument();
    expect(screen.getByTestId('product-pane-grid')).toHaveClass('hidden');

    // Opening detail closes customize (mutual exclusion, the other direction).
    await act(async () => {
      fireEvent.click(screen.getByTestId('open-detail-trigger'));
    });
    expect(screen.queryByTestId('modifier-composer-stub')).toBeNull();
    expect(screen.getByTestId('product-detail-sheet-stub')).toBeInTheDocument();
  });

  it('recall clears the CUSTOMIZE pane (cart replacement invalidates the in-flight edit, Rev 2 U3/U4)', async () => {
    useHoldStore.setState({
      recallTransaction: vi
        .fn()
        .mockResolvedValue({ items: [], transactionDiscount: undefined }) as never,
    } as never);
    render(<HomePage />);
    await act(async () => {
      fireEvent.click(screen.getByTestId('open-customize-trigger'));
    });
    expect(screen.getByTestId('modifier-composer-stub')).toBeInTheDocument();

    await act(async () => {
      fireEvent.click(screen.getByTestId('recall-trigger'));
    });
    expect(screen.queryByTestId('modifier-composer-stub')).toBeNull();
  });

  it('customize-EDIT confirm on a vanished line toasts and closes — never a silent no-op (Rev 2, U3)', async () => {
    const { toast } = await import('sonner');
    // Seed a cart line + its matching product so handleEditModifiers
    // (HomePage.tsx:996-1006) can open the composer in EDIT mode.
    const gadget = { id: 'p2', name: 'Gadget', sku: 'G1', sale_price: '5.000', stock_quantity: 3 };
    useProductStore.setState({ products: [gadget] } as never);
    useCartStore.setState({
      items: [{ id: 'line-1', product: { id: 'p2', name: 'Gadget' }, quantity: 1 }] as never,
    } as never);

    render(<HomePage />);
    await act(async () => {
      fireEvent.click(screen.getByTestId('edit-line-trigger'));
    });
    expect(screen.getByTestId('modifier-composer-stub')).toBeInTheDocument();

    // The cart stays interactive while composing — the edited line vanishes
    // under the composer (removal / recall / clear).
    act(() => {
      useCartStore.setState({ items: [] } as never);
    });

    await act(async () => {
      fireEvent.click(screen.getByTestId('composer-confirm-trigger'));
    });
    expect(vi.mocked(useCartStore.getState().updateLineModifiers)).not.toHaveBeenCalled();
    expect(vi.mocked(toast.error)).toHaveBeenCalledWith('modifiers.lineGone');
    expect(screen.queryByTestId('modifier-composer-stub')).toBeNull();
  });
});
