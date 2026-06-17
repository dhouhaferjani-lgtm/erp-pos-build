/**
 * Offline-first shifts Phase 6.2 — advisory banner shown when the background
 * reconcile detects the device's open shift was CLOSED on the server (a
 * web-admin recovery close). Renders only when terminalStore holds a
 * remoteShiftCloseConflict; advisory only (no auto-close action).
 */
import { describe, it, expect, beforeEach } from 'vitest';
import { render, cleanup } from '@testing-library/react';
import { RemoteShiftCloseBanner } from '../RemoteShiftCloseBanner';
import { useTerminalStore } from '@/stores/terminalStore';

describe('RemoteShiftCloseBanner', () => {
  beforeEach(() => {
    useTerminalStore.setState({ remoteShiftCloseConflict: null } as never);
    cleanup();
  });

  it('renders nothing when there is no conflict', () => {
    const { queryByTestId } = render(<RemoteShiftCloseBanner />);
    expect(queryByTestId('remote-shift-close-banner')).toBeNull();
  });

  it('renders an assertive advisory banner when a conflict is flagged', () => {
    useTerminalStore.setState({
      remoteShiftCloseConflict: { shiftId: 'shift-7', shiftNumber: 7 },
    } as never);

    const { getByTestId } = render(<RemoteShiftCloseBanner />);
    const banner = getByTestId('remote-shift-close-banner');

    expect(banner).toBeTruthy();
    expect(banner.getAttribute('role')).toBe('alert');
    expect(banner.getAttribute('aria-live')).toBe('assertive');
    // Shows the affected shift number and non-empty advisory copy.
    expect(banner.textContent ?? '').toContain('7');
    expect(banner.textContent ?? '').not.toBe('');
  });
});
