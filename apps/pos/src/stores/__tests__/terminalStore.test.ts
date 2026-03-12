import { describe, it, expect, beforeEach, vi } from 'vitest';
import { useTerminalStore } from '../terminalStore';
import type { Terminal, Shift } from '../terminalStore';

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
}));

vi.mock('@/lib/device', () => ({
  getDeviceId: vi.fn(() => 'device-abc'),
}));

vi.mock('@/lib/storage', () => ({
  getStoredValue: vi.fn().mockResolvedValue(null),
  setStoredValue: vi.fn().mockResolvedValue(undefined),
  removeStoredValue: vi.fn().mockResolvedValue(undefined),
  StorageKeys: {
    TOKEN: 'auth_token',
    SERVER_URL: 'server_url',
    USER: 'user',
    COMPANY_ID: 'company_id',
    COMPANIES: 'companies',
    TERMINAL: 'terminal',
    PENDING_TERMINAL_ID: 'pending_terminal_id',
  },
}));

import { apiGet, apiPost } from '@/lib/api';
import { removeStoredValue } from '@/lib/storage';

const mockTerminal: Terminal = {
  id: 'term-1',
  code: 'T001',
  name: 'Register 1',
  type: 'pos',
  is_active: true,
  hardware_identifier: 'device-abc',
  location: { id: 'loc-1', name: 'Main Store', code: 'LOC-001' },
};

const mockShift: Shift = {
  id: 'shift-1',
  terminal_id: 'term-1',
  shift_number: 1,
  status: 'OPEN',
  opening_cash: '100.00',
  opened_at: '2026-03-12T08:00:00.000Z',
  user: { id: 'user-1', name: 'Jane' },
};

describe('terminalStore', () => {
  beforeEach(() => {
    useTerminalStore.setState({
      terminal: null,
      pendingTerminalId: null,
      shift: null,
      isLoading: false,
    });
    vi.clearAllMocks();
  });

  it('has correct initial state', () => {
    const state = useTerminalStore.getState();
    expect(state.terminal).toBeNull();
    expect(state.pendingTerminalId).toBeNull();
    expect(state.shift).toBeNull();
    expect(state.isLoading).toBe(false);
  });

  it('claimTerminal stores terminal', async () => {
    vi.mocked(apiPost).mockResolvedValue(mockTerminal);

    await useTerminalStore.getState().claimTerminal('term-1', 'device-abc');

    const state = useTerminalStore.getState();
    expect(state.terminal).toEqual(mockTerminal);
    expect(state.isLoading).toBe(false);
  });

  it('claimTerminal propagates errors', async () => {
    vi.mocked(apiPost).mockRejectedValue(new Error('Terminal already claimed'));

    await expect(
      useTerminalStore.getState().claimTerminal('term-1', 'device-abc'),
    ).rejects.toThrow('Terminal already claimed');

    expect(useTerminalStore.getState().isLoading).toBe(false);
  });

  it('fetchAvailable returns terminal list', async () => {
    vi.mocked(apiGet).mockResolvedValue([mockTerminal]);

    const terminals = await useTerminalStore.getState().fetchAvailable();

    expect(terminals).toHaveLength(1);
    expect(terminals[0]!.code).toBe('T001');
  });

  it('requestTerminal stores pending terminal id', async () => {
    vi.mocked(apiPost).mockResolvedValue({ ...mockTerminal, is_active: false });

    const terminal = await useTerminalStore.getState().requestTerminal('loc-1', 'Register 2', 'device-abc');

    expect(terminal).toBeDefined();
    expect(useTerminalStore.getState().pendingTerminalId).toBe('term-1');
  });

  it('checkTerminalStatus activates terminal when is_active', async () => {
    vi.mocked(apiGet).mockResolvedValue(mockTerminal);

    const terminal = await useTerminalStore.getState().checkTerminalStatus('term-1');

    expect(terminal.is_active).toBe(true);
    expect(useTerminalStore.getState().terminal).toEqual(mockTerminal);
    expect(useTerminalStore.getState().pendingTerminalId).toBeNull();
  });

  it('checkTerminalStatus does not activate when not active', async () => {
    vi.mocked(apiGet).mockResolvedValue({ ...mockTerminal, is_active: false });

    await useTerminalStore.getState().checkTerminalStatus('term-1');

    expect(useTerminalStore.getState().terminal).toBeNull();
  });

  it('openShift creates a shift', async () => {
    useTerminalStore.setState({ terminal: mockTerminal });
    vi.mocked(apiPost).mockResolvedValue(mockShift);

    await useTerminalStore.getState().openShift('100.00');

    expect(useTerminalStore.getState().shift).toEqual(mockShift);
    expect(useTerminalStore.getState().isLoading).toBe(false);
  });

  it('openShift throws when no terminal configured', async () => {
    await expect(useTerminalStore.getState().openShift('100.00')).rejects.toThrow(
      'No terminal configured',
    );
  });

  it('closeShift clears shift', async () => {
    useTerminalStore.setState({ terminal: mockTerminal, shift: mockShift });
    vi.mocked(apiPost).mockResolvedValue({ ...mockShift, status: 'CLOSED' });

    await useTerminalStore.getState().closeShift('150.00');

    expect(useTerminalStore.getState().shift).toBeNull();
  });

  it('closeShift throws when no active shift', async () => {
    await expect(useTerminalStore.getState().closeShift('150.00')).rejects.toThrow(
      'No active shift',
    );
  });

  it('reset clears terminal state and storage', () => {
    useTerminalStore.setState({ terminal: mockTerminal, shift: mockShift });

    useTerminalStore.getState().reset();

    const state = useTerminalStore.getState();
    expect(state.terminal).toBeNull();
    expect(state.shift).toBeNull();
    expect(state.pendingTerminalId).toBeNull();
    expect(removeStoredValue).toHaveBeenCalledWith('terminal');
    expect(removeStoredValue).toHaveBeenCalledWith('pending_terminal_id');
  });
});
