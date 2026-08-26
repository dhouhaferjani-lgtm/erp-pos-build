/**
 * Campaign lane N-12, gate r1 finding 2 / fiscal E — the terminal-claim refusal
 * `LOCATION_HAS_NO_CASH_REGISTER` must reach the operator translated, and must
 * name a recovery that exists.
 *
 * Before this, nothing in `apps/pos` or `apps/web` referenced the code: the
 * device rethrew and `getErrorMessage()` rendered the server's raw English
 * string, which told the operator to "add a cash repository for the location in
 * Treasury settings" — a screen with no create form that never sends
 * `location_id`. The operator had no way out of a stopped POS.
 *
 * Gate r2 minor — this file used to assert on `readFileSync` of the page's
 * source, which pins a string rather than the behaviour (renaming the local
 * `err` broke the test while the behaviour was fine). It renders the page and
 * clicks Claim now.
 */
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { ApiRequestError } from '@/lib/api';
import en from '@/locales/en/pos.json';
import fr from '@/locales/fr/pos.json';

const claimTerminal = vi.fn();
const fetchAvailable = vi.fn();

vi.mock('@/lib/device', () => ({ getDeviceId: () => 'device-under-test' }));

vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: Object.assign(
    (selector?: (s: unknown) => unknown) => {
      const state = { pendingTerminalId: null, fetchAvailable, claimTerminal, isLoading: false };
      return selector ? selector(state) : state;
    },
    { getState: () => ({ pendingTerminalId: null, fetchAvailable, claimTerminal, isLoading: false }) },
  ),
}));

vi.mock('@/hooks/useTerminalActivation', () => ({
  useTerminalActivation: () => ({ checkTerminalStatus: vi.fn() }),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: Object.assign(
    (selector?: (s: unknown) => unknown) => {
      const state = { logout: vi.fn(), user: null, serverUrl: 'http://localhost' };
      return selector ? selector(state) : state;
    },
    { getState: () => ({ logout: vi.fn(), user: null, serverUrl: 'http://localhost' }) },
  ),
}));

const TERMINAL = {
  id: 'terminal-1',
  code: 'POS02',
  name: 'Boutique Ariana',
  location: { id: 'loc-1', name: 'Boutique Ariana' },
};

/** [locale, message] — indexed directly so TS strict never sees an index signature. */
const LOCALES: Array<[string, string]> = [
  ['en', en.terminal.locationHasNoCashRegister],
  ['fr', fr.terminal.locationHasNoCashRegister],
];

async function renderAndClaim(rejection: unknown): Promise<void> {
  const { TerminalSetupPage } = await import('@/pages/TerminalSetupPage');
  fetchAvailable.mockResolvedValue([TERMINAL]);
  claimTerminal.mockRejectedValue(rejection);

  render(<TerminalSetupPage />);

  const button = await screen.findByRole('button', { name: en.terminal.claim });
  // The rejection resolves a promise inside the handler, so the state update it
  // causes lands outside React's own batching without this.
  await act(async () => {
    fireEvent.click(button);
  });
}

describe('N-12 — LOCATION_HAS_NO_CASH_REGISTER is a translated, actionable refusal', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it.each(LOCALES)('every POS locale carries the key (%s)', (_locale, message) => {
    expect(typeof message).toBe('string');
    expect(message.length).toBeGreaterThan(20);
  });

  it('names the recovery that exists, not the Treasury screen that does not', () => {
    // The only in-product path that provisions a location's drawer is saving the
    // location with POS enabled (LocationController's provisioning hook).
    expect(en.terminal.locationHasNoCashRegister).toMatch(/POS enabled/i);
    expect(fr.terminal.locationHasNoCashRegister).toMatch(/point de vente activé/i);
  });

  it('renders the translated message when the claim is refused for a drawer-less location', async () => {
    await renderAndClaim(
      new ApiRequestError(
        422,
        'This location has no cash register. Open Settings → Locations, and save this location with POS enabled — that creates its cash register. Until then its cash would be booked against another location.',
        'LOCATION_HAS_NO_CASH_REGISTER',
      ),
    );

    await waitFor(() => {
      expect(screen.getByText(en.terminal.locationHasNoCashRegister)).toBeTruthy();
    });
  });

  it('still surfaces the server message for every other refusal', async () => {
    await renderAndClaim(new ApiRequestError(409, 'This terminal is already claimed.', 'TERMINAL_ALREADY_CLAIMED'));

    await waitFor(() => {
      expect(screen.getByText('This terminal is already claimed.')).toBeTruthy();
    });
    expect(screen.queryByText(en.terminal.locationHasNoCashRegister)).toBeNull();
  });
});
