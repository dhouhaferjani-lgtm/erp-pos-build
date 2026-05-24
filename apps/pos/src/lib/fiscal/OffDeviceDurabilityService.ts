/**
 * `OffDeviceDurabilityService` — Phase 1 §12 conservation control surface.
 *
 * Authority: spec v7 §12 (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md`)
 * + plan §2676+ Task 32.
 *
 * Spec §12 verbatim: "Device authority is not survivable without off-device
 * conservation; an on-device backup encrypted with an on-device key is not a
 * conservation control. Phase 1 delivers: at least one off-device durability
 * path (encrypted removable archive / LAN peer / NAS / cloud-sync) with key
 * custody outside the terminal disk; the on-device AES-GCM copy as crash-
 * recovery only; an operator-visible unsynced-risk indicator; a forced
 * archive/export threshold; a maximum-unsynced escalation; a device-loss
 * incident register."
 *
 * **What this service owns** (Pass 1):
 *
 *   - The CONTROL surface — `availablePaths()` reports which off-device
 *     durability paths the operator has configured, and `keyCustody()`
 *     reports whether key material is held outside the terminal disk
 *     (must be 'external'; 'on-device' is rejected at construction with a
 *     typed error).
 *   - `unsyncedRisk()` reports the operator-visible risk level from
 *     (count of unsynced `fiscal_events` rows) + (age of the oldest unsynced
 *     row) using the configured thresholds.
 *   - `shouldForceArchive()` is the policy bit the operator workflow reads
 *     to decide whether to gate further authoring on a successful off-device
 *     archive push.
 *
 * **What this service does NOT own** (deferred to Phase 2):
 *
 *   - Actual archive TRANSFER mechanics — LAN peer push, NAS mount,
 *     encrypted-USB write, cloud-sync upload. Those live outside this
 *     service in operational glue; Pass 1 is the CONTROL surface that gates
 *     them.
 *   - The device-loss incident register itself — that's the server-side
 *     `device_loss_incidents` table + the `DeviceLossIncident` Eloquent
 *     model (Task 32 PHP side; spec §12).
 *   - The on-device AES-GCM crash-recovery copy — that already exists per
 *     the device's existing `.izipos_key` flow and is NOT a conservation
 *     control (spec §12 explicit carve-out).
 */

import type Database from '@tauri-apps/plugin-sql';

import type { SqlSurface } from './FiscalEventEngine';

// -------------------------------------------------------------------
// Configuration types
// -------------------------------------------------------------------

/**
 * Operator-visible risk level for the off-device durability state.
 *
 * `'normal'` — under the forced-archive threshold AND the oldest unsynced
 * event is younger than the age-warn threshold.
 *
 * `'elevated'` — either count ≥ forced-archive threshold OR oldest unsynced
 * event age ≥ age-warn threshold. The forced-archive bit (`shouldForceArchive`)
 * tracks the count side only — the age side is a warn-but-don't-force signal
 * so a single hour-old event doesn't gate authoring.
 *
 * `'escalated'` — count ≥ escalated threshold. This is the maximum-unsynced
 * escalation that spec §12 calls out; the operator workflow uses this to
 * surface the device-loss incident register UI.
 */
export type UnsyncedRiskLevel = 'normal' | 'elevated' | 'escalated';

/**
 * Where the off-device archive's encryption key lives. Spec §12 requires
 * key custody OUTSIDE the terminal disk; any path that uses 'on-device'
 * custody is rejected at construction via `OnDeviceKeyCustodyForbiddenError`.
 *
 * `'on-device'` is INCLUDED in the union not because it's valid but so the
 * runtime guard can match against it after a config-from-JSON erasure has
 * stripped the type narrowing. Production callers cannot construct an
 * on-device path through the discriminated `OffDeviceDurabilityPath` union
 * below; the rejection is defense-in-depth for config-loader code paths
 * that bypass the type system.
 */
export type KeyCustody =
  | 'external-token'
  | 'tpm-bound'
  | 'shared-secret-rotated'
  | 'kerberos'
  | 'ssh-key'
  | 'cloud-kms'
  | 'on-device';

/**
 * A configured off-device durability path. The four kinds map 1:1 to the
 * spec §12 enumeration (encrypted removable archive / LAN peer / NAS /
 * cloud-sync).
 *
 * Each kind carries the minimum metadata the operational transfer layer
 * needs to address the destination. The actual transfer is OUT OF SCOPE
 * for this service (Pass 1) — this surface only proves a configured path
 * exists and reports key custody.
 */
