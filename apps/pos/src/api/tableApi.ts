import { apiGet } from '@/lib/api';

export interface FloorData {
  id: string;
  name: string;
  position: number;
  is_active: boolean;
  tables?: TableData[];
  created_at: string;
  updated_at: string;
}

export interface TableData {
  id: string;
  floor_id: string | null;
  table_number: string;
  label: string | null;
  seats: number;
  status: 'available' | 'occupied' | 'reserved' | 'cleaning';
  shape: string | null;
  position_x: string | null;
  position_y: string | null;
  width: string | null;
  height: string | null;
  current_order_id: string | null;
  floor?: FloorData;
  created_at: string;
  updated_at: string;
}

export async function getFloors(): Promise<FloorData[]> {
  return apiGet<FloorData[]>('/pos/floors');
}
