import type Database from '@tauri-apps/plugin-sql';
import { fetchCompanyConfig } from '@/api/productApi';
import { getDatabase, execute, queryOne } from '@/lib/db';
import {
  deleteHeldTransactionsByIds,
  listHeldTransactions,
  type HeldTransactionRow,
} from '@/lib/db/repositories/heldTransactionRepository';
import { setStoredValue, StorageKeys } from '@/lib/storage';
import { persistCompanyConfig, loadCachedCompanyConfig } from '@/lib/companyConfigCache';
import { hasModule, useProductStore } from '@/stores/productStore';
import type { CartItem } from '@/types/cart';

// Codex PR #118 round-6 P2 — the completion key and the held-transaction
// scan are now both per-terminal. A device that hosts more than one
// terminal record against the same company SQLite database (back-office
// + POS terminal on one machine, or a stand-by terminal that gets
// activated later) previously had two failure modes under the global key:
//   1. First terminal's migration deletes the second terminal's stale
//      C2 carts, and the second terminal never gets the banner.
//   2. First terminal's flag-set causes the second terminal's first
//      boot to skip its own dump entirely, leaving stale carts
//      recallable.
// The key suffix below scopes both the completion state and the row
// listing to a single terminal_id so each terminal completes its own
// one-shot dump independently.
const MIGRATION_KEY_PREFIX = 'c2_bare_cart_line_dump';
function migrationKey(terminalId: string): string {
  return `${MIGRATION_KEY_PREFIX}:${terminalId}`;
}
const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
const UUID_RE = new RegExp(`^${UUID}$`, 'i');
const MENU_COMPOSITE_RE = new RegExp(`^${UUID}_${UUID}$`, 'i');

export interface C2BareCartLineDumpResult {
  alreadyRan: boolean;
  deferred: boolean;
  dumpedCount: number;
  dumpedIds: string[];
  keptIds: string[];
}

interface RunC2BareCartLineDumpOptions {
  companyId?: string;
  /**
   * REQUIRED in production (the default db/list/migration helpers all key
   * off `terminalId`). Tests may inject their own helpers and omit this.
   * Codex PR #118 r6 P2 — both the migration completion key and the held-
   * transaction scan are scoped per-terminal so a device hosting more than
   * one terminal record against the same company DB completes its dump
   * independently per terminal.
   */
  terminalId?: string;
  db?: Database;
  isMenuTenant?: boolean;
  getMigrationRan?: () => Promise<boolean>;
  setMigrationRan?: () => Promise<void>;
  listHeldTransactions?: () => Promise<HeldTransactionRow[]>;
  deleteHeldTransactions?: (ids: string[]) => Promise<void>;
  setBannerPending?: (pending: boolean) => Promise<void>;
}

