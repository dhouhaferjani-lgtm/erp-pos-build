import { useEffect } from 'react';
import { useBootstrapStore } from '@/stores/bootstrapStore';

/**
 * AppRouter-side telemetry hook — fires a structured `console.error`
 * whenever the bootstrap state machine transitions into `error`. Keeps
 * the production logging consistent with the rest of the T0.1
 * instrumentation without forcing every consumer to remember to log.
 *
 * Lives in its own file (not next to BootstrapErrorScreen) so the
 * component module stays component-only — required for Vite fast refresh
 * to swap renders without a full reload.
 */
export function useBootstrapErrorTelemetry(): void {
  const error = useBootstrapStore((s) => s.error);
  const phase = useBootstrapStore((s) => s.phase);

  useEffect(() => {
    if (phase !== 'error' || error === null) return;
    console.error('[POS][AppRouter][bootstrap] phase failed', {
      phase: error.phase,
      errorName: error.errorName,
      recoverable: error.recoverable,
      retryCount: error.retryCount,
    });
  }, [phase, error]);
}