export type OffDeviceDurabilityPath =
  | {
      kind: 'encrypted-removable-archive';
      mountPoint: string;
      keyCustody: Exclude<KeyCustody, 'on-device'>;
    }
  | {
      kind: 'lan-peer';
      peerUrl: string;
      keyCustody: Exclude<KeyCustody, 'on-device'>;
    }
  | {
      kind: 'nas';
      nasUrl: string;
      keyCustody: Exclude<KeyCustody, 'on-device'>;
    }
  | {
      kind: 'cloud-sync';
      provider: string;
      keyCustody: Exclude<KeyCustody, 'on-device'>;
    };

/**
 * Construction config. Thresholds carry sensible defaults documented in the
 * plan (forced-archive 50, escalated 500, age-warn 3600s = 1 hour). The
 * `sqlSurface` is the structural SQLite handle (`SqlSurface` from
 * `FiscalEventEngine`) so the service does NOT depend on the Tauri Database
 * concrete type and stays testable against `SqliteTestAdapter`.
 *
 * Round-2 T32-P2: all three threshold knobs are OPTIONAL at the boundary —
 * the constructor normalizes missing fields to the documented defaults
 * (50 / 500 / 3600). Erased-type config from JSON / DB can omit any of them
 * without weakening the control surface; the constructor also validates that
 * supplied values are positive finite integers and that
 * `escalatedUnsyncedThreshold >= forcedArchiveUnsyncedThreshold`. Invalid
 * thresholds throw `InvalidDurabilityThresholdError` at construction.
 */
export interface OffDeviceDurabilityConfig {
  paths: OffDeviceDurabilityPath[];
  forcedArchiveUnsyncedThreshold?: number;
  escalatedUnsyncedThreshold?: number;
  /** Age (seconds) beyond which a single unsynced event escalates risk to 'elevated'. */
  unsyncedAgeWarnThresholdSeconds?: number;
  sqlSurface: Database | SqlSurface;
}

/**
 * Threshold defaults documented in the plan + spec §12. Exported so callers
 * (and tests) can reference them without duplicating the numeric values.
 */
export const DEFAULT_FORCED_ARCHIVE_UNSYNCED_THRESHOLD = 50;
export const DEFAULT_ESCALATED_UNSYNCED_THRESHOLD = 500;
export const DEFAULT_UNSYNCED_AGE_WARN_THRESHOLD_SECONDS = 3600;

// -------------------------------------------------------------------
// Errors
// -------------------------------------------------------------------

/**
 * Thrown at construction when `config.paths` is empty. Spec §12 mandates
 * "at least one off-device durability path" — a Phase 1 gate before any
 * Phase 2 customer-facing deployment.
 */
export class EmptyOffDeviceDurabilityConfigError extends Error {
  constructor() {
    super(
      'OffDeviceDurabilityService requires at least one configured off-device durability path (spec §12). ' +
        'On-device-only operation is forbidden — Phase 1 gate before Phase 2 customer-facing deployment.',
    );
    this.name = 'EmptyOffDeviceDurabilityConfigError';
  }
}

/**
 * Thrown at construction when any path's `keyCustody` is `'on-device'`. The
 * spec §12 verbatim: "an on-device backup encrypted with an on-device key
 * is not a conservation control." The discriminated `OffDeviceDurabilityPath`
 * union already prevents this at compile time; this runtime guard is defense-
 * in-depth for callers that build the config from JSON / DB rows where the
 * type narrowing has been erased.
 */
export class OnDeviceKeyCustodyForbiddenError extends Error {
  constructor(pathKind: string) {
    super(
      `OffDeviceDurabilityService path of kind '${pathKind}' declared keyCustody='on-device', ` +
        'which is forbidden by spec §12 — key custody MUST live outside the terminal disk. ' +
        'The .izipos_key on-device AES-GCM copy is crash-recovery only, not a conservation control.',
    );
    this.name = 'OnDeviceKeyCustodyForbiddenError';
  }
}

/**
 * Round-2 T32-P2: thrown at construction when one of the three threshold
 * knobs is supplied but malformed. Catches:
 *   - non-finite values (`NaN`, `Infinity`)
 *   - non-integer numbers (e.g. `0.5`)
 *   - zero or negative thresholds (a zero threshold would force-archive at
 *     the very first unsynced event, which inverts the spec semantics)
 *   - `escalatedUnsyncedThreshold < forcedArchiveUnsyncedThreshold` (the
 *     ordering must hold — escalation is the harder threshold)
 *
 * Erased-type configs from JSON / DB can land here; the typed error makes
 * the invalid-shape signal explicit for the operational logging layer.
 */
