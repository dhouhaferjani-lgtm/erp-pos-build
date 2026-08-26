import { describe, expect, it } from 'vitest';
import { getAccessLevel, hasManagerAccess, isManagerRole } from './roles';

describe('POS access hierarchy', () => {
  it('treats owner as higher access than manager', () => {
    expect(getAccessLevel(['cashier'])).toBe(0);
    expect(getAccessLevel(['manager'])).toBe(1);
    expect(getAccessLevel(['owner'])).toBe(2);
  });

  it('keeps owner manager-compatible for existing gates', () => {
    expect(isManagerRole(['owner'])).toBe(true);
  });
});

/**
 * B-13 (iv) — role composition is the PIN OPERATOR's roles ONLY.
 *
 * The device login user is a terminal-provisioning identity, not the acting
 * identity: an IziPOS terminal is signed in ONCE with a back-office account
 * (typically the owner's) and then handed to whoever is on shift, who
 * identifies with a PIN. Folding the login user's roles into the gate made
 * every PIN operator on such a terminal a manager.
 */
describe('hasManagerAccess — PIN operator roles only (B-13)', () => {
  it('grants manager surfaces to a manager PIN operator', () => {
    expect(hasManagerAccess(['manager'])).toBe(true);
    expect(hasManagerAccess(['admin'])).toBe(true);
    expect(hasManagerAccess(['owner'])).toBe(true);
  });

  it('refuses manager surfaces to a cashier PIN operator', () => {
    expect(hasManagerAccess(['cashier'])).toBe(false);
  });

  it('refuses manager surfaces to a cashier PIN operator on an OWNER-logged-in terminal', () => {
    // The regression this lane exists for: the login user's roles are not an
    // argument any more, so there is nothing to MAX against. Passing them
    // would not even typecheck.
    expect(hasManagerAccess(['cashier'])).toBe(false);
  });

  it('fails CLOSED when no PIN operator is active', () => {
    // App.tsx renders <PinEntryPage> instead of <AppShell> whenever
    // `operator` is null, so this state is not reachable through the shell —
    // but the predicate must not be the thing that would open the door if a
    // future surface mounts outside that guard. There is no "no PIN policy"
    // mode in the device app.
    expect(hasManagerAccess(undefined)).toBe(false);
    expect(hasManagerAccess([])).toBe(false);
  });

  it('ignores unknown role strings rather than treating them as elevated', () => {
    expect(hasManagerAccess(['supervisor-ish'])).toBe(false);
  });
});
