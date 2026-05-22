import { describe, expect, it } from 'vitest';
import {
  FiscalEventPayloadRegistry,
  FiscalEventTypeNotImplementedError,
  type FiscalEventTypeValue,
} from '../FiscalEventPayloadRegistry';

describe('FiscalEventPayloadRegistry', () => {
  const registry = new FiscalEventPayloadRegistry();

  it('marks implemented fiscal event types as implemented', () => {
    expect(registry.isImplemented('SALE_RECEIPT')).toBe(true);
    expect(registry.isImplemented('CHAIN_BREAK_DETECTED')).toBe(true);
    expect(registry.isImplemented('CHAIN_RESTART')).toBe(true);
    expect(registry.isImplemented('TERMINAL_REGISTRY_SNAPSHOT')).toBe(true);
    expect(registry.isImplemented('ACCOUNT_PAYMENT')).toBe(true);
    expect(registry.isImplemented('ACCOUNT_CHARGE')).toBe(true);
    expect(registry.isImplemented('ACCOUNT_STATUS_CHANGED')).toBe(true);
    expect(registry.isImplemented('OPERATOR_APPROVAL_GRANTED')).toBe(true);
    expect(registry.isImplemented('OVERRIDE_CREDIT_LIMIT')).toBe(true);
    expect(registry.isImplemented('OVERRIDE_ACCOUNT_STATUS')).toBe(true);
    expect(registry.isImplemented('OVERRIDE_DISCOUNT_LIMIT')).toBe(true);
    expect(registry.isImplemented('OVERRIDE_TENDER_TOLERANCE')).toBe(true);
    expect(registry.isImplemented('OVERRIDE_VOID_OR_RETURN')).toBe(true);
  });

  it('marks reserved-not-implemented types as unimplemented', () => {
    const reservedNotImplemented: FiscalEventTypeValue[] = [
      'COMPANY_DAY_CLOSURE_MANIFEST',
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
      'ACCOUNT_REFUND',
      'ACCOUNT_PAYMENT_RECONCILED',
      'ACCOUNT_CREDIT_ISSUE',
      'ACCOUNT_CREDIT_USAGE',
      'DEPOSIT_RECEIPT',
      'IDENTITY_ALIAS_RECONCILED',
    ];

    for (const type of reservedNotImplemented) {
      expect(registry.isImplemented(type), `${type} should be unimplemented`).toBe(false);
    }
  });

  it('returns eventVersion=1 for implemented types', () => {
    expect(registry.eventVersionFor('SALE_RECEIPT')).toBe(1);
    expect(registry.eventVersionFor('CHAIN_BREAK_DETECTED')).toBe(1);
    expect(registry.eventVersionFor('CHAIN_RESTART')).toBe(1);
    expect(registry.eventVersionFor('TERMINAL_REGISTRY_SNAPSHOT')).toBe(1);
    expect(registry.eventVersionFor('ACCOUNT_PAYMENT')).toBe(1);
    expect(registry.eventVersionFor('ACCOUNT_CHARGE')).toBe(1);
    expect(registry.eventVersionFor('ACCOUNT_STATUS_CHANGED')).toBe(1);
    expect(registry.eventVersionFor('OPERATOR_APPROVAL_GRANTED')).toBe(1);
    expect(registry.eventVersionFor('OVERRIDE_CREDIT_LIMIT')).toBe(1);
    expect(registry.eventVersionFor('OVERRIDE_ACCOUNT_STATUS')).toBe(1);
    expect(registry.eventVersionFor('OVERRIDE_DISCOUNT_LIMIT')).toBe(1);
    expect(registry.eventVersionFor('OVERRIDE_TENDER_TOLERANCE')).toBe(1);
    expect(registry.eventVersionFor('OVERRIDE_VOID_OR_RETURN')).toBe(1);
  });

  it('implements ACCOUNT_PAYMENT at version 1 without changing server-only types', () => {
    expect(registry.isImplemented('ACCOUNT_PAYMENT')).toBe(true);
    expect(registry.eventVersionFor('ACCOUNT_PAYMENT')).toBe(1);
    expect(registry.serverOnlyTypes()).toEqual([
      'TERMINAL_REGISTRY_SNAPSHOT',
      'COMPANY_DAY_CLOSURE_MANIFEST',
      'ACCOUNT_STATUS_CHANGED',
    ]);
  });

  it('implements ACCOUNT_CHARGE at version 1 without changing server-only types', () => {
    expect(registry.isImplemented('ACCOUNT_CHARGE')).toBe(true);
    expect(registry.eventVersionFor('ACCOUNT_CHARGE')).toBe(1);
    expect(registry.serverOnlyTypes()).toEqual([
      'TERMINAL_REGISTRY_SNAPSHOT',
      'COMPANY_DAY_CLOSURE_MANIFEST',
      'ACCOUNT_STATUS_CHANGED',
    ]);
  });

  it('throws FiscalEventTypeNotImplementedError when resolving a reserved type', () => {
    expect(() => registry.eventVersionFor('COMPANY_DAY_CLOSURE_MANIFEST')).toThrow(
      FiscalEventTypeNotImplementedError,
    );
    expect(() => registry.eventVersionFor('SALE_VOID')).toThrow(FiscalEventTypeNotImplementedError);
    expect(() => registry.eventVersionFor('Z_REPORT')).toThrow(FiscalEventTypeNotImplementedError);
  });

  it('throws with a message that names the reserved type', () => {
    let thrown: unknown;
    try {
      registry.eventVersionFor('SALE_VOID');
    } catch (error) {
      thrown = error;
    }
    expect(thrown).toBeInstanceOf(FiscalEventTypeNotImplementedError);
    expect((thrown as Error).message).toMatch(/SALE_VOID/);
  });

  it('exposes a stable list of implemented types matching the server contract', () => {
    expect(new Set(registry.implementedTypes())).toEqual(
      new Set<FiscalEventTypeValue>([
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
      ]),
    );
  });

  // -------------------------------------------------------------------
  // Task 26 round-2 — spec §11.0 server-only classification
  //
  // Per spec v7 §11.0, company-integrity event types are SERVER-ONLY:
  // implemented at the registry/DTO level (so the parse path knows the
  // payload shape) but NOT device-authorable. `FiscalEventEngine.append()`
  // reads this classification to reject server-only types at the device
  // boundary.
  // -------------------------------------------------------------------

  it('marks TERMINAL_REGISTRY_SNAPSHOT as server-only (§11.0)', () => {
    expect(registry.isServerOnly('TERMINAL_REGISTRY_SNAPSHOT')).toBe(true);
  });

  it('marks COMPANY_DAY_CLOSURE_MANIFEST as server-only (§11.0)', () => {
    expect(registry.isServerOnly('COMPANY_DAY_CLOSURE_MANIFEST')).toBe(true);
  });

  it('marks ACCOUNT_STATUS_CHANGED as server-only administrative carve-out', () => {
    expect(registry.isServerOnly('ACCOUNT_STATUS_CHANGED')).toBe(true);
  });

  it('marks SALE_RECEIPT / CHAIN_BREAK_DETECTED / CHAIN_RESTART as NOT server-only (device-authored)', () => {
    expect(registry.isServerOnly('SALE_RECEIPT')).toBe(false);
    expect(registry.isServerOnly('CHAIN_BREAK_DETECTED')).toBe(false);
    expect(registry.isServerOnly('CHAIN_RESTART')).toBe(false);
    expect(registry.isServerOnly('ACCOUNT_PAYMENT')).toBe(false);
    expect(registry.isServerOnly('ACCOUNT_CHARGE')).toBe(false);
    expect(registry.isServerOnly('OPERATOR_APPROVAL_GRANTED')).toBe(false);
    expect(registry.isServerOnly('OVERRIDE_CREDIT_LIMIT')).toBe(false);
  });

  it('serverOnlyTypes() returns the stable §11.0 set', () => {
    expect(new Set(registry.serverOnlyTypes())).toEqual(
      new Set<FiscalEventTypeValue>([
        'TERMINAL_REGISTRY_SNAPSHOT',
        'COMPANY_DAY_CLOSURE_MANIFEST',
        'ACCOUNT_STATUS_CHANGED',
      ]),
    );
  });
});
