import { describe, it, expect, beforeEach, vi } from 'vitest';
import { useOperatorStore } from '../operatorStore';
import type { Operator } from '../operatorStore';

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
}));

import { apiGet, apiPost } from '@/lib/api';

const mockOperator: Operator = {
  id: 'op-1',
  name: 'Jane Cashier',
  email: 'jane@example.com',
  roles: ['cashier'],
  permissions: ['pos.sell'],
  can_discount: true,
  max_discount_percent: 10,
};

describe('operatorStore', () => {
  beforeEach(() => {
    useOperatorStore.setState({
      operator: null,
      isLocked: false,
      lastActivity: Date.now(),
      lockTimeoutMs: 5 * 60 * 1000,
      hasPins: null,
    });
    vi.clearAllMocks();
  });

  it('has correct initial state', () => {
    const state = useOperatorStore.getState();
    expect(state.operator).toBeNull();
    expect(state.isLocked).toBe(false);
    expect(state.hasPins).toBeNull();
  });

  it('verifyPin sets operator and unlocks', async () => {
    vi.mocked(apiPost).mockResolvedValue(mockOperator);

    await useOperatorStore.getState().verifyPin('1234');

    const state = useOperatorStore.getState();
    expect(state.operator).toEqual(mockOperator);
    expect(state.isLocked).toBe(false);
    expect(apiPost).toHaveBeenCalledWith('/pos/auth/verify-pin', { pin: '1234' });
  });

  it('verifyPin propagates API errors', async () => {
    vi.mocked(apiPost).mockRejectedValue(new Error('Invalid PIN'));

    await expect(useOperatorStore.getState().verifyPin('0000')).rejects.toThrow('Invalid PIN');
    expect(useOperatorStore.getState().operator).toBeNull();
  });

  it('setupPin sets operator and marks hasPins true', async () => {
    vi.mocked(apiPost).mockResolvedValue(mockOperator);

    await useOperatorStore.getState().setupPin('5678');

    const state = useOperatorStore.getState();
    expect(state.operator).toEqual(mockOperator);
    expect(state.hasPins).toBe(true);
    expect(state.isLocked).toBe(false);
  });

  it('checkHasPins returns and stores result', async () => {
    vi.mocked(apiGet).mockResolvedValue({ has_pins: true });

    const result = await useOperatorStore.getState().checkHasPins();

    expect(result).toBe(true);
    expect(useOperatorStore.getState().hasPins).toBe(true);
  });

  it('checkHasPins returns false when no pins', async () => {
    vi.mocked(apiGet).mockResolvedValue({ has_pins: false });

    const result = await useOperatorStore.getState().checkHasPins();

    expect(result).toBe(false);
    expect(useOperatorStore.getState().hasPins).toBe(false);
  });

  it('lock sets isLocked to true', () => {
    useOperatorStore.getState().lock();
    expect(useOperatorStore.getState().isLocked).toBe(true);
  });

  it('clearOperator removes operator and resets lock', () => {
    useOperatorStore.setState({ operator: mockOperator, isLocked: true });

    useOperatorStore.getState().clearOperator();

    const state = useOperatorStore.getState();
    expect(state.operator).toBeNull();
    expect(state.isLocked).toBe(false);
  });

  it('resetActivityTimer updates lastActivity', () => {
    const before = Date.now();
    useOperatorStore.setState({ lastActivity: 0 });

    useOperatorStore.getState().resetActivityTimer();

    expect(useOperatorStore.getState().lastActivity).toBeGreaterThanOrEqual(before);
  });
});
