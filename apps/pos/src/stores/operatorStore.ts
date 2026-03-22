import { create } from 'zustand';
import { apiGet, apiPost } from '@/lib/api';

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

export const useOperatorStore = create<OperatorStore>()((set) => ({
  ...initialState,

  verifyPin: async (pin: string) => {
    const operator = await apiPost<Operator>('/pos/auth/verify-pin', { pin });
    set({
      operator,
      isLocked: false,
      lastActivity: Date.now(),
    });
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
    const result = await apiGet<{ has_pins: boolean }>('/pos/auth/has-pins');
    set({ hasPins: result.has_pins });
    return result.has_pins;
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