export class InvalidDurabilityThresholdError extends Error {
  constructor(reason: string) {
    super(
      `OffDeviceDurabilityService threshold config invalid: ${reason}. ` +
        'Required: forcedArchiveUnsyncedThreshold + escalatedUnsyncedThreshold + ' +
        'unsyncedAgeWarnThresholdSeconds are positive finite integers, and ' +
        'escalatedUnsyncedThreshold >= forcedArchiveUnsyncedThreshold.',
    );
    this.name = 'InvalidDurabilityThresholdError';
  }
}

// -------------------------------------------------------------------
// Service
// -------------------------------------------------------------------

/**
 * Narrow a `Database` / structural handle to the `SqlSurface` subset this
 * service uses (`select` only — Pass 1 is read-only).
 */
function asSql(handle: Database | SqlSurface): SqlSurface {
  return handle as unknown as SqlSurface;
}

/**
 * Round-2 T32-P2: validate + normalize a threshold knob. When `supplied` is
 * `undefined` the documented default applies; otherwise the value must be a
 * positive finite integer or `InvalidDurabilityThresholdError` is thrown.
 */
function normalizeThreshold(
  supplied: number | undefined,
  fallback: number,
  field: string,
): number {
  if (supplied === undefined) {
    return fallback;
  }
  if (typeof supplied !== 'number' || !Number.isFinite(supplied)) {
    throw new InvalidDurabilityThresholdError(
      `${field} must be a finite number; got ${String(supplied)}`,
    );
  }
  if (!Number.isInteger(supplied)) {
    throw new InvalidDurabilityThresholdError(
      `${field} must be an integer; got ${supplied}`,
    );
  }
  if (supplied <= 0) {
    throw new InvalidDurabilityThresholdError(
      `${field} must be > 0; got ${supplied}`,
    );
  }
  return supplied;
}

export class OffDeviceDurabilityService {
  private readonly sql: SqlSurface;

  /** Normalized + validated thresholds. See round-2 T32-P2. */
  private readonly forcedArchiveUnsyncedThreshold: number;

  private readonly escalatedUnsyncedThreshold: number;

  private readonly unsyncedAgeWarnThresholdSeconds: number;

  constructor(private readonly config: OffDeviceDurabilityConfig) {
    if (config.paths.length === 0) {
      throw new EmptyOffDeviceDurabilityConfigError();
    }
    // Defense-in-depth: even though the discriminated union prevents
    // on-device custody at compile time, a config built from JSON / DB
    // rows has had the narrowing erased. Reject at construction.
    for (const path of config.paths) {
      // Cast through `unknown` — at runtime the field may be any string.
      const custody = (path as unknown as { keyCustody?: string }).keyCustody;
      if (custody === 'on-device') {
        throw new OnDeviceKeyCustodyForbiddenError(path.kind);
      }
    }

    // Round-2 T32-P2: normalize + validate threshold knobs. Defaults match
    // the plan documentation (50 / 500 / 3600s). Each supplied value must be
    // a positive finite integer, and the escalation threshold must be
    // strictly >= the forced-archive threshold.
    this.forcedArchiveUnsyncedThreshold = normalizeThreshold(
      config.forcedArchiveUnsyncedThreshold,
      DEFAULT_FORCED_ARCHIVE_UNSYNCED_THRESHOLD,
      'forcedArchiveUnsyncedThreshold',
    );
    this.escalatedUnsyncedThreshold = normalizeThreshold(
      config.escalatedUnsyncedThreshold,
      DEFAULT_ESCALATED_UNSYNCED_THRESHOLD,
      'escalatedUnsyncedThreshold',
    );
    this.unsyncedAgeWarnThresholdSeconds = normalizeThreshold(
      config.unsyncedAgeWarnThresholdSeconds,
      DEFAULT_UNSYNCED_AGE_WARN_THRESHOLD_SECONDS,
      'unsyncedAgeWarnThresholdSeconds',
    );

    if (this.escalatedUnsyncedThreshold < this.forcedArchiveUnsyncedThreshold) {
      throw new InvalidDurabilityThresholdError(
        `escalatedUnsyncedThreshold (${this.escalatedUnsyncedThreshold}) must be >= ` +
          `forcedArchiveUnsyncedThreshold (${this.forcedArchiveUnsyncedThreshold})`,
      );
    }

    this.sql = asSql(config.sqlSurface);
  }

