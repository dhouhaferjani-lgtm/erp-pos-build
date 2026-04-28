import type Database from '@tauri-apps/plugin-sql';
import { queryAll, execute } from '@/lib/db';
import type { FloorData, TableData } from '@/api/tableApi';

const DELETE_BATCH_SIZE = 200;

interface FloorRow {
  id: string;
  name: string;
  position: number;
  is_active: number;
  updated_at: string;
}

interface TableRow {
  id: string;
  floor_id: string | null;
  table_number: string;
  label: string | null;
  seats: number;
  status: TableData['status'];
  shape: string | null;
  position_x: string | null;
  position_y: string | null;
  width: string | null;
  height: string | null;
  current_order_id: string | null;
  updated_at: string;
}

function floorRowToData(row: FloorRow, tables: TableData[] = []): FloorData {
  return {
    id: row.id,
    name: row.name,
    position: row.position,
    is_active: row.is_active === 1,
    tables,
    created_at: row.updated_at,
    updated_at: row.updated_at,
  };
}

function tableRowToData(row: TableRow): TableData {
  return {
    id: row.id,
    floor_id: row.floor_id,
    table_number: row.table_number,
    label: row.label,
    seats: row.seats,
    status: row.status,
    shape: row.shape,
    position_x: row.position_x,
    position_y: row.position_y,
    width: row.width,
    height: row.height,
    current_order_id: row.current_order_id,
    created_at: row.updated_at,
    updated_at: row.updated_at,
  };
}

export interface FloorUpsertInput {
  id: string;
  name: string;
  position: number;
  is_active: boolean;
  updated_at: string;
}

export interface TableUpsertInput {
  id: string;
  floor_id: string | null;
  table_number: string;
  label: string | null;
  seats: number;
  status: TableData['status'];
  shape: string | null;
  position_x: string | null;
  position_y: string | null;
  width: string | null;
  height: string | null;
  current_order_id: string | null;
  updated_at: string;
}

export async function upsertFloors(db: Database, floors: FloorUpsertInput[]): Promise<void> {
  if (floors.length === 0) return;
  for (const floor of floors) {
    await execute(
      db,
      `INSERT INTO floors (id, name, position, is_active, updated_at, synced_at)
       VALUES ($1, $2, $3, $4, $5, datetime('now'))
       ON CONFLICT(id) DO UPDATE SET
         name = excluded.name, position = excluded.position,
         is_active = excluded.is_active, updated_at = excluded.updated_at,
         synced_at = datetime('now')`,
      [floor.id, floor.name, floor.position, floor.is_active ? 1 : 0, floor.updated_at],
    );
  }
}

export async function upsertTables(db: Database, tables: TableUpsertInput[]): Promise<void> {
  if (tables.length === 0) return;
  for (const t of tables) {
    await execute(
      db,
      `INSERT INTO tables (id, floor_id, table_number, label, seats, status, shape, position_x, position_y, width, height, current_order_id, updated_at, synced_at)
       VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, datetime('now'))
       ON CONFLICT(id) DO UPDATE SET
         floor_id = excluded.floor_id, table_number = excluded.table_number,
         label = excluded.label, seats = excluded.seats, status = excluded.status,
         shape = excluded.shape, position_x = excluded.position_x,
         position_y = excluded.position_y, width = excluded.width, height = excluded.height,
         current_order_id = excluded.current_order_id, updated_at = excluded.updated_at,
         synced_at = datetime('now')`,
      [t.id, t.floor_id, t.table_number, t.label, t.seats, t.status, t.shape,
       t.position_x, t.position_y, t.width, t.height, t.current_order_id, t.updated_at],
    );
  }
}

export async function getAllFloorsWithTables(db: Database): Promise<FloorData[]> {
  const floorRows = await queryAll<FloorRow>(
    db,
    `SELECT * FROM floors WHERE is_active = 1 ORDER BY position ASC`,
  );
  if (floorRows.length === 0) return [];
  const tableRows = await queryAll<TableRow>(
    db,
    `SELECT * FROM tables ORDER BY table_number ASC`,
  );

  const tablesByFloor = new Map<string, TableData[]>();
  for (const row of tableRows) {
    const key = row.floor_id ?? '__unassigned';
    const arr = tablesByFloor.get(key) ?? [];
    arr.push(tableRowToData(row));
    tablesByFloor.set(key, arr);
  }

  return floorRows.map((row) => floorRowToData(row, tablesByFloor.get(row.id) ?? []));
}

export async function deleteFloors(db: Database, ids: string[]): Promise<void> {
  if (ids.length === 0) return;
  for (let i = 0; i < ids.length; i += DELETE_BATCH_SIZE) {
    const batch = ids.slice(i, i + DELETE_BATCH_SIZE);
    const placeholders = batch.map((_, idx) => `$${idx + 1}`).join(', ');
    await execute(db, `DELETE FROM floors WHERE id IN (${placeholders})`, batch);
  }
}

export async function deleteTables(db: Database, ids: string[]): Promise<void> {
  if (ids.length === 0) return;
  for (let i = 0; i < ids.length; i += DELETE_BATCH_SIZE) {
    const batch = ids.slice(i, i + DELETE_BATCH_SIZE);
    const placeholders = batch.map((_, idx) => `$${idx + 1}`).join(', ');
    await execute(db, `DELETE FROM tables WHERE id IN (${placeholders})`, batch);
  }
}
