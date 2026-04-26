import { invoke } from '@tauri-apps/api/core';
import { fetch as tauriFetch } from '@tauri-apps/plugin-http';
import { useAuthStore } from '@/stores/authStore';
import { usePrinterStore } from '@/stores/printerStore';
import { useCashDrawerStore } from '@/stores/cashDrawerStore';

// ── Types ──

export type PrinterConnectionType = 'usb' | 'network' | 'windows';

export interface PrinterInfo {
  id: string;
  name: string;
  connection_type: PrinterConnectionType;
  address: string;
}

export interface CompanyInfo {
  name: string;
  address_line1: string;
  address_line2: string | null;
  city: string;
  postal_code: string;
  country: string;
  tax_id: string;
  phone: string | null;
}

export interface ModifierLine {
  name: string;
  price: string;
}

export interface ReceiptLine {
  name: string;
  quantity: string;
  unit_price: string;
  line_total: string;
  modifiers: ModifierLine[] | null;
  discount: string | null;
}

export interface VatBreakdownLine {
  rate: string;
  taxable: string;
  tax: string;
}

export interface PaymentLine {
  method: string;
  amount: string;
}

export interface ReceiptLabels {
  receipt?: string;
  date?: string;
  terminal?: string;
  operator?: string;
  customer?: string;
  item?: string;
  qty?: string;
  amount?: string;
  subtotal?: string;
  discount?: string;
  tax?: string;
  total?: string;
  payments?: string;
  change_due?: string;
  rounding?: string;
  vat_rate?: string;
  taxable?: string;
  tax_col?: string;
  thank_you?: string;
  tax_id?: string;
  tel?: string;
  cash_count_section_title?: string;
  cash_count_total_variance?: string;
  cash_count_approved_by?: string;
  cash_count_reason?: string;
  cash_count_col_tender?: string;
  cash_count_col_expected?: string;
  cash_count_col_actual?: string;
  cash_count_col_variance?: string;
}

export interface ZReceiptCashCountRow {
  code: string;
  name: string;
  expected: string;
  actual: string;
  variance: string;
  direction: 'over' | 'under' | 'balanced';
}

export interface ReceiptData {
  company: CompanyInfo;
  receipt_number: string;
  date_time: string;
  terminal_name: string;
  operator_name: string;
  lines: ReceiptLine[];
  subtotal: string;
  discount_amount: string;
  tax_amount: string;
  total: string;
  currency_symbol: string;
  vat_breakdown: VatBreakdownLine[];
  payments: PaymentLine[];
  change_due: string;
  /** Cash-sale tolerance write-off in customer-facing currency. Null when not applied. */
  tolerance_writeoff?: string | null;
  /**
   * Precomputed flag for the Rust formatter. True when tolerance_writeoff is a
   * positive amount; false otherwise. Computed on the TS boundary using
   * arbitrary-precision decimal so the Rust side never parses monetary strings.
   */
  has_tolerance?: boolean;
  fiscal_hash: string | null;
  fiscal_signature: string | null;
  customer_name: string | null;
  notes: string | null;
  labels?: ReceiptLabels;
  /** Visibility flags — all default to true when absent (backward compatible) */
  show_vat_breakdown?: boolean;
  show_fiscal_info?: boolean;
  show_payment_details?: boolean;
  show_customer?: boolean;
  /** When true the Rust formatter prints a bold centred DUPLICATA banner */
  is_reprint?: boolean;
  /** Z-report cash-count block (optional — only set when closing a shift with cash counts) */
  cash_counts?: ZReceiptCashCountRow[];
  manager_name?: string | null;
  variance_reason?: string | null;
  variance_severity?: string | null;
  aggregate_variance?: string | null;
}

export interface BuildZReceiptDataInput {
  companyName: string;
  formattedZNumber: string;
  dateTime: string;
  terminalName: string;
  operatorName: string;
  currencySymbol: string;
  wasReused: boolean;
  cashCounts?: ZReceiptCashCountRow[];
  managerName?: string | null;
  varianceReason?: string | null;
  varianceSeverity?: string | null;
  aggregateVariance?: string | null;
  labels?: ReceiptLabels;
}

export function buildZReceiptData(input: BuildZReceiptDataInput): ReceiptData {
  return {
    company: {
      name: input.companyName,
      address_line1: '',
      address_line2: null,
      city: '',
      postal_code: '',
      country: '',
      tax_id: '',
      phone: null,
    },
    receipt_number: input.formattedZNumber,
    date_time: input.dateTime,
    terminal_name: input.terminalName,
    operator_name: input.operatorName,
    lines: [],
    subtotal: '0.00',
    discount_amount: '0.00',
    tax_amount: '0.00',
    total: '0.00',
    currency_symbol: input.currencySymbol,
    vat_breakdown: [],
    payments: [],
    change_due: '0.00',
    tolerance_writeoff: null,
    has_tolerance: false,
    fiscal_hash: null,
    fiscal_signature: null,
    customer_name: null,
    notes: null,
    labels: input.labels,
    show_vat_breakdown: false,
    show_fiscal_info: false,
    show_payment_details: false,
    show_customer: false,
    is_reprint: input.wasReused,
    cash_counts: input.cashCounts,
    manager_name: input.managerName ?? null,
    variance_reason: input.varianceReason ?? null,
    variance_severity: input.varianceSeverity ?? null,
    aggregate_variance: input.aggregateVariance ?? null,
  };
}