  /**
   * The list of configured off-device durability paths. Operator-visible
   * surface for the settings UI; the operational transfer layer reads this
   * to know which destinations to push to.
   */
  availablePaths(): readonly OffDeviceDurabilityPath[] {
    return this.config.paths;
  }

  /**
   * Where the off-device archive key material lives. Spec §12 requires
   * `'external'`; on-device custody is rejected at construction, so this
   * always returns `'external'` for a successfully-constructed service.
   *
   * Kept as a method (not a constant) so the operator UI can present the
   * same value the constructor validated, and so future Phase 2 extensions
   * (e.g. rotating between TPM-bound + cloud-KMS) have an extension point.
   */
  keyCustody(): 'external' {
    return 'external';
  }

  /**
   * Current operator-visible risk level. Reads (count of unsynced
   * `fiscal_events` rows) + (age of oldest unsynced row) and applies the
   * configured thresholds:
   *
   *   - escalated: count ≥ escalatedUnsyncedThreshold
   *   - elevated:  count ≥ forcedArchiveUnsyncedThreshold OR oldestAgeSeconds ≥ unsyncedAgeWarnThresholdSeconds
   *   - normal:    otherwise
   *
   * Unsynced = `sync_status IN ('pending', 'syncing', 'failed')`. Matches
   * the device migration's partial sync-pending index (Task 13).
   */
  async unsyncedRisk(): Promise<UnsyncedRiskLevel> {
    const count = await this.countUnsyncedFiscalEvents();
    if (count >= this.escalatedUnsyncedThreshold) {
      return 'escalated';
    }
    if (count >= this.forcedArchiveUnsyncedThreshold) {
      return 'elevated';
    }
    const oldestAgeSeconds = await this.oldestUnsyncedAgeSeconds();
    if (oldestAgeSeconds !== null && oldestAgeSeconds >= this.unsyncedAgeWarnThresholdSeconds) {
      return 'elevated';
    }
    return 'normal';
  }

  /**
   * Whether the operator workflow should gate further authoring on a
   * successful off-device archive push. True at count ≥ forced-archive
   * threshold; the age-based escalation does NOT force archive (it's a
   * warn-but-don't-force signal — a single hour-old event shouldn't gate
   * authoring, but a fleet of unsynced events should).
   */
  async shouldForceArchive(): Promise<boolean> {
    const count = await this.countUnsyncedFiscalEvents();
    return count >= this.forcedArchiveUnsyncedThreshold;
  }

  // -------------------------------------------------------------------
  // Internals — SQLite reads
  // -------------------------------------------------------------------

  /**
   * Count of `fiscal_events` rows with non-terminal sync state. Matches the
   * device migration's partial sync-pending index condition (Task 13:
   * `WHERE sync_status IN ('pending', 'syncing', 'failed')`) so the count
   * query uses the index.
   */
  private async countUnsyncedFiscalEvents(): Promise<number> {
    const rows = await this.sql.select<Array<{ unsynced_count: number }>>(
      `SELECT COUNT(*) AS unsynced_count
         FROM fiscal_events
        WHERE sync_status IN ('pending', 'syncing', 'failed')`,
    );
    const row = rows[0];
    if (!row) return 0;
    // node:sqlite returns COUNT(*) as a number; Tauri's plugin-sql does too.
    // Coerce defensively in case a future driver wraps it differently.
    return typeof row.unsynced_count === 'number'
      ? row.unsynced_count
      : Number(row.unsynced_count);
  }

  /**
   * Age (seconds) of the oldest unsynced `fiscal_events` row, or `null` if
   * none are unsynced. Reads `created_at` (TEXT ISO-8601 per Task 13's
   * device migration) and computes the delta against `Date.now()` so the
   * service does not depend on any SQLite date function (driver-portable
   * across both `node:sqlite` and Tauri's plugin-sql).
   */
  private async oldestUnsyncedAgeSeconds(): Promise<number | null> {
    const rows = await this.sql.select<Array<{ oldest_created_at: string | null }>>(
      `SELECT MIN(created_at) AS oldest_created_at
         FROM fiscal_events
        WHERE sync_status IN ('pending', 'syncing', 'failed')`,
    );
    const oldest = rows[0]?.oldest_created_at;
    if (!oldest) return null;
    const oldestMillis = Date.parse(oldest);
    if (Number.isNaN(oldestMillis)) return null;
    const deltaMillis = Date.now() - oldestMillis;
    if (deltaMillis < 0) return 0;
    return Math.floor(deltaMillis / 1000);
  }
}
