/**
 * Device-side mirror of the server `FiscalEventPayloadRegistry`.
 *
 * Resolves the `event_version` per `FiscalEventType` and exposes two
 * orthogonal classifiers:
 *
 *   - `isImplemented()` — Phase 1 implemented set (matches the server
 *     registry's implemented set so the parse path knows the payload shape).
 *   - `isServerOnly()` — spec v7 §11.0 server-authoring carve-out. Some
 *     "implemented" types are SERVER-ONLY: the server authors them, the
 *     device MUST NOT. `FiscalEventEngine.append()` reads this
 *     classification to reject the type at the device boundary
 *     (Task 26 round-2 closure of T26-P3, cross-language drift gate per
 *     Task 14 standing pattern).
 *
 * Phase 1 implements four event types:
 *   - SALE_RECEIPT             (device-authored)
 *   - CHAIN_BREAK_DETECTED     (device-authored)
 *   - CHAIN_RESTART            (device-authored)
 *   - TERMINAL_REGISTRY_SNAPSHOT (server-authored per §11.0 — device rejects)
 *
 * Spec v7 §11.0 server-only set (independent of implemented status — a
 * type can be reserved AND server-only, so the device-side rejection is
 * locked even before the type lands):
 *   - TERMINAL_REGISTRY_SNAPSHOT (implemented)
 *   - COMPANY_DAY_CLOSURE_MANIFEST (reserved)
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
 * type + version resolution + server-only classification; DTO construction
 * is server-side concern.
 */

