import type Database from '@tauri-apps/plugin-sql';
import { queryAll, queryOne, execute } from '@/lib/db';
import { resolveCachedDiscountStatus } from '@/lib/discountPermissions';
import type { DiscountPermissionStatus } from '@/lib/discountPermissions';

export interface CachedOperator {
  id: string;
  tenant_id?: string;
  name: string;
  email: string;
  pin_hash: string;
  roles: string[];
  permissions: string[];
  company_ids?: string[];
  terminal_ids?: string[];
  approval_scopes?: string[];
  approval_scope_permissions_fetched_at?: string | null;
  approval_mirror_status?: 'fresh' | 'server_quarantined';
  can_discount: boolean;
  can_apply_line_discounts?: boolean;
  can_apply_transaction_discounts?: boolean;
  max_discount_percent: number | null;
  discount_permissions_fetched_at: string | null;
  discount_permissions_terminal_code: string | null;
  discount_permissions_status: DiscountPermissionStatus;
}

interface OperatorPinRow {
  id: string;
  tenant_id?: string;
  name: string;
  email: string;
  pin_hash: string;
  roles: string;
  permissions: string;
  company_ids?: string | null;
  terminal_ids?: string | null;
  approval_scopes?: string | null;
  approval_scope_permissions_fetched_at?: string | null;
  approval_mirror_status?: 'fresh' | 'server_quarantined' | null;
  can_discount: number;
  max_discount_percent: number | null;
  discount_permissions_fetched_at?: string | null;
  discount_permissions_terminal_code?: string | null;
  discount_permissions_status?: DiscountPermissionStatus | null;
  discount_permissions_user_can_discount?: number | null;
  discount_permissions_user_max_discount_percent?: number | null;
  discount_permissions_can_apply_line_discounts?: number | null;
  discount_permissions_can_apply_transaction_discounts?: number | null;
}

