import type { BootstrapPhase } from '@/stores/bootstrapStore';

/**
 * Thrown when a bootstrap phase exceeds its per-phase timeout. Carries the
 * `phase` and `timeoutMs` so the bootstrapStore's error branch can render
 * a phase-aware error screen.
 *
 * Note: the cashier-facing error label uses `App.tsx`'s SAFE_ERROR_NAMES
 * allowlist; `BootstrapTimeoutError` is currently NOT in that allowlist,
 * so the bootstrapStore coerces it to the generic 'Error' label until
 * the allowlist is extended (Day 2 work). The class name is still useful
 * for engineering / structured logs.
 */
export class BootstrapTimeoutError extends Error {
  override name = 'BootstrapTimeoutError';

  constructor(
    public readonly phase: BootstrapPhase,
    public readonly timeoutMs: number,
  ) {
    super(`Bootstrap phase "${phase}" timed out after ${timeoutMs}ms`);
  }
}

/**
 * Thrown when the caller aborts a bootstrap phase before it (or its
 * timeout) settles. The wrapped work continues independently — the
 * existing init methods don't yet accept an AbortSignal, and grafting
 * cancellation onto them is out of scope for Day 1. The error name is
 * `'AbortError'` so it lands in `App.tsx::SAFE_ERROR_NAMES` allowlist.
 */
export class BootstrapAbortedError extends Error {
  override name = 'AbortError';

  constructor(public readonly phase: BootstrapPhase) {
    super(`Bootstrap phase "${phase}" was aborted by the caller`);
  }
}

/**
 * Race a wrapped promise against a per-phase timeout. Resolves with the
 * wrapped promise's value if it settles in time; rejects with
 * `BootstrapTimeoutError` if the timer fires first; rejects with
 * `BootstrapAbortedError` if the AbortSignal fires; propagates the
 * original rejection otherwise.
 *
 * Codex review (PR #106 round 1, P2): the optional AbortSignal both
 * cancels the timeout AND settles the outer promise. The wrapped work
 * itself continues independently — Day 2's AppRouter integration can
 * use the abort path to short-circuit a slow phase ("Use cached data")
 * without having to wait for the underlying network call to time out.
 */
export function withTimeout<T>(
  phase: BootstrapPhase,
  promise: Promise<T>,
  timeoutMs: number,
  signal?: AbortSignal,
): Promise<T> {
  return new Promise((resolve, reject) => {
    let settled = false;

    // Codex review (PR #106 round 2, P2): if the caller passes a signal
    // that is already aborted, `addEventListener('abort', ...)` will never
    // fire — the event has already happened. Short-circuit here so the
    // outer promise rejects immediately, instead of waiting the full
    // `timeoutMs` or the wrapped promise's settlement.
    //
    // Codex review (PR #106 round 3, P2): the wrapped promise is still
    // in flight even though we are short-circuiting. Without attaching a
    // rejection handler here, a later rejection from that underlying work
    // becomes an unhandled rejection (this `withTimeout` call is the only
    // consumer). Drain it with a no-op catch before bailing out.
    if (signal?.aborted) {
      promise.catch(() => {
        // Underlying init rejected after the caller aborted; we already
        // settled the outer promise with BootstrapAbortedError. Swallowing
        // here matches the normal abort path's behaviour, where the
        // settled-flag guard discards the late rejection.
      });
      reject(new BootstrapAbortedError(phase));
      return;
    }

    const timer = setTimeout(() => {
      if (settled) return;
      settled = true;
      signal?.removeEventListener('abort', onAbort);
      reject(new BootstrapTimeoutError(phase, timeoutMs));
    }, timeoutMs);

    const onAbort = (): void => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      reject(new BootstrapAbortedError(phase));
    };
    signal?.addEventListener('abort', onAbort, { once: true });

    promise.then(
      (value) => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        signal?.removeEventListener('abort', onAbort);
        resolve(value);
      },
      (error: unknown) => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        signal?.removeEventListener('abort', onAbort);
        reject(error as Error);
      },
    );
  });
}
