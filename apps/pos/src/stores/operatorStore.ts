import { create } from 'zustand';
import bcrypt from 'bcryptjs';
import { apiGet, apiPost } from '@/lib/api';
import { getDatabase } from '@/lib/db';
import { getAllOperators, hasOperatorPins } from '@/lib/db/repositories/operatorPinRepository';
import { useAuthStore } from '@/stores/authStore';

export interface Operator {
  id: string;
  name: string;
  email: string;
  roles: string[];
  permissions: string[];
  can_discount: boolean;
  max_discount_percent: number | null;
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
  checkHasPins: () => Promise<boolean>;
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

export const useOperatorStore = create<OperatorStore>()((set) => ({
  ...initialState,

  verifyPin: async (pin: string) => {
    try {
      const operator = await apiPost<Operator>('/pos/auth/verify-pin', { pin });
      set({
        operator,
        isLocked: false,
        lastActivity: Date.now(),
      });
    } catch {
      // Offline fallback: verify against cached bcrypt hashes in SQLite
      try {
        const db = await getDb();
        const operators = await getAllOperators(db);

        for (const op of operators) {
          if (bcrypt.compareSync(pin, op.pin_hash)) {
            set({
              operator: {
                id: op.id,
                name: op.name,
                email: op.email,
                roles: op.roles,
                permissions: op.permissions,
                can_discount: op.can_discount,
                max_discount_percent: op.max_discount_percent,
              },
              isLocked: false,
              lastActivity: Date.now(),
            });
            return;
          }
        }
      } catch (dbError) {
        console.error('[POS] Offline PIN verification failed:', dbError);
      }

      throw new Error('Invalid PIN');
    }
  },

  setupPin: async (pin: string) => {
    const operator = await apiPost<Operator>('/pos/auth/setup-pin', { pin });
    set({
      operator,
      isLocked: false,
      lastActivity: Date.now(),
      hasPins: true,
    });
  },

  checkHasPins: async () => {
    try {
      const result = await apiGet<{ has_pins: boolean }>('/pos/auth/has-pins');
      set({ hasPins: result.has_pins });
      return result.has_pins;
    } catch {
      // Offline fallback: check local SQLite cache
      try {
        const db = await getDb();
        const hasPins = await hasOperatorPins(db);
        set({ hasPins });
        return hasPins;
      } catch {
        return false;
      }
    }
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
