/**
 * Sub-Spec B: PinEntryPage must NOT render a sign-out control in either mode.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}));

vi.mock('@/lib/api', () => ({
  getErrorMessage: (e: unknown) => (e instanceof Error ? e.message : 'unknown'),
}));

vi.mock('@/lib/storage', () => ({
  getStoredValue: vi.fn().mockResolvedValue(null),
  setStoredValue: vi.fn().mockResolvedValue(undefined),
  removeStoredValue: vi.fn().mockResolvedValue(undefined),
  StorageKeys: {
    TOKEN: 'auth_token',
    USER: 'user',
    COMPANY_ID: 'company_id',
    COMPANIES: 'companies',
    TERMINAL: 'terminal',
    PENDING_TERMINAL_ID: 'pending_terminal_id',
    LOGIN_TENANT_ID: 'login_tenant_id',
  },
}));

vi.mock('@/lib/echo', () => ({ disconnectEcho: vi.fn() }));
vi.mock('@tauri-apps/plugin-os', () => ({ platform: vi.fn(() => 'macos') }));
vi.mock('@/lib/device', () => ({ getDeviceId: vi.fn(() => 'device-test') }));

const mockVerifyPin = vi.fn();

vi.mock('@/stores/operatorStore', () => ({
  useOperatorStore: <T,>(selector: (s: unknown) => T): T => {
    return selector({ verifyPin: mockVerifyPin, clearOperator: vi.fn() });
  },
}));

vi.mock('@/components/PinPad', () => ({
  PinPad: () => null,
}));

import { PinEntryPage } from '../PinEntryPage';

describe('PinEntryPage (Sub-Spec B)', () => {
  beforeEach(() => {
    mockVerifyPin.mockReset();
  });

  it('renders no sign-out control (locked mode)', () => {
    render(<PinEntryPage isLocked />);
    expect(screen.queryByText('settings.signOutFromPin')).toBeNull();
  });

  it('renders no sign-out control (PIN entry mode)', () => {
    render(<PinEntryPage />);
    expect(screen.queryByText('settings.signOutFromPin')).toBeNull();
  });
});
