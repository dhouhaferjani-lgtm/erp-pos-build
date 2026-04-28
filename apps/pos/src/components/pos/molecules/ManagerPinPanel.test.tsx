import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { ManagerPinPanel } from './ManagerPinPanel';

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({
    t: (k: string, o?: { defaultValue?: string; [key: string]: unknown }) =>
      o?.defaultValue ?? k,
  }),
}));

const managers = [
  { id: 'mgr-1', name: 'Alice Manager' },
  { id: 'mgr-2', name: 'Bob Manager' },
];

describe('ManagerPinPanel', () => {
  it('happy path: valid PIN → onSuccess called with manager id and name', async () => {
    const onVerify = vi.fn().mockResolvedValue({ valid: true });
    const onSuccess = vi.fn();
    const onThrottleUpdate = vi.fn();

    render(
      <ManagerPinPanel
        authorizedManagers={managers}
        excludeUserId="cashier-1"
        onVerify={onVerify}
        onSuccess={onSuccess}
        throttle={{ until: null, failedAttempts: 0 }}
        onThrottleUpdate={onThrottleUpdate}
      />,
    );

    fireEvent.click(screen.getByTestId('numpad-digit-1'));
    fireEvent.click(screen.getByTestId('numpad-digit-2'));
    fireEvent.click(screen.getByTestId('numpad-digit-3'));
    fireEvent.click(screen.getByTestId('numpad-digit-4'));

    fireEvent.click(screen.getByTestId('manager-pin-verify'));

    await waitFor(() => {
      expect(onSuccess).toHaveBeenCalledWith('mgr-1', 'Alice Manager');
    });
    expect(onThrottleUpdate).toHaveBeenCalledWith({ until: null, failedAttempts: 0 });
  });

  it('3 failed attempts → throttle activates (until set, failedAttempts reset to 0)', async () => {
    const onVerify = vi.fn().mockResolvedValue({ valid: false });
    const onThrottleUpdate = vi.fn();

    const { rerender } = render(
      <ManagerPinPanel
        authorizedManagers={managers}
        excludeUserId="cashier-1"
        onVerify={onVerify}
        onSuccess={vi.fn()}
        throttle={{ until: null, failedAttempts: 0 }}
        onThrottleUpdate={onThrottleUpdate}
      />,
    );

    // Attempt 1 → failedAttempts goes to 1
    for (const d of ['1', '2', '3', '4'])
      fireEvent.click(screen.getByTestId(`numpad-digit-${d}`));
    fireEvent.click(screen.getByTestId('manager-pin-verify'));
    await waitFor(() =>
      expect(onThrottleUpdate).toHaveBeenCalledWith({ until: null, failedAttempts: 1 }),
    );

    rerender(
      <ManagerPinPanel
        authorizedManagers={managers}
        excludeUserId="cashier-1"
        onVerify={onVerify}
        onSuccess={vi.fn()}
        throttle={{ until: null, failedAttempts: 1 }}
        onThrottleUpdate={onThrottleUpdate}
      />,
    );

    // Attempt 2 → failedAttempts goes to 2
    for (const d of ['1', '2', '3', '4'])
      fireEvent.click(screen.getByTestId(`numpad-digit-${d}`));
    fireEvent.click(screen.getByTestId('manager-pin-verify'));
    await waitFor(() =>
      expect(onThrottleUpdate).toHaveBeenCalledWith({ until: null, failedAttempts: 2 }),
    );

    rerender(
      <ManagerPinPanel
        authorizedManagers={managers}
        excludeUserId="cashier-1"
        onVerify={onVerify}
        onSuccess={vi.fn()}
        throttle={{ until: null, failedAttempts: 2 }}
        onThrottleUpdate={onThrottleUpdate}
      />,
    );

    // Attempt 3 → throttle fires (until !== null, failedAttempts reset to 0)
    for (const d of ['1', '2', '3', '4'])
      fireEvent.click(screen.getByTestId(`numpad-digit-${d}`));
    fireEvent.click(screen.getByTestId('manager-pin-verify'));
    await waitFor(() => {
      const calls = onThrottleUpdate.mock.calls;
      const lastCall = calls[calls.length - 1];
      expect(lastCall).toBeDefined();
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion
      const arg = lastCall![0] as { until: string | null; failedAttempts: number };
      expect(arg.until).not.toBeNull();
      expect(arg.failedAttempts).toBe(0);
    });
  });

  it('shows countdown when throttled', () => {
    const futureUntil = new Date(Date.now() + 25_000).toISOString();
    render(
      <ManagerPinPanel
        authorizedManagers={managers}
        excludeUserId="cashier-1"
        onVerify={vi.fn()}
        onSuccess={vi.fn()}
        throttle={{ until: futureUntil, failedAttempts: 0 }}
        onThrottleUpdate={vi.fn()}
      />,
    );
    expect(screen.getByTestId('manager-pin-countdown')).toBeInTheDocument();
  });

  it('excludes the current cashier from manager dropdown', () => {
    render(
      <ManagerPinPanel
        authorizedManagers={[...managers, { id: 'cashier-1', name: 'Cashier Alice' }]}
        excludeUserId="cashier-1"
        onVerify={vi.fn()}
        onSuccess={vi.fn()}
        throttle={{ until: null, failedAttempts: 0 }}
        onThrottleUpdate={vi.fn()}
      />,
    );
    expect(screen.queryByText('Cashier Alice')).not.toBeInTheDocument();
    expect(screen.getByText('Alice Manager')).toBeInTheDocument();
    expect(screen.getByText('Bob Manager')).toBeInTheDocument();
  });

  it('verify button disabled when PIN < 4 digits, enabled at 4', () => {
    render(
      <ManagerPinPanel
        authorizedManagers={managers}
        excludeUserId="cashier-1"
        onVerify={vi.fn()}
        onSuccess={vi.fn()}
        throttle={{ until: null, failedAttempts: 0 }}
        onThrottleUpdate={vi.fn()}
      />,
    );
    expect(screen.getByTestId('manager-pin-verify')).toBeDisabled();

    fireEvent.click(screen.getByTestId('numpad-digit-1'));
    fireEvent.click(screen.getByTestId('numpad-digit-2'));
    fireEvent.click(screen.getByTestId('numpad-digit-3'));
    expect(screen.getByTestId('manager-pin-verify')).toBeDisabled();

    fireEvent.click(screen.getByTestId('numpad-digit-4'));
    expect(screen.getByTestId('manager-pin-verify')).not.toBeDisabled();
  });

  it('dot button disabled (digit-only via JPY scale 0)', () => {
    render(
      <ManagerPinPanel
        authorizedManagers={managers}
        excludeUserId="cashier-1"
        onVerify={vi.fn()}
        onSuccess={vi.fn()}
        throttle={{ until: null, failedAttempts: 0 }}
        onThrottleUpdate={vi.fn()}
      />,
    );
    expect(screen.getByTestId('numpad-dot')).toBeDisabled();
  });
});
