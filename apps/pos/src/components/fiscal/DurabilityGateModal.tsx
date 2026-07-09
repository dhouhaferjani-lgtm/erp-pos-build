/**
 * Round-2 T32-B2: blocking modal that gates fiscal authoring on a successful
 * operator acknowledgment when spec §12's forced-archive threshold has been
 * crossed.
 *
 * Spec §12 verbatim: "a forced archive/export threshold". The
 * `OffDeviceDurabilityService.shouldForceArchive()` policy bit is read into
 * `durabilityStore.forceArchiveRequired` by the polling hook
 * (`useFiscalDurabilityPolling`). When the gate is armed (force-archive
 * required AND no live acknowledgment grace), this modal shows over the
 * entire POS UI so the operator MUST interact with the durability surface
 * before authoring any further fiscal events.
 *
 * **Phase 1 stub action.** The "Acknowledge & continue" button records the
 * operator's deliberate action via `acknowledgeDurabilityGate()` — that
 * writes an in-memory grace window (`DURABILITY_ACKNOWLEDGMENT_GRACE_MS`,
 * default 1 hour) which the gate selector reads via `isDurabilityGateArmed`.
 * The actual off-device archive TRANSFER is Phase 2 work and not the
 * subject of §12; Phase 1's mandate is the *control surface*, and that
 * control surface is now reachable from the operator UI.
 *
 * The gate re-arms after the grace window expires so it remains a real
 * conservation control rather than a one-time dismissal — if the unsynced
 * backlog stays above threshold, the operator must re-acknowledge.
 */

import { useTranslation } from 'react-i18next';

import { isDurabilityGateArmed, useDurabilityStore } from '@/stores/durabilityStore';

export function DurabilityGateModal() {
  const { t } = useTranslation('fiscal');
  const gateArmed = useDurabilityStore(isDurabilityGateArmed);
  const acknowledgeDurabilityGate = useDurabilityStore(
    (s) => s.acknowledgeDurabilityGate,
  );

  if (!gateArmed) return null;

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="durability-gate-title"
      data-testid="durability-gate-modal"
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
    >
      <div className="bg-white rounded-panel shadow-xl max-w-lg w-full p-6 border border-red-300">
        <h2
          id="durability-gate-title"
          className="text-lg font-semibold text-red-900 mb-2"
        >
          {t('durabilityGate.title')}
        </h2>
        <p className="text-sm text-gray-800 mb-3">
          {t('durabilityGate.description')}
        </p>
        <p className="text-xs text-gray-500 mb-4 italic">
          {t('durabilityGate.phase2Note')}
        </p>
        <div className="flex justify-end">
          <button
            type="button"
            data-testid="durability-gate-acknowledge"
            onClick={acknowledgeDurabilityGate}
            className="bg-red-600 hover:bg-red-700 text-white font-medium px-4 py-2 rounded-ctl text-sm"
          >
            {t('durabilityGate.acknowledgeButton')}
          </button>
        </div>
      </div>
    </div>
  );
}
