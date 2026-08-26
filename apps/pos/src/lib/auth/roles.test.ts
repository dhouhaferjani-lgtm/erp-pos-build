import { describe, expect, it } from 'vitest';
import {
  MANAGER_SURFACE_PERMISSION,
  getAccessLevel,
  hasManagerAccess,
  isManagerRole,
} from './roles';

describe('POS access hierarchy (legacy role-name fallback)', () => {
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
 * B-13 (iv) — role composition is the PIN OPERATOR's authority ONLY.
 *
 * The device login user is a terminal-provisioning identity, not the acting
 * identity: an IziPOS terminal is signed in ONCE with a back-office account
 * (typically the owner's) and then handed to whoever is on shift, who
 * identifies with a PIN. Folding the login user's roles into the gate made
 * every PIN operator on such a terminal a manager.
 */
describe('hasManagerAccess — PIN operator only (B-13)', () => {
  it('grants manager surfaces on the server permission', () => {
    expect(hasManagerAccess({ permissions: ['pos.view_reports'] })).toBe(true);
  });

  it('refuses a cashier PIN operator, whose permissions omit it', () => {
    expect(hasManagerAccess({ permissions: ['pos.operate_terminal'] })).toBe(false);
  });

  it('refuses a cashier PIN operator on an OWNER-logged-in terminal', () => {
    // The regression this lane exists for: the login user's authority is not an
    // argument any more, so there is nothing to MAX against. Passing it would
    // not even typecheck.
    expect(hasManagerAccess({ roles: ['cashier'], permissions: ['pos.operate_terminal'] }))
      .toBe(false);
  });

  it('fails CLOSED when no PIN operator is active', () => {
    // App.tsx renders <PinEntryPage> instead of <AppShell> whenever `operator`
    // is null, so this state is not reachable through the shell — but the
    // predicate must not be the thing that would open the door if a future
    // surface mounts outside that guard. There is no "no PIN policy" mode.
    expect(hasManagerAccess(undefined)).toBe(false);
    expect(hasManagerAccess(null)).toBe(false);
    expect(hasManagerAccess({})).toBe(false);
  });
});

/**
 * Gate r1 (R1-4) — the gate must key on the PERMISSION the server authorizes,
 * not on a hardcoded four-name role allowlist.
 *
 * `owner` is not seeded at all; `viewer`/`technician`/`operator`/`accountant`
 * are seeded but absent from `ROLE_LEVEL`; and tenants may create arbitrary
 * role names (`RoleController.php:187-198`). A name-only gate therefore says
 * "no" to principals the server says "yes" to, with no explanation.
 */
describe('hasManagerAccess — permission-based, per surface (R1-4)', () => {
  it('names the same permissions the server authorizes', () => {
    expect(MANAGER_SURFACE_PERMISSION.reports).toBe('pos.view_reports');
    expect(MANAGER_SURFACE_PERMISSION.cash_drawer).toBe('pos.approve_cash_drawer_control');
  });

  it('admits a TENANT-CREATED role that carries the permission', () => {
    // The server would authorize this principal (ReportController.php:59);
    // before R1-4 the device redirected it to "/" with no explanation.
    expect(hasManagerAccess({ roles: ['supervisor'], permissions: ['pos.view_reports'] }))
      .toBe(true);
  });

  it('refuses a manager-NAMED role stripped of the permission', () => {
    // Permissions win over the name: a tenant that removed `pos.view_reports`
    // from its manager role means it.
    expect(hasManagerAccess({ roles: ['manager'], permissions: ['pos.operate_terminal'] }))
      .toBe(false);
  });

  it('gates the cash drawer on its own permission, not on the reports one', () => {
    const reportsOnly = { permissions: ['pos.view_reports'] };
    expect(hasManagerAccess(reportsOnly, 'reports')).toBe(true);
    expect(hasManagerAccess(reportsOnly, 'cash_drawer')).toBe(false);

    const drawerOnly = { permissions: ['pos.approve_cash_drawer_control'] };
    expect(hasManagerAccess(drawerOnly, 'cash_drawer')).toBe(true);
    expect(hasManagerAccess(drawerOnly, 'reports')).toBe(false);
  });

  it('falls back to role names ONLY for a cache row with no permissions', () => {
    // Pre-R1-4 `operator_pins` rows, and any payload that predates the
    // pin-data `permissions` field. Without this a device upgrade would lock
    // every manager out until the next roster pull.
    expect(hasManagerAccess({ roles: ['manager'] })).toBe(true);
    expect(hasManagerAccess({ roles: ['manager'], permissions: [] })).toBe(true);
    expect(hasManagerAccess({ roles: ['cashier'], permissions: [] })).toBe(false);
    expect(hasManagerAccess({ roles: ['supervisor'], permissions: [] })).toBe(false);
  });
});

/**
 * Gate r1 (R1-3) — cached operator authority must not outlive its TTL offline.
 *
 * After this lane `operator.roles`/`permissions` are the SOLE basis for every
 * manager gate in the POS, and offline PIN verification reads them from a
 * SQLite cache with no freshness check. A demoted manager on a terminal that
 * never regains connectivity would keep manager access forever. The device
 * already solves this one layer over for approval scopes
 * (`approvalVerifier.ts`, 7 days).
 */
describe('hasManagerAccess — offline authority TTL (R1-3)', () => {
  it('CLOSES the manager gate for an operator whose cached authority went stale', () => {
    expect(hasManagerAccess({ permissions: ['pos.view_reports'], authority_stale: true }))
      .toBe(false);
    expect(hasManagerAccess({ roles: ['manager'], authority_stale: true })).toBe(false);
    expect(
      hasManagerAccess(
        { permissions: ['pos.approve_cash_drawer_control'], authority_stale: true },
        'cash_drawer',
      ),
    ).toBe(false);
  });

  it('leaves a fresh or live operator alone', () => {
    expect(hasManagerAccess({ permissions: ['pos.view_reports'], authority_stale: false }))
      .toBe(true);
    // Absent flag = the live online verify path, which has no cache to age.
    expect(hasManagerAccess({ permissions: ['pos.view_reports'] })).toBe(true);
  });
});