export interface PrinterConfig {
  connection_type: PrinterConnectionType;
  address: string;
  name: string;
}

// ── Settings types passed to Rust backend ──

export interface PrintSettings {
  columns: number;
  cut_mode: 'partial' | 'full' | 'none';
  encoding: string;
  footer_text: string;
  copies: number;
}

export interface DrawerKickSettings {
  pin: number;
  pulse_on: number;
  pulse_off: number;
  beep: boolean;
}

// ── Tauri Command Wrappers ──

/**
 * Discover available USB and network printers.
 * @param subnetPrefix Optional subnet prefix for network scanning (e.g., "192.168.1.")
 */
export async function discoverPrinters(
  subnetPrefix?: string,
): Promise<PrinterInfo[]> {
  return invoke<PrinterInfo[]>('discover_printers', {
    subnetPrefix: subnetPrefix ?? null,
  });
}

/**
 * Print a receipt to the specified printer.
 */
export async function printReceipt(
  receipt: ReceiptData,
  printer: PrinterConfig,
  printSettings?: PrintSettings,
): Promise<void> {
  return invoke<void>('print_receipt', {
    receipt,
    connectionType: printer.connection_type,
    address: printer.address,
    printSettings: printSettings ?? null,
  });
}

/**
 * Print a test/alignment page to verify printer setup.
 */
export async function printTestPage(
  printer: PrinterConfig,
  columns?: number,
): Promise<void> {
  return invoke<void>('print_test_page', {
    connectionType: printer.connection_type,
    address: printer.address,
    columns: columns ?? null,
  });
}

/**
 * Open the cash drawer connected to the specified printer.
 */
export async function openCashDrawer(
  printer: PrinterConfig,
  drawerSettings?: DrawerKickSettings,
): Promise<void> {
  return invoke<void>('open_cash_drawer', {
    connectionType: printer.connection_type,
    address: printer.address,
    drawerSettings: drawerSettings ?? null,
  });
}

/** Build PrintSettings from the printer store state. */
export function getPrintSettingsFromStore(): PrintSettings {
  const { settings } = usePrinterStore.getState();
  return {
    columns: settings.paperWidth === '80mm' ? 42 : 32,
    cut_mode: settings.cutMode,
    encoding: settings.encoding,
    footer_text: settings.footerText,
    copies: settings.copies,
  };
}

/** Build DrawerKickSettings from the cash drawer store state. */
export function getDrawerSettingsFromStore(): DrawerKickSettings {
  const state = useCashDrawerStore.getState();
  return {
    pin: state.pin,
    pulse_on: state.pulseOnTime,
    pulse_off: state.pulseOffTime,
    beep: state.beepOnOpen,
  };
}

/**
 * Check if the app is running inside Tauri (native desktop).
 * Printing is only available in the Tauri environment.
 */
export function isTauriEnvironment(): boolean {
  return typeof window !== 'undefined' && '__TAURI_INTERNALS__' in window;
}

// ── PDF Receipt Printing (browser-based, works without physical printer) ──

/**
 * Fetch a receipt PDF from the backend and open the browser print dialog.
 * Works in both Tauri and regular browser environments.
 */
export async function printReceiptAsPdf(receiptId: string): Promise<void> {
  const { serverUrl, token, companyId } = useAuthStore.getState();
  if (!serverUrl || !token) {
    throw new Error('Not authenticated');
  }

  const url = `${serverUrl}/api/v1/pos/receipts/${receiptId}/pdf`;
  const headers: Record<string, string> = {
    Authorization: `Bearer ${token}`,
    Accept: 'application/pdf',
  };
  if (companyId) {
    headers['X-Company-Id'] = companyId;
  }

  let blob: Blob;

  if (isTauriEnvironment()) {
    const response = await tauriFetch(url, { method: 'GET', headers });
    if (!response.ok) {
      throw new Error(`Failed to fetch receipt PDF (${String(response.status)})`);
    }
    blob = await response.blob();
  } else {
    const response = await globalThis.fetch(url, { method: 'GET', headers });
    if (!response.ok) {
      throw new Error(`Failed to fetch receipt PDF (${String(response.status)})`);
    }
    blob = await response.blob();
  }

  const blobUrl = window.URL.createObjectURL(blob);

  // Use hidden iframe to trigger print dialog
  const iframe = document.createElement('iframe');
  iframe.style.position = 'fixed';
  iframe.style.width = '1px';
  iframe.style.height = '1px';
  iframe.style.opacity = '0';
  iframe.style.left = '-9999px';
  iframe.style.top = '0';
  iframe.style.border = 'none';
  iframe.src = blobUrl;
  document.body.appendChild(iframe);

  iframe.onload = () => {
    setTimeout(() => {
      try {
        iframe.contentWindow?.focus();
        iframe.contentWindow?.print();
      } catch {
        // Fallback: open in new tab
        window.open(blobUrl, '_blank');
      }

      // Clean up after delay
      setTimeout(() => {
        try {
          document.body.removeChild(iframe);
        } catch {
          // already removed
        }
        window.URL.revokeObjectURL(blobUrl);
      }, 60_000);
    }, 500);
  };
}
