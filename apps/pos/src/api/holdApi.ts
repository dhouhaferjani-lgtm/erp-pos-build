import { apiGet, apiPost, apiDelete } from '@/lib/api';

export interface HeldOrderLine {
  product_id: string;
  product_name: string;
  quantity: number;
  unit_price: string;
  line_total: string;
}

export interface HeldOrder {
  id: string;
  label: string;
  lines: HeldOrderLine[];
  total: string;
  item_count: number;
  held_at: string;
  terminal_id: string;
}

export interface HoldOrderRequest {
  terminal_id: string;
  label: string;
  lines: Array<{
    product_id: string;
    quantity: number;
    unit_price: string;
  }>;
}

export async function fetchHeldOrders(): Promise<HeldOrder[]> {
  return apiGet<HeldOrder[]>('/pos/held-orders');
}

export async function holdOrder(data: HoldOrderRequest): Promise<HeldOrder> {
  return apiPost<HeldOrder>('/pos/held-orders', data);
}

export async function recallHeldOrder(id: string): Promise<HeldOrder> {
  return apiPost<HeldOrder>(`/pos/held-orders/${id}/recall`);
}

export async function discardHeldOrder(id: string): Promise<void> {
  return apiDelete<void>(`/pos/held-orders/${id}`);
}