export const FISCAL_EVENT_TYPES = [
  'SALE_RECEIPT',
  'CHAIN_BREAK_DETECTED',
  'CHAIN_RESTART',
  'TERMINAL_REGISTRY_SNAPSHOT',
  'COMPANY_DAY_CLOSURE_MANIFEST',
  'ACCOUNT_PAYMENT',
  'ACCOUNT_CHARGE',
  'ACCOUNT_STATUS_CHANGED',
  'OPERATOR_APPROVAL_GRANTED',
  'OVERRIDE_CREDIT_LIMIT',
  'OVERRIDE_ACCOUNT_STATUS',
  'OVERRIDE_DISCOUNT_LIMIT',
  'OVERRIDE_TENDER_TOLERANCE',
  'OVERRIDE_VOID_OR_RETURN',
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

const IMPLEMENTED_EVENT_TYPES = [
  'SALE_RECEIPT',
  'CHAIN_BREAK_DETECTED',
  'CHAIN_RESTART',
  'TERMINAL_REGISTRY_SNAPSHOT',
  'ACCOUNT_PAYMENT',
  'ACCOUNT_CHARGE',
  'ACCOUNT_STATUS_CHANGED',
  'OPERATOR_APPROVAL_GRANTED',
  'OVERRIDE_CREDIT_LIMIT',
  'OVERRIDE_ACCOUNT_STATUS',
  'OVERRIDE_DISCOUNT_LIMIT',
  'OVERRIDE_TENDER_TOLERANCE',
  'OVERRIDE_VOID_OR_RETURN',
  'OPENING_FLOAT',
  'CASH_IN',
  'CASH_OUT',
  'SAFE_DROP',
  'CASH_CORRECTION',
  'SESSION_OPEN',
  'SESSION_CLOSE',
  'X_REPORT',
  'Z_REPORT',
] as const satisfies readonly FiscalEventTypeValue[];

type ImplementedEventType = (typeof IMPLEMENTED_EVENT_TYPES)[number];

/**
 * Spec v7 §11.0 server-only set. Device-side `append()` MUST reject
 * these event types regardless of implementation status. Both members
 * remain in `FISCAL_EVENT_TYPES`; both remain server-resolvable for
 * payload DTO purposes; neither is device-authorable.
 *
 * COMPANY_DAY_CLOSURE_MANIFEST is listed here even though it's
 * reserved-not-implemented so the device boundary is locked BEFORE the
 * type lands — when the closure phase ships, no device-side code path
 * exists that could author it.
 */
const SERVER_ONLY_EVENT_TYPES = [
  'TERMINAL_REGISTRY_SNAPSHOT',
  'COMPANY_DAY_CLOSURE_MANIFEST',
  'ACCOUNT_STATUS_CHANGED',
] as const satisfies readonly FiscalEventTypeValue[];

type ServerOnlyType = (typeof SERVER_ONLY_EVENT_TYPES)[number];

export class FiscalEventTypeNotImplementedError extends Error {
  constructor(public readonly type: FiscalEventTypeValue) {
    super(
      `FiscalEventType ${type} has no Phase 1 payload handler; see Appendix A for the reserved-not-implemented set.`,
    );
    this.name = 'FiscalEventTypeNotImplementedError';
  }
}

/**
 * The `event_version` this build AUTHORS for a SALE / TRAINING receipt.
 *
 * D-1 (2026-08-25) moved it from 3 to 5: the sealed taxable base is now NET of
 * the ticket-level remise, ventilated pro-rata per rate. It is the SINGLE
 * cutover discriminator the server gates on — a v3 sale payload is still
 * accepted forever (older devices in the field), but a device that has this
 * build may never author one again.
 *
 * The PHP authority is
 * `FiscalPayloadConstraintValidator::SALE_RECEIPT_AUTHORED_EVENT_VERSION`.
 */
export const SALE_RECEIPT_AUTHORED_EVENT_VERSION = 5;

/**
 * v3-refund-chain-integration spec §2 — thrown by `eventVersionFor()` when
 * a payload-aware `SALE_RECEIPT` call resolves `invoice_type_code ===
 * 'VOID'`. VOID authoring does not exist on the device (§8/§17's
 * "explicitly NOT touched" list) — this is a hard, typed refusal, never a
 * silent fallback to a lower event version.
 */
export class VoidAuthoringProhibitedError extends Error {
  constructor() {
    super(
      'SALE_RECEIPT payload with invoice_type_code=VOID cannot be device-authored; VOID authoring is not implemented on this device.',
    );
    this.name = 'VoidAuthoringProhibitedError';
  }
}

/**
 * Reads `payload.invoice_type_code` defensively — `payload` is `unknown`
 * at this call site (the registry does not own a SALE_RECEIPT payload
 * type, per this file's own docblock), so this never throws on a
 * non-object/null payload; it simply yields `undefined`, which
 * `eventVersionFor()`'s exhaustive `switch` below treats as "unrecognized"
 * (fail-closed, §2's resolution table).
 */
function readInvoiceTypeCode(payload: unknown): unknown {
  if (typeof payload !== 'object' || payload === null) {
    return undefined;
  }
  return (payload as Record<string, unknown>)['invoice_type_code'];
}

export class FiscalEventPayloadRegistry {
  private readonly implemented = new Set<ImplementedEventType>(IMPLEMENTED_EVENT_TYPES);

  private readonly serverOnly = new Set<ServerOnlyType>(SERVER_ONLY_EVENT_TYPES);

  isImplemented(type: FiscalEventTypeValue): boolean {
    return this.implemented.has(type as ImplementedEventType);
  }

  /**
   * Spec v7 §11.0 — `true` for company-integrity event types the server
   * authors and the device MUST refuse. `FiscalEventEngine.append()`
   * checks this before resolving the version so the rejection lands
   * before any state mutation. See `ServerAuthoredEventTypeError`.
   */
  isServerOnly(type: FiscalEventTypeValue): boolean {
    return this.serverOnly.has(type as ServerOnlyType);
  }

  /**
   * v3-refund-chain-integration spec §2 — payload-aware overload.
   * `payload` is OPTIONAL and, when absent, resolution is byte-identical
   * to the pre-existing payload-less behavior (every call site in this
   * codebase that does not yet thread a payload keeps working unmodified
   * — the 192-line registry test's payload-less assertions are the green
   * baseline this signature must never break).
   *
   * Exact resolution table (spec §2, errata F7), `SALE_RECEIPT` only:
   *
   *   | second arg          | `invoice_type_code`     | resolution |
   *   |----------------------|--------------------------|------------|
   *   | absent                | n/a                       | `5`        |
   *   | present                | `'SALE'` / `'TRAINING'`   | `5`        |
   *   | present                | `'REFUND'`                | `4`        |
   *   | present                | `'VOID'`                  | throws `VoidAuthoringProhibitedError` |
   *   | present                | missing/non-string/other  | throws `FiscalEventTypeNotImplementedError` (fail-closed) |
   *
   * Every other implemented type ignores `payload` entirely and keeps
   * resolving to its existing fixed version (`1`) — this feature only
   * introduces version fan-out for `SALE_RECEIPT`.
   *
   * @throws FiscalEventTypeNotImplementedError when `type` is reserved but
   *         has no Phase 1 payload handler, OR (SALE_RECEIPT + payload
   *         present only) when `invoice_type_code` is missing, non-string,
   *         or not one of the four recognized literals.
   * @throws VoidAuthoringProhibitedError when `type === 'SALE_RECEIPT'`,
   *         `payload` is present, and `invoice_type_code === 'VOID'`.
   */
  eventVersionFor(type: FiscalEventTypeValue, payload?: unknown): number {
    if (!this.implemented.has(type as ImplementedEventType)) {
      throw new FiscalEventTypeNotImplementedError(type);
    }
    // SaleReceiptV5 (D-1 post-remise VAT base, owner ruling 2026-08-25):
    // SALE_RECEIPT seals `subtotal` / `vat_total` / `vat_breakdown[]` NET of
    // the ticket-level remise, ventilated pro-rata per rate, and each
    // breakdown row carries its `discount_allocated`. The server accepts
    // {1, 2, 3, 4, 5} for parse — v3 stays valid forever so receipts authored
    // by not-yet-upgraded devices keep projecting (forward-only); the device
    // AUTHORS 5 (sale/training) or 4 (refund, §2).
    if (type === 'SALE_RECEIPT') {
      if (payload === undefined) {
        return SALE_RECEIPT_AUTHORED_EVENT_VERSION;
      }
      const invoiceTypeCode = readInvoiceTypeCode(payload);
      switch (invoiceTypeCode) {
        case 'SALE':
        case 'TRAINING':
          return SALE_RECEIPT_AUTHORED_EVENT_VERSION;
        case 'REFUND':
          return 4;
        case 'VOID':
          throw new VoidAuthoringProhibitedError();
        default:
          // Missing, non-string, or an unrecognized value — fail-closed,
          // never silently defaults to 3 (spec §2, errata F7).
          throw new FiscalEventTypeNotImplementedError(type);
      }
    }
    return 1;
  }

  implementedTypes(): readonly FiscalEventTypeValue[] {
    return IMPLEMENTED_EVENT_TYPES;
  }

  serverOnlyTypes(): readonly FiscalEventTypeValue[] {
    return SERVER_ONLY_EVENT_TYPES;
  }
}
