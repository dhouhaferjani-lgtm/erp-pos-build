import type Database from '@tauri-apps/plugin-sql';
import { queryAll, queryOne, execute } from '@/lib/db';

export interface CachedOperator {
  id: string;
  name: string;
  email: string;
  pin_hash: string;
  roles: string[];
  permissions: string[];
  can_discount: boolean;
  max_discount_percent: number | null;
}

interface OperatorPinRow {
  id: string;
  name: string;
  email: string;
  pin_hash: string;
  roles: string;
  permissions: string;
  can_discount: number;
  max_discount_percent: number | null;
}

function rowToOperator(row: OperatorPinRow): CachedOperator {
  return {
    id: row.id,
    name: row.name,
    email: row.email,
    pin_hash: row.pin_hash,
    roles: JSON.parse(row.roles) as string[],
    permissions: JSON.parse(row.permissions) as string[],
    can_discount: row.can_discount === 1,
    max_discount_percent: row.max_discount_percent,
  };
}

export async function getAllOperators(db: Database): Promise<CachedOperator[]> {
  const rows = await queryAll<OperatorPinRow>(db, 'SELECT * FROM operator_pins ORDER BY name');
  return rows.map(rowToOperator);
}

export async function getOperatorById(db: Database, id: string): Promise<CachedOperator | null> {
  const row = await queryOne<OperatorPinRow>(
    db,
    'SELECT * FROM operator_pins WHERE id = $1',
    [id]
  );
  return row ? rowToOperator(row) : null;
}

export async function upsertOperators(
  db: Database,
  operators: Array<{
    id: string;
    name: string;
    email: string;
    pin_hash: string;
    roles: string[];
    permissions: string[];
    can_discount: boolean;
    max_discount_percent: number | null;
  }>,
): Promise<void> {
  for (const op of operators) {
    await execute(
      db,
      `INSERT INTO operator_pins (id, name, email, pin_hash, roles, permissions, can_discount, max_discount_percent, synced_at)
       VALUES ($1, $2, $3, $4, $5, $6, $7, $8, datetime('now'))
       ON CONFLICT(id) DO UPDATE SET
         name = excluded.name, email = excluded.email, pin_hash = excluded.pin_hash,
         roles = excluded.roles, permissions = excluded.permissions,
         can_discount = excluded.can_discount, max_discount_percent = excluded.max_discount_percent,
         synced_at = datetime('now')`,
      [
        op.id, op.name, op.email, op.pin_hash,
        JSON.stringify(op.roles), JSON.stringify(op.permissions),
        op.can_discount ? 1 : 0, op.max_discount_percent,
      ]
    );
  }
}

export async function hasOperatorPins(db: Database): Promise<boolean> {
  const result = await queryOne<{ count: number }>(db, 'SELECT COUNT(*) as count FROM operator_pins');
  return (result?.count ?? 0) > 0;
}