export async function runC2BareCartLineDump(
  options: RunC2BareCartLineDumpOptions = {},
): Promise<C2BareCartLineDumpResult> {
  const db = options.db ?? (options.companyId ? await getDatabase(options.companyId) : null);
  const hasInjectedHelpers = Boolean(
    options.getMigrationRan && options.setMigrationRan && options.listHeldTransactions,
  );
  if (!db && !hasInjectedHelpers) {
    return emptyResult({ deferred: true });
  }
  // The default helpers below require a terminalId to scope. When tests
  // inject all four override helpers, terminalId is optional.
  if (!hasInjectedHelpers && !options.terminalId) {
    return emptyResult({ deferred: true });
  }

  const key = options.terminalId ? migrationKey(options.terminalId) : '__test__';
  const getMigrationRan = options.getMigrationRan ?? (async () => getMigrationRanFromDb(db!, key));
  const setMigrationRan = options.setMigrationRan ?? (async () => setMigrationRanInDb(db!, key));
  const listHeldTransactionsFn = options.listHeldTransactions
    ?? (async () => listHeldTransactions(db!, options.terminalId!));
  const deleteHeldTransactions = options.deleteHeldTransactions
    ?? (async (ids: string[]) => deleteHeldTransactionsByIds(db!, ids));
  const setBannerPending = options.setBannerPending
    ?? (async (pending: boolean) => setStoredValue(StorageKeys.C2_MIGRATION_BANNER, pending));

  if (await getMigrationRan()) {
    return emptyResult({ alreadyRan: true });
  }

  const isMenuTenant = options.isMenuTenant ?? await resolveIsMenuTenant(options.companyId);
  if (isMenuTenant === null) {
    return emptyResult({ deferred: true });
  }

  const rows = await listHeldTransactionsFn();
  if (!isMenuTenant) {
    await setMigrationRan();
    return {
      alreadyRan: false,
      deferred: false,
      dumpedCount: 0,
      dumpedIds: [],
      keptIds: rows.map((row) => row.id),
    };
  }

  const dumpedIds: string[] = [];
  const keptIds: string[] = [];

  for (const row of rows) {
    if (heldTransactionHasPreC2BareProductLine(row)) {
      dumpedIds.push(row.id);
    } else {
      keptIds.push(row.id);
    }
  }

  if (dumpedIds.length > 0) {
    await deleteHeldTransactions(dumpedIds);
    // Codex PR #118 r7 P2 — banner persistence is best-effort and MUST
    // NOT reject the migration. If Tauri Store fails (rare: disk full,
    // permission glitch), SQLite rows have already been deleted; the
    // caller still needs the result so it can reconcile the in-memory
    // holdStore. The Zustand reactive banner store fires from the
    // caller's post-dump branch regardless of this persistence write,
    // so the cashier still sees the warning in the current session;
    // only the cross-boot durability is missed for this one tick.
    try {
      await setBannerPending(true);
    } catch (error) {
      console.warn(
        '[c2BareCartLineDump] banner persistence failed; in-memory banner still fires',
        error,
      );
    }
  }

  await setMigrationRan();

  return {
    alreadyRan: false,
    deferred: false,
    dumpedCount: dumpedIds.length,
    dumpedIds,
    keptIds,
  };
}

function emptyResult(overrides: Partial<C2BareCartLineDumpResult>): C2BareCartLineDumpResult {
  return {
    alreadyRan: false,
    deferred: false,
    dumpedCount: 0,
    dumpedIds: [],
    keptIds: [],
    ...overrides,
  };
}

async function resolveIsMenuTenant(companyId?: string): Promise<boolean | null> {
  try {
    const config = await fetchCompanyConfig();
    useProductStore.setState({ companyConfig: config });
    if (companyId) {
      await persistCompanyConfig(companyId, config);
    }
    return hasModule(config, 'Menu');
  } catch (error) {
    if (companyId) {
      const cached = await loadCachedCompanyConfig(companyId);
      if (cached !== null) {
        useProductStore.setState({ companyConfig: cached });
        return hasModule(cached, 'Menu');
      }
    }
    console.warn('[c2BareCartLineDump] deferred: company config unavailable', error);
    return null;
  }
}

async function getMigrationRanFromDb(db: Database, key: string): Promise<boolean> {
  const row = await queryOne<{ value: string }>(
    db,
    'SELECT value FROM pos_migration_state WHERE key = $1',
    [key],
  );
  return row?.value === 'true';
}

async function setMigrationRanInDb(db: Database, key: string): Promise<void> {
  await execute(
    db,
    `INSERT INTO pos_migration_state (key, value, updated_at)
     VALUES ($1, 'true', datetime('now'))
     ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at`,
    [key],
  );
}

function heldTransactionHasPreC2BareProductLine(row: HeldTransactionRow): boolean {
  let items: unknown;
  try {
    items = JSON.parse(row.items_json);
  } catch {
    return false;
  }

  if (!Array.isArray(items)) return false;
  return items.some(isPreC2BareProductLine);
}

function isPreC2BareProductLine(value: unknown): boolean {
  const item = value as Partial<CartItem>;
  const product = item.product;
  if (!product || typeof product.id !== 'string') return false;
  if (product.sellableType !== undefined && product.sellableType !== 'product') return false;
  if (MENU_COMPOSITE_RE.test(product.id)) return false;

  return UUID_RE.test(product.id);
}