function rowToOperator(row: OperatorPinRow): CachedOperator {
  const cacheStatus = resolveCachedDiscountStatus(row.discount_permissions_fetched_at);
  const permissionStatus =
    cacheStatus === 'fresh' && row.discount_permissions_status === 'terminal_denied'
      ? 'terminal_denied'
      : cacheStatus;

  return {
    id: row.id,
    tenant_id: row.tenant_id ?? '',
    name: row.name,
    email: row.email,
    pin_hash: row.pin_hash,
    roles: JSON.parse(row.roles) as string[],
    permissions: JSON.parse(row.permissions) as string[],
    company_ids: JSON.parse(row.company_ids ?? '[]') as string[],
    terminal_ids: JSON.parse(row.terminal_ids ?? '[]') as string[],
    approval_scopes: JSON.parse(row.approval_scopes ?? '[]') as string[],
    approval_scope_permissions_fetched_at: row.approval_scope_permissions_fetched_at ?? null,
    approval_mirror_status: row.approval_mirror_status ?? 'fresh',
    can_discount: row.can_discount === 1,
    can_apply_line_discounts: row.discount_permissions_can_apply_line_discounts === 1,
    can_apply_transaction_discounts: row.discount_permissions_can_apply_transaction_discounts === 1,
    max_discount_percent: row.max_discount_percent,
    discount_permissions_fetched_at: row.discount_permissions_fetched_at ?? null,
    discount_permissions_terminal_code: row.discount_permissions_terminal_code ?? null,
    discount_permissions_status: permissionStatus,
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

/**
 * The roster pull. This is the ONLY writer that re-reads `roles` and
 * `permissions` from the server, so it is the only one whose `synced_at` stamp
 * would mean anything about authority.
 *
 * Gate r2 (R2-1): even so, authority freshness is NOT dated from this column.
 * `synced_at` is a row-level "last write" marker, and dating a security TTL
 * from it made the TTL resettable by writers that carry no authority — the two
 * discount-permission updaters below, one of which fires on every
 * offline-accepted PIN verify. The authority clock is
 * `sync_metadata.operators_last_sync` (`lib/auth/operatorAuthorityFreshness.ts`),
 * written by `pullOperatorPins` only after THIS function succeeds.
 */
export async function upsertOperators(
  db: Database,
  operators: Array<{
    id: string;
    tenant_id?: string;
    name: string;
    email: string;
    pin_hash: string;
    roles: string[];
    permissions: string[];
    company_ids?: string[];
    terminal_ids?: string[];
    approval_scopes?: string[];
    approval_scope_permissions_fetched_at?: string | null;
    can_discount: boolean;
    max_discount_percent: number | null;
  }>,
): Promise<void> {
  for (const op of operators) {
    await execute(
      db,
      `INSERT INTO operator_pins (id, tenant_id, name, email, pin_hash, roles, permissions, company_ids, terminal_ids, approval_scopes, approval_scope_permissions_fetched_at, approval_mirror_status, can_discount, max_discount_percent, synced_at, discount_permissions_fetched_at, discount_permissions_terminal_code, discount_permissions_status, discount_permissions_user_can_discount, discount_permissions_user_max_discount_percent, discount_permissions_can_apply_line_discounts, discount_permissions_can_apply_transaction_discounts)
       VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, 'fresh', $12, $13, datetime('now'), NULL, NULL, 'unavailable', NULL, NULL, NULL, NULL)
       ON CONFLICT(id) DO UPDATE SET
         tenant_id = excluded.tenant_id,
         name = excluded.name, email = excluded.email, pin_hash = excluded.pin_hash,
         roles = excluded.roles, permissions = excluded.permissions,
         company_ids = excluded.company_ids,
         terminal_ids = excluded.terminal_ids,
         approval_scopes = excluded.approval_scopes,
         approval_scope_permissions_fetched_at = excluded.approval_scope_permissions_fetched_at,
         approval_mirror_status = 'fresh',
         can_discount = excluded.can_discount,
         max_discount_percent = CASE
           WHEN discount_permissions_fetched_at IS NOT NULL
             AND discount_permissions_user_can_discount = excluded.can_discount
             AND (
               (discount_permissions_user_max_discount_percent IS NULL AND excluded.max_discount_percent IS NULL)
               OR discount_permissions_user_max_discount_percent = excluded.max_discount_percent
             )
           THEN operator_pins.max_discount_percent
           ELSE excluded.max_discount_percent
         END,
         discount_permissions_fetched_at = CASE
           WHEN discount_permissions_fetched_at IS NOT NULL
             AND discount_permissions_user_can_discount = excluded.can_discount
             AND (
               (discount_permissions_user_max_discount_percent IS NULL AND excluded.max_discount_percent IS NULL)
               OR discount_permissions_user_max_discount_percent = excluded.max_discount_percent
             )
           THEN operator_pins.discount_permissions_fetched_at
           ELSE NULL
         END,
         discount_permissions_terminal_code = CASE
           WHEN discount_permissions_fetched_at IS NOT NULL
             AND discount_permissions_user_can_discount = excluded.can_discount
             AND (
               (discount_permissions_user_max_discount_percent IS NULL AND excluded.max_discount_percent IS NULL)
               OR discount_permissions_user_max_discount_percent = excluded.max_discount_percent
             )
           THEN operator_pins.discount_permissions_terminal_code
           ELSE NULL
         END,
         discount_permissions_status = CASE
           WHEN discount_permissions_fetched_at IS NOT NULL
             AND discount_permissions_user_can_discount = excluded.can_discount
             AND (
               (discount_permissions_user_max_discount_percent IS NULL AND excluded.max_discount_percent IS NULL)
               OR discount_permissions_user_max_discount_percent = excluded.max_discount_percent
             )
           THEN operator_pins.discount_permissions_status
           ELSE 'unavailable'
         END,
         discount_permissions_user_can_discount = CASE
           WHEN discount_permissions_fetched_at IS NOT NULL
             AND discount_permissions_user_can_discount = excluded.can_discount
             AND (
               (discount_permissions_user_max_discount_percent IS NULL AND excluded.max_discount_percent IS NULL)
               OR discount_permissions_user_max_discount_percent = excluded.max_discount_percent
             )
           THEN operator_pins.discount_permissions_user_can_discount
           ELSE NULL
         END,
         discount_permissions_user_max_discount_percent = CASE
           WHEN discount_permissions_fetched_at IS NOT NULL
             AND discount_permissions_user_can_discount = excluded.can_discount
             AND (
               (discount_permissions_user_max_discount_percent IS NULL AND excluded.max_discount_percent IS NULL)
               OR discount_permissions_user_max_discount_percent = excluded.max_discount_percent
             )
           THEN operator_pins.discount_permissions_user_max_discount_percent
           ELSE NULL
         END,
         discount_permissions_can_apply_line_discounts = CASE
           WHEN discount_permissions_fetched_at IS NOT NULL
             AND discount_permissions_user_can_discount = excluded.can_discount
             AND (
               (discount_permissions_user_max_discount_percent IS NULL AND excluded.max_discount_percent IS NULL)
               OR discount_permissions_user_max_discount_percent = excluded.max_discount_percent
             )
           THEN operator_pins.discount_permissions_can_apply_line_discounts
           ELSE NULL
         END,
         discount_permissions_can_apply_transaction_discounts = CASE
           WHEN discount_permissions_fetched_at IS NOT NULL
             AND discount_permissions_user_can_discount = excluded.can_discount
             AND (
               (discount_permissions_user_max_discount_percent IS NULL AND excluded.max_discount_percent IS NULL)
               OR discount_permissions_user_max_discount_percent = excluded.max_discount_percent
             )
           THEN operator_pins.discount_permissions_can_apply_transaction_discounts
           ELSE NULL
         END,
         synced_at = datetime('now')`,
      [
        op.id, op.tenant_id ?? '',
        op.name, op.email, op.pin_hash,
        JSON.stringify(op.roles), JSON.stringify(op.permissions),
        JSON.stringify(op.company_ids ?? []),
        JSON.stringify(op.terminal_ids ?? []),
        JSON.stringify(op.approval_scopes ?? []),
        op.approval_scope_permissions_fetched_at ?? null,
        op.can_discount ? 1 : 0, op.max_discount_percent,
      ]
    );
  }
}

export async function updateOperatorDiscountPermissions(
  db: Database,
  operatorId: string,
  permissions: {
    can_discount: boolean;
    max_discount_percent: number | null;
    user_can_discount: boolean;
    user_max_discount_percent: number | null;
    can_apply_line_discounts: boolean;
    can_apply_transaction_discounts: boolean;
    fetched_at: string;
    terminal_code: string;
    status: Extract<DiscountPermissionStatus, 'fresh' | 'terminal_denied'>;
  },
): Promise<void> {
  await execute(
    db,
    `UPDATE operator_pins
     SET can_discount = $1,
         max_discount_percent = $2,
         discount_permissions_fetched_at = $3,
         discount_permissions_terminal_code = $4,
         discount_permissions_status = $5,
         discount_permissions_user_can_discount = $6,
         discount_permissions_user_max_discount_percent = $7,
         discount_permissions_can_apply_line_discounts = $8,
         discount_permissions_can_apply_transaction_discounts = $9
     WHERE id = $10`,
    [
      permissions.can_discount ? 1 : 0,
      permissions.max_discount_percent,
      permissions.fetched_at,
      permissions.terminal_code,
      permissions.status,
      permissions.user_can_discount ? 1 : 0,
      permissions.user_max_discount_percent,
      permissions.can_apply_line_discounts ? 1 : 0,
      permissions.can_apply_transaction_discounts ? 1 : 0,
      operatorId,
    ],
  );
}

export async function invalidateTerminalDiscountPermissions(
  db: Database,
  terminalCode: string,
): Promise<void> {
  await execute(
    db,
    `UPDATE operator_pins
     SET can_discount = COALESCE(discount_permissions_user_can_discount, can_discount),
         max_discount_percent = discount_permissions_user_max_discount_percent,
         discount_permissions_fetched_at = NULL,
         discount_permissions_terminal_code = NULL,
         discount_permissions_status = 'unavailable',
         discount_permissions_can_apply_line_discounts = NULL,
         discount_permissions_can_apply_transaction_discounts = NULL
     WHERE discount_permissions_terminal_code = $1`,
    [terminalCode],
  );
}

export async function hasOperatorPins(db: Database): Promise<boolean> {
  const result = await queryOne<{ count: number }>(db, 'SELECT COUNT(*) as count FROM operator_pins');
  return (result?.count ?? 0) > 0;
}

/**
 * FU-1 — make a confirmed-full `/pos/auth/pin-data` pull authoritative by
 * deleting any cached operator NOT present in the pull (`keepIds`).
 *
 * The device previously only ever upserted operator PINs, so a manager
 * mirrored BEFORE suspension kept a valid local PIN + cached `approval_scopes`
 * and could still approve offline overrides — the offline approval path checks
 * client-side bcrypt against this store and never reaches the server
 * `PinVerifier`. Pruning omitted operators on a full pull closes that window.
 *
 * Returns the number of rows deleted.
 *
 * Deliberately NO active-operator exception: the server (`PosAuthController::
 * pinData`) returns EVERY active company member with a `pos_pin` and does not
 * filter by terminal, so a legitimate active operator is always in `keepIds`
 * and never at risk of being pruned. An `id != activeOperatorId` carve-out
 * would only ever fire when the active operator is OMITTED — i.e. they were
 * just suspended/revoked — and would then SHIELD that suspended operator's
 * local PIN (Codex review, FU-1 HIGH). So we prune strictly by `keepIds`.
 *
 * Callers MUST only invoke this after a confirmed full, current-terminal pull
 * (status-200, non-empty response). An empty `keepIds` is treated as a
 * defensive no-op: the sole caller already gates on a non-empty pull, and
 * wiping every operator would break ALL offline approvals.
 */
export async function pruneOperatorsExcept(db: Database, keepIds: string[]): Promise<number> {
  if (keepIds.length === 0) {
    return 0;
  }

  const placeholders = keepIds.map((_, i) => `$${i + 1}`).join(', ');
  const result = await execute(
    db,
    `DELETE FROM operator_pins WHERE id NOT IN (${placeholders})`,
    keepIds,
  );
  return result.rowsAffected;
}
