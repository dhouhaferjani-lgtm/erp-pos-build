import { create } from 'zustand';
import bcrypt from 'bcryptjs';
import { apiGet, apiPost } from '@/lib/api';
import { fetchDiscountPermissions } from '@/api/discountApi';
import { getDatabase } from '@/lib/db';
import {
  getAllOperators,
  hasOperatorPins,
  updateOperatorDiscountPermissions,
  upsertOperators,
} from '@/lib/db/repositories/operatorPinRepository';
import { enqueuePinUpdate } from '@/lib/db/repositories/queuedPinUpdateRepository';
import { useAuthStore } from '@/stores/authStore';
import { useTerminalStore } from '@/stores/terminalStore';
import type { DiscountPermissionStatus } from '@/lib/discountPermissions';

export interface Operator {
  id: string;
  name: string;
  email: string;
  roles: string[];
  permissions: string[];
  can_discount: boolean;
  can_apply_line_discounts?: boolean;
  can_apply_transaction_discounts?: boolean;
  max_discount_percent: number | null;
  discount_permissions_status?: DiscountPermissionStatus;
  discount_permissions_refresh_error?: 'transient';
}

interface OperatorState {
  operator: Operator | null;
  isLocked: boolean;
  lastActivity: number;
  hasPins: boolean | null;
}

interface OperatorActions {
  verifyPin: (pin: string) => Promise<void>;
  setupPin: (pin: string) => Promise<void>;
  checkHasPins: (opts?: { signal?: AbortSignal }) => Promise<boolean>;
  invalidateDiscountPermissions: () => void;
  refreshDiscountPermissions: () => Promise<void>;
  lock: () => void;
  clearOperator: () => void;
  resetActivityTimer: () => void;
}

type OperatorStore = OperatorState & OperatorActions;

const initialState: OperatorState = {
  operator: null,
  isLocked: false,
  lastActivity: Date.now(),
  hasPins: null,
};

async function getDb(): Promise<import('@tauri-apps/plugin-sql').default> {
  const { companyId } = useAuthStore.getState();
  return getDatabase(companyId ?? '');
}

function getApiErrorStatus(error: unknown): number | null {
  if (typeof error === 'object' && error !== null && 'status' in error) {
    const status = (error as { status?: unknown }).status;
    return typeof status === 'number' ? status : null;
  }

  return null;
}

function isTransientDiscountPermissionError(error: unknown): boolean {
  const status = getApiErrorStatus(error);
  if (status === null) {
    return true;
  }

  return status === 408 || status === 429 || status >= 500;
}

function operatorWithUnavailableDiscounts(
  operator: Operator,
  refreshError?: 'transient',
): Operator {
  return {
    ...operator,
    can_discount: false,
    can_apply_line_discounts: false,
    can_apply_transaction_discounts: false,
    max_discount_percent: null,
    discount_permissions_status: 'unavailable',
    discount_permissions_refresh_error: refreshError,
  };
}

async function resolveOnlineDiscountPermissions(operator: Operator): Promise<Operator> {
  const terminalCode = useTerminalStore.getState().terminal?.code;
  if (!terminalCode) {
    return operatorWithUnavailableDiscounts(operator);
  }

  let permissions;
  try {
    permissions = await fetchDiscountPermissions(terminalCode, operator.id);
  } catch (error) {
    console.debug('[POS] Discount permission fetch failed:', error);
    return operatorWithUnavailableDiscounts(
      operator,
      isTransientDiscountPermissionError(error) ? 'transient' : undefined,
    );
  }

  const status: Extract<DiscountPermissionStatus, 'fresh' | 'terminal_denied'> =
    permissions.terminalAllowsDiscounts ? 'fresh' : 'terminal_denied';

  const resolved: Operator = {
    ...operator,
    can_discount: permissions.userCanDiscount,
    can_apply_line_discounts: permissions.canApplyLineDiscounts,
    can_apply_transaction_discounts: permissions.canApplyTransactionDiscounts,
    max_discount_percent: permissions.maxDiscountPercent,
    discount_permissions_status: status,
  };

  try {
    const db = await getDb();
    await updateOperatorDiscountPermissions(db, operator.id, {
      can_discount: permissions.userCanDiscount,
      max_discount_percent: permissions.maxDiscountPercent,
      user_can_discount: permissions.userCanDiscount,
      user_max_discount_percent: permissions.userMaxDiscountPercent,
      can_apply_line_discounts: permissions.canApplyLineDiscounts,
      can_apply_transaction_discounts: permissions.canApplyTransactionDiscounts,
      fetched_at: new Date().toISOString(),
      terminal_code: terminalCode,
      status,
    });
  } catch (error) {
    console.debug('[POS] Failed to cache discount permissions:', error);
  }

  return resolved;
}

