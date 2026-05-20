/**
 * Task 32 — `OffDeviceDurabilityService` coverage (spec v7 §12).
 *
 * Spec §12: "Device authority is not survivable without off-device conservation;
 * an on-device backup encrypted with an on-device key is not a conservation
 * control." Phase 1 must deliver:
 *   - At least one off-device durability path (encrypted-removable-archive /
 *     lan-peer / nas / cloud-sync) with key custody OUTSIDE the terminal disk.
 *   - The on-device AES-GCM copy as crash-recovery only.
 *   - An operator-visible unsynced-risk indicator.
 *   - A forced archive/export threshold.
 *   - A maximum-unsynced escalation.
 *   - A device-loss incident register (PHP side — covered in
 *     `DeviceLossIncidentTest`).
 *
 * The tests below run against the in-process `SqliteTestAdapter` (the same
 * shim Task 15+ uses) so we exercise the real device `fiscal_events` schema
 * — `sync_status` CHECK constraint, immutability trigger, partial sync
 * index, and all — rather than a mock.
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { migrations } from '@/lib/db/migrations';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';

import {
  EmptyOffDeviceDurabilityConfigError,
  OnDeviceKeyCustodyForbiddenError,
  OffDeviceDurabilityService,
  type OffDeviceDurabilityConfig,
  type OffDeviceDurabilityPath,
} from '../OffDeviceDurabilityService';

const nodeSqliteAvailable = (() => {
  try {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    return Boolean(require('node:sqlite').DatabaseSync);
  } catch {
    return false;
  }
})();

const d = nodeSqliteAvailable ? describe : describe.skip;

const TENANT_ID = 'tenant-1';
const COMPANY_ID = 'company-1';
const TERMINAL_ID = 'terminal-1';
const OPERATOR_ID = 'operator-1';

async function runMigrationsUpTo(adapter: SqliteTestAdapter, maxVersion: number): Promise<void> {
  for (const migration of migrations) {
    if (migration.version > maxVersion) continue;
    if (migration.run) {
      await migration.run(adapter);
    } else if (migration.sql) {
      await adapter.execute(migration.sql);
    }
  }
}

function lanPeerPath(): OffDeviceDurabilityPath {
  return {
    kind: 'lan-peer',
    peerUrl: 'http://10.0.0.5:7443',
    keyCustody: 'shared-secret-rotated',
  };
}

function encryptedRemovableArchivePath(): OffDeviceDurabilityPath {
  return {
    kind: 'encrypted-removable-archive',
    mountPoint: '/mnt/usb-fiscal-archive',
    keyCustody: 'external-token',
  };
}

function defaultConfig(
  overrides: Partial<OffDeviceDurabilityConfig> = {},
): Omit<OffDeviceDurabilityConfig, 'sqlSurface'> {
  return {
    paths: [lanPeerPath()],
    forcedArchiveUnsyncedThreshold: 50,
    escalatedUnsyncedThreshold: 500,
    unsyncedAgeWarnThresholdSeconds: 3600,
    ...overrides,
  };
}

/**
 * Insert a single fiscal_events row with explicit `sync_status` + `created_at`.
 * Sequence numbers are mono-increasing per terminal so the chain UNIQUE
 * (tenant, terminal, sequence) is respected even when seeding 500 rows.
 */
async function seedEvent(
  adapter: SqliteTestAdapter,
  opts: {
    sequenceNumber: number;
    syncStatus?: 'pending' | 'syncing' | 'synced' | 'failed';
    createdAt?: string;
    terminalId?: string;
  },
): Promise<void> {
  const id = `evt-${opts.sequenceNumber}-${opts.terminalId ?? TERMINAL_ID}`;
  const status = opts.syncStatus ?? 'pending';
  const createdAt = opts.createdAt ?? new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');

  await adapter.execute(
    `INSERT INTO fiscal_events (
       id, tenant_id, company_id, terminal_id, operator_id,
       event_type, event_version, signature_version,
       sequence_number, event_time_device, business_date,
       reference_event_id, reference_document_id,
       source_event_class, source_event_id,
       canonical_bytes, previous_hash, current_hash,
       signature_status, sync_status, created_at
     ) VALUES (
       $1, $2, $3, $4, $5,
       'SALE_RECEIPT', 1, 'hash-chain-integrity-v1',
       $6, $7, '2026-05-20',
       NULL, NULL, NULL, NULL,
       'canonical-bytes', $8, $9,
       'not_required', $10, $11
     )`,
    [
      id,
      TENANT_ID,
      COMPANY_ID,
      opts.terminalId ?? TERMINAL_ID,
      OPERATOR_ID,
      opts.sequenceNumber,
      '2026-05-20T10:00:00Z',
      opts.sequenceNumber === 1 ? 'a'.repeat(64) : `${String(opts.sequenceNumber - 1).padStart(64, '0')}`,
      `${String(opts.sequenceNumber).padStart(64, '0')}`,
      status,
      createdAt,
    ],
  );
}

