import { invoke } from '@tauri-apps/api/core';
import type { MonitorInfo } from '@/stores/customerDisplayStore';

/** Simplified cart item for the customer display. */
export interface CartDisplayItem {
  name: string;
  quantity: number;
  quantity_decimals?: number | null;
  line_total: string;
}

/** Payload types matching the Rust CustomerDisplayPayload enum. */
export type CustomerDisplayPayload =
  | { type: 'idle'; image_url: string }
  | { type: 'cart'; items: CartDisplayItem[]; total: string; currency: string }
  | { type: 'thank_you'; receipt_number: string; total: string; currency: string };

/** List all available monitors. */
export async function listMonitors(): Promise<MonitorInfo[]> {
  return invoke<MonitorInfo[]>('list_monitors');
}

/** Open the customer display window on the specified monitor. */
export async function openCustomerDisplay(
  monitorIndex?: number,
): Promise<void> {
  return invoke<void>('open_customer_display', {
    monitorIndex: monitorIndex ?? null,
  });
}

/** Close the customer display window. */
export async function closeCustomerDisplay(): Promise<void> {
  return invoke<void>('close_customer_display');
}

/** Send a payload to the customer display window. */
async function sendPayload(payload: CustomerDisplayPayload): Promise<void> {
  return invoke<void>('send_to_customer_display', { payload });
}

/** Send the idle screen with a logo/promo image. */
export async function sendIdleScreen(imageUrl: string): Promise<void> {
  return sendPayload({ type: 'idle', image_url: imageUrl });
}

/** Send a cart update to the customer display. */
export async function sendCartUpdate(
  items: CartDisplayItem[],
  total: string,
  currency: string,
): Promise<void> {
  return sendPayload({ type: 'cart', items, total, currency });
}

/** Send a thank-you screen after checkout. */
export async function sendThankYou(
  receiptNumber: string,
  total: string,
  currency: string,
): Promise<void> {
  return sendPayload({ type: 'thank_you', receipt_number: receiptNumber, total, currency });
}
