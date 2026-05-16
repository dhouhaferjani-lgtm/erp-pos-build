/**
 * Device-side mirror of the server `FiscalEventPayloadRegistry`.
 *
 * Resolves the `event_version` per `FiscalEventType` and exposes an
 * `isImplemented()` classifier for the four Phase 1 payload types. The
 * server (PHP) and device (TS) registries must agree on the implemented
 * set; the unit-level invariant is locked by their shared test surfaces.
 *
 * Phase 1 implements four event types:
 *   - SALE_RECEIPT
 *   - CHAIN_BREAK_DETECTED
 *   - CHAIN_RESTART
 *   - TERMINAL_REGISTRY_SNAPSHOT
 *
 * Every other case in `FISCAL_EVENT_TYPES` (the full Appendix A vocabulary)
 * is RESERVED at the device level so the chain can extend in later phases
 * without renumbering. `FiscalEventEngine.append()` (Task 15) calls
 * `eventVersionFor()` and surfaces `FiscalEventTypeNotImplementedError`
 * to the caller, which lets the checkout / fiscal flow fail fast at the
 * device boundary rather than letting a half-typed payload land in
 * `fiscal_events` and ride the sync to the server.
 *
 * The TS registry intentionally does NOT instantiate payload DTO classes
 * — the device-side authoring path constructs the canonical payload from
 * existing in-memory state (Cart, voucher store, payment store) and feeds
 * it to `FiscalEventCanonicalEncoder` directly. The TS registry's role is
 * type + version resolution; DTO construction is server-side concern.
 */

export const FISCAL_EVENT_TYPES = [
  'SALE_RECEIPT',
  'CHAIN_BREAK_DETECTED',
  'CHAIN_RESTART',
  'TERMINAL_REGISTRY_SNAPSHOT',
  'COMPANY_DAY_CLOSURE_MANIFEST',
  'ACCOUNT_PAYMENT',
  'ACCOUNT_CHARGE',
  'ACCOUNT_REFUND',
  'ACCOUNT_PAYMENT_RECONCILED',
  'ACCOUNT_CREDIT_ISSUE',
  'ACCOUNT_CREDIT_USAGE',
  'DEPOSIT_RECEIPT',
  'IDENTITY_ALIAS_RECONCILED',
  'SALE_VOID',
  'SALE_CORRECTION',
  'REFUND_RECEIPT',
  'PARTIAL_REFUND',
  'RETURN_WITHOUT_RECEIPT',
  'OPENING_FLOAT',
  'CASH_IN',
  'CASH_OUT',
  'SAFE_DROP',
  'CASH_CORRECTION',
  'SESSION_OPEN',
  'SESSION_CLOSE',
  'X_REPORT',
  'Z_REPORT',
  'REPRINT_COPY',
] as const;

export type FiscalEventTypeValue = (typeof FISCAL_EVENT_TYPES)[number];

const PHASE_1_IMPLEMENTED = [
  'SALE_RECEIPT',
  'CHAIN_BREAK_DETECTED',
  'CHAIN_RESTART',
  'TERMINAL_REGISTRY_SNAPSHOT',
] as const satisfies readonly FiscalEventTypeValue[];

type Phase1ImplementedType = (typeof PHASE_1_IMPLEMENTED)[number];

export class FiscalEventTypeNotImplementedError extends Error {
  constructor(public readonly type: FiscalEventTypeValue) {
    super(
      `FiscalEventType ${type} has no Phase 1 payload handler; see Appendix A for the reserved-not-implemented set.`,
    );
    this.name = 'FiscalEventTypeNotImplementedError';
  }
}

export class FiscalEventPayloadRegistry {
  private readonly implemented = new Set<Phase1ImplementedType>(PHASE_1_IMPLEMENTED);

  isImplemented(type: FiscalEventTypeValue): boolean {
    return this.implemented.has(type as Phase1ImplementedType);
  }

  /**
   * @throws FiscalEventTypeNotImplementedError when `type` is reserved but
   *         has no Phase 1 payload handler.
   */
  eventVersionFor(type: FiscalEventTypeValue): number {
    if (!this.implemented.has(type as Phase1ImplementedType)) {
      throw new FiscalEventTypeNotImplementedError(type);
    }
    return 1;
  }

  implementedTypes(): readonly FiscalEventTypeValue[] {
    return PHASE_1_IMPLEMENTED;
  }
}