async function seedUnsyncedEvents(
  adapter: SqliteTestAdapter,
  count: number,
  startSequence: number = 1,
): Promise<void> {
  for (let i = 0; i < count; i++) {
    await seedEvent(adapter, {
      sequenceNumber: startSequence + i,
      syncStatus: 'pending',
    });
  }
}

d('OffDeviceDurabilityService — §12 contract', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await runMigrationsUpTo(adapter, 37);
  });

  afterEach(() => {
    adapter.close();
  });

  // -------------------------------------------------------------------
  // Configuration contract — paths + key custody
  // -------------------------------------------------------------------

  it('exposes at least one off-device durability path with external key custody', () => {
    const svc = new OffDeviceDurabilityService({
      ...defaultConfig({ paths: [encryptedRemovableArchivePath()] }),
      sqlSurface: adapter,
    });
    expect(svc.availablePaths()).toHaveLength(1);
    expect(svc.availablePaths()[0]?.kind).toBe('encrypted-removable-archive');
    expect(svc.keyCustody()).toBe('external');
  });

  it('accepts every spec §12 path kind (encrypted-removable-archive / lan-peer / nas / cloud-sync)', () => {
    const allPaths: OffDeviceDurabilityPath[] = [
      { kind: 'encrypted-removable-archive', mountPoint: '/mnt/usb', keyCustody: 'tpm-bound' },
      { kind: 'lan-peer', peerUrl: 'http://10.0.0.5:7443', keyCustody: 'shared-secret-rotated' },
      { kind: 'nas', nasUrl: 'smb://nas.local/fiscal', keyCustody: 'kerberos' },
      { kind: 'cloud-sync', provider: 'aws-s3', keyCustody: 'cloud-kms' },
    ];
    const svc = new OffDeviceDurabilityService({
      ...defaultConfig({ paths: allPaths }),
      sqlSurface: adapter,
    });
    expect(svc.availablePaths()).toHaveLength(4);
    expect(svc.keyCustody()).toBe('external');
  });

  it('throws EmptyOffDeviceDurabilityConfigError when paths is empty (§12 on-device-only is forbidden)', () => {
    expect(
      () =>
        new OffDeviceDurabilityService({
          ...defaultConfig({ paths: [] }),
          sqlSurface: adapter,
        }),
    ).toThrow(EmptyOffDeviceDurabilityConfigError);
  });

  it('throws OnDeviceKeyCustodyForbiddenError if any path declares on-device custody', () => {
    // The type system already prevents this at compile time, but the runtime
    // guard is defense-in-depth for callers that build the config from JSON
    // / DB rows — i.e. where the type narrowing has been erased.
    const badConfig = {
      ...defaultConfig({
        paths: [
          {
            kind: 'encrypted-removable-archive',
            mountPoint: '/mnt/usb',
            // Cast through unknown — simulates a config loaded from JSON where the
            // type guard hasn't been applied. The runtime guard MUST catch this.
            keyCustody: 'on-device',
          } as unknown as OffDeviceDurabilityPath,
        ],
      }),
      sqlSurface: adapter,
    };

    expect(() => new OffDeviceDurabilityService(badConfig)).toThrow(
      OnDeviceKeyCustodyForbiddenError,
    );
  });

  // -------------------------------------------------------------------
  // unsyncedRisk() — count-based + age-based escalation
  // -------------------------------------------------------------------

  it("reports 'normal' risk when no unsynced events exist", async () => {
    const svc = new OffDeviceDurabilityService({
      ...defaultConfig(),
      sqlSurface: adapter,
    });
    expect(await svc.unsyncedRisk()).toBe('normal');
  });

  it("reports 'normal' risk when only synced events exist", async () => {
    const svc = new OffDeviceDurabilityService({
      ...defaultConfig(),
      sqlSurface: adapter,
    });
    await seedEvent(adapter, { sequenceNumber: 1, syncStatus: 'synced' });
    await seedEvent(adapter, { sequenceNumber: 2, syncStatus: 'synced' });
    expect(await svc.unsyncedRisk()).toBe('normal');
  });

  it("escalates to 'elevated' at the forced-archive threshold (default 50)", async () => {
    const svc = new OffDeviceDurabilityService({
      ...defaultConfig({ forcedArchiveUnsyncedThreshold: 50, escalatedUnsyncedThreshold: 500 }),
      sqlSurface: adapter,
    });
    await seedUnsyncedEvents(adapter, 50);
    expect(await svc.unsyncedRisk()).toBe('elevated');
    expect(await svc.shouldForceArchive()).toBe(true);
  });

  it("escalates to 'escalated' at the maximum-unsynced threshold (default 500)", async () => {
    const svc = new OffDeviceDurabilityService({
      ...defaultConfig({ forcedArchiveUnsyncedThreshold: 50, escalatedUnsyncedThreshold: 500 }),
      sqlSurface: adapter,
    });
    await seedUnsyncedEvents(adapter, 500);
    expect(await svc.unsyncedRisk()).toBe('escalated');
    expect(await svc.shouldForceArchive()).toBe(true);
  });

  it("escalates to 'elevated' on aged unsynced events even below the count threshold", async () => {
    const svc = new OffDeviceDurabilityService({
      ...defaultConfig({ unsyncedAgeWarnThresholdSeconds: 3600 }),
      sqlSurface: adapter,
    });
    // Two hours old — above the 1-hour warn threshold.
    const twoHoursAgo = new Date(Date.now() - 2 * 3600 * 1000)
      .toISOString()
      .replace(/\.\d{3}Z$/, 'Z');
    await seedEvent(adapter, {
      sequenceNumber: 1,
      syncStatus: 'pending',
      createdAt: twoHoursAgo,
    });
    expect(await svc.unsyncedRisk()).toBe('elevated');
    expect(await svc.shouldForceArchive()).toBe(false);
  });

  it("counts 'pending' / 'syncing' / 'failed' as unsynced; ignores 'synced'", async () => {
    const svc = new OffDeviceDurabilityService({
      ...defaultConfig({ forcedArchiveUnsyncedThreshold: 3 }),
      sqlSurface: adapter,
    });
    await seedEvent(adapter, { sequenceNumber: 1, syncStatus: 'pending' });
    await seedEvent(adapter, { sequenceNumber: 2, syncStatus: 'syncing' });
    await seedEvent(adapter, { sequenceNumber: 3, syncStatus: 'failed' });
    await seedEvent(adapter, { sequenceNumber: 4, syncStatus: 'synced' });
    expect(await svc.unsyncedRisk()).toBe('elevated'); // 3 unsynced ≥ 3 threshold
    expect(await svc.shouldForceArchive()).toBe(true);
  });

  // -------------------------------------------------------------------
  // shouldForceArchive() — independent of unsyncedRisk
  // -------------------------------------------------------------------

  it("returns false from shouldForceArchive when below the forced-archive threshold", async () => {
    const svc = new OffDeviceDurabilityService({
      ...defaultConfig({ forcedArchiveUnsyncedThreshold: 50 }),
      sqlSurface: adapter,
    });
    await seedUnsyncedEvents(adapter, 49);
    expect(await svc.shouldForceArchive()).toBe(false);
  });

  it('shouldForceArchive triggers at the exact threshold boundary (≥ count)', async () => {
    const svc = new OffDeviceDurabilityService({
      ...defaultConfig({ forcedArchiveUnsyncedThreshold: 10 }),
      sqlSurface: adapter,
    });
    await seedUnsyncedEvents(adapter, 10);
    expect(await svc.shouldForceArchive()).toBe(true);
  });
});
