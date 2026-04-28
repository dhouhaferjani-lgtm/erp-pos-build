import { apiGet } from '@/lib/api';
import { getDatabase } from '@/lib/db';
import { getAllFloorsWithTables } from '@/lib/db/repositories/tableRepository';
import { useAuthStore } from '@/stores/authStore';

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
  const { companyId } = useAuthStore.getState();

  // Offline-first: serve the SQLite cache immediately if populated.
  if (companyId) {
    try {
      const db = await getDatabase(companyId);
      const cached = await getAllFloorsWithTables(db);
      if (cached.length > 0) {
        // Fire-and-forget a background refresh so subsequent reads see fresh data.
        apiGet<FloorData[] | { data: FloorData[] }>('/pos/floors')
          .then(async (response) => {
            const floors = Array.isArray(response) ? response : response.data;
            const { upsertFloors, upsertTables } = await import('@/lib/db/repositories/tableRepository');
            await upsertFloors(db, floors.map((f) => ({
              id: f.id, name: f.name, position: f.position,
              is_active: f.is_active, updated_at: f.updated_at,
            })));
            await upsertTables(db, floors.flatMap((f) => (f.tables ?? []).map((t) => ({
              id: t.id, floor_id: t.floor_id, table_number: t.table_number, label: t.label,
              seats: t.seats, status: t.status, shape: t.shape,
              position_x: t.position_x, position_y: t.position_y,
              width: t.width, height: t.height, current_order_id: t.current_order_id,
              updated_at: t.updated_at,
            }))));
          })
          .catch(() => { /* offline — cached data stands */ });
        return cached;
      }
    } catch {
      // SQLite read failed — fall through to network.
    }
  }

  const result = await apiGet<FloorData[] | { data: FloorData[] }>('/pos/floors');
  return Array.isArray(result) ? result : result.data;
}