export const useOperatorStore = create<OperatorStore>()((set, get) => ({
  ...initialState,

  verifyPin: async (pin: string) => {
    // Offline-first: check cached bcrypt hashes in SQLite BEFORE the API.
    // The POS is offline-first, so authentication should be offline-first too.
    // If the local cache matches, accept immediately and fire the API call in
    // the background for server-side activity tracking (telemetry).
    let offlineMatch: Operator | null = null;
    try {
      const db = await getDb();
      const operators = await getAllOperators(db);
      const terminalCode = useTerminalStore.getState().terminal?.code ?? null;

      for (const op of operators) {
        if (bcrypt.compareSync(pin, op.pin_hash)) {
          const discountPermissionStatus =
            terminalCode !== null && op.discount_permissions_terminal_code === terminalCode
              ? op.discount_permissions_status
              : 'unavailable';
          offlineMatch = {
            id: op.id,
            name: op.name,
            email: op.email,
            roles: op.roles,
            permissions: op.permissions,
            can_discount: discountPermissionStatus === 'fresh' ? op.can_discount : false,
            can_apply_line_discounts: discountPermissionStatus === 'fresh'
              ? op.can_apply_line_discounts
              : false,
            can_apply_transaction_discounts: discountPermissionStatus === 'fresh'
              ? op.can_apply_transaction_discounts
              : false,
            max_discount_percent: discountPermissionStatus === 'fresh' ? op.max_discount_percent : null,
            discount_permissions_status: discountPermissionStatus,
          };
          break;
        }
      }
    } catch (dbError) {
      console.warn('[POS] Offline PIN verification layer unavailable:', dbError);
    }

    if (offlineMatch !== null) {
      set({
        operator: offlineMatch,
        isLocked: false,
        lastActivity: Date.now(),
      });
      // Fire-and-forget telemetry: tell the server we verified a PIN.
      // Any failure here is swallowed — it must not affect UX.
      void apiPost<Operator>('/pos/auth/verify-pin', { pin }).catch((err: unknown) => {
        console.debug('[POS] PIN telemetry call failed (offline-accepted):', err);
      });
      void resolveOnlineDiscountPermissions(offlineMatch)
        .then((operator) => {
          if (useOperatorStore.getState().operator?.id !== offlineMatch.id) {
            return;
          }
          if (
            offlineMatch.discount_permissions_status === 'fresh'
            && operator.discount_permissions_status === 'unavailable'
            && operator.discount_permissions_refresh_error === 'transient'
          ) {
            return;
          }
          set({
            operator,
            lastActivity: Date.now(),
          });
        })
        .catch((err: unknown) => {
          console.debug('[POS] Discount permission refresh failed (offline-accepted):', err);
        });
      return;
    }

    // Offline miss — fall through to API as the source of truth.
    try {
      const operator = await resolveOnlineDiscountPermissions(
        await apiPost<Operator>('/pos/auth/verify-pin', { pin }),
      );
      set({
        operator,
        isLocked: false,
        lastActivity: Date.now(),
      });
    } catch {
      throw new Error('Invalid PIN');
    }
  },

  setupPin: async (pin: string) => {
    const { user } = useAuthStore.getState();
    if (!user) {
      throw new Error('No authenticated user; log in before setting up a PIN.');
    }

    // Hash locally so the SQLite row matches the bcrypt verification path.
    const pinHash = bcrypt.hashSync(pin, 10);
    const db = await getDb();

    // Always write the local cache first so the PIN works offline immediately.
    const isAdmin = user.roles.includes('super_admin') || user.roles.includes('admin');
    await upsertOperators(db, [{
      id: user.id,
      name: user.name,
      email: user.email ?? '',
      pin_hash: pinHash,
      roles: user.roles,
      permissions: user.permissions,
      can_discount: isAdmin,
      max_discount_percent: isAdmin ? 100 : null,
    }]);

    // Try the backend now (fire-and-forget semantics on failure).
    try {
      const operator = await resolveOnlineDiscountPermissions(
        await apiPost<Operator>('/pos/auth/setup-pin', { pin }),
      );
      set({
        operator,
        isLocked: false,
        lastActivity: Date.now(),
        hasPins: true,
      });
      return;
    } catch {
      // Backend unreachable — enqueue for next sync cycle.
      await enqueuePinUpdate(db, { userId: user.id, pinHash });
      set({
        operator: {
          id: user.id,
          name: user.name,
          email: user.email ?? '',
          roles: user.roles,
          permissions: user.permissions,
          can_discount: false,
          can_apply_line_discounts: false,
          can_apply_transaction_discounts: false,
          max_discount_percent: null,
          discount_permissions_status: 'unavailable',
        },
        isLocked: false,
        lastActivity: Date.now(),
        hasPins: true,
      });
    }
  },

  checkHasPins: async (opts?: { signal?: AbortSignal }) => {
    try {
      const result = await apiGet<{ has_pins: boolean }>('/pos/auth/has-pins', undefined, {
        signal: opts?.signal,
      });
      if (opts?.signal?.aborted) {
        return false;
      }
      set({ hasPins: result.has_pins });
      return result.has_pins;
    } catch (error) {
      if (opts?.signal?.aborted) {
        throw error;
      }
      // Offline fallback: check local SQLite cache
      try {
        const db = await getDb();
        const hasPins = await hasOperatorPins(db);
        if (opts?.signal?.aborted) {
          return false;
        }
        set({ hasPins });
        return hasPins;
      } catch {
        return false;
      }
    }
  },

  invalidateDiscountPermissions: () => {
    const { operator } = get();
    if (operator === null) {
      return;
    }

    set({ operator: operatorWithUnavailableDiscounts(operator) });
  },

  refreshDiscountPermissions: async () => {
    const { operator } = get();
    if (operator === null) {
      return;
    }

    const operatorId = operator.id;
    const refreshed = await resolveOnlineDiscountPermissions(operator);
    if (get().operator?.id !== operatorId) {
      return;
    }

    set({ operator: refreshed });
  },

  lock: () => {
    set({ isLocked: true });
  },

  clearOperator: () => {
    set({ operator: null, isLocked: false });
  },

  resetActivityTimer: () => {
    set({ lastActivity: Date.now() });
  },
}));
