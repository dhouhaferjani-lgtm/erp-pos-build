/**
 * Round-2 T32-B2: factory for `OffDeviceDurabilityService` so the AppShell
 * has a single typed entry point to build the spec §12 control surface.
 *
 * **Phase 1 boundary.** The factory ships with a placeholder LAN-peer
 * configuration so the operator-visible indicator + the forced-archive gate
 * are reachable as soon as the company database is available. The actual
 * durability path config (operator-chosen destination + key custody type)
 * is Phase 2 work — it'll come from a settings UI + DB row + tenant policy
 * push. For Phase 1, the wire-up is what matters; the placeholder is the
 * stub-able seam the Phase 2 config loader will replace.
 *
 * The factory returns `null` if `tenantPolicy.offDeviceDurability` is
 * explicitly disabled — that's the "operator opt-out" knob Phase 1 still
 * carries for development environments. In production, opting out is
 * forbidden by spec §12 ("Phase 1 gate before any Phase 2 customer-facing
 * deployment") and the policy push will simply not carry the disable bit.
 *
 * The factory itself never throws. Any invalid config raises at the
 * `OffDeviceDurabilityService` constructor, which is where the typed errors
 * (`EmptyOffDeviceDurabilityConfigError`, `OnDeviceKeyCustodyForbiddenError`,
 * `InvalidDurabilityThresholdError`) belong — they propagate up through this
 * factory so the operational logging layer can surface them.
 */

import type Database from '@tauri-apps/plugin-sql';

import {
  OffDeviceDurabilityService,
  type OffDeviceDurabilityConfig,
  type OffDeviceDurabilityPath,
} from './OffDeviceDurabilityService';

/**
 * Phase 1 placeholder path. Phase 2 will replace this with a config-loader-
 * sourced path the operator selected (one of: encrypted-removable-archive /
 * lan-peer / nas / cloud-sync) with the operator-configured destination
 * address + key-custody type.
 */
function placeholderPhase1Path(): OffDeviceDurabilityPath {
  return {
    kind: 'lan-peer',
    peerUrl: 'http://durability-peer.invalid:7443',
    keyCustody: 'shared-secret-rotated',
  };
}

export interface DurabilityServiceFactoryConfig {
  /**
   * Optional override for the configured durability paths. Phase 1 default
   * is a single placeholder LAN-peer; Phase 2 will source this from the
   * operator's settings UI.
   */
  paths?: OffDeviceDurabilityPath[];
  /** Forced-archive threshold; the service applies the default (50) when undefined. */
  forcedArchiveUnsyncedThreshold?: number;
  /** Escalation threshold; the service applies the default (500) when undefined. */
  escalatedUnsyncedThreshold?: number;
  /** Age-warn threshold (seconds); the service applies the default (3600) when undefined. */
  unsyncedAgeWarnThresholdSeconds?: number;
}

/**
 * Build the durability service. Returns `null` when the database handle
 * isn't ready yet so AppShell can mount unconditionally and the polling
 * hook becomes a no-op until the company DB is established.
 */
export function buildOffDeviceDurabilityService(
  database: Database | null,
  config: DurabilityServiceFactoryConfig = {},
): OffDeviceDurabilityService | null {
  if (database === null) return null;

  const fullConfig: OffDeviceDurabilityConfig = {
    paths: config.paths ?? [placeholderPhase1Path()],
    forcedArchiveUnsyncedThreshold: config.forcedArchiveUnsyncedThreshold,
    escalatedUnsyncedThreshold: config.escalatedUnsyncedThreshold,
    unsyncedAgeWarnThresholdSeconds: config.unsyncedAgeWarnThresholdSeconds,
    sqlSurface: database,
  };

  return new OffDeviceDurabilityService(fullConfig);
}
