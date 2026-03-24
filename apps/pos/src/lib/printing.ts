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
  vat_rate?: string;
  taxable?: string;
  tax_col?: string;
  thank_you?: string;
  tax_id?: string;
  tel?: string;
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
  fiscal_hash: string | null;
  fiscal_signature: string | null;
  customer_name: string | null;
  notes: string | null;
  labels?: ReceiptLabels;
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
