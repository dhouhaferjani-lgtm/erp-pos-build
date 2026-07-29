import { describe, expect, it } from 'vitest';

import {
  SALE_RECEIPT_LINE_ITEM_KEYS_V2,
  SALE_RECEIPT_PAYLOAD_KEYS,
  SALE_RECEIPT_PAYLOAD_KEYS_V3,
} from '../FiscalEventEngine';
import { ACCOUNT_PAYMENT_PAYLOAD_KEYS } from '../payloads/AccountPaymentPayload';
import { ACCOUNT_CHARGE_PAYLOAD_KEYS } from '../payloads/AccountChargePayload';

describe('Fiscal payload PHP/TS key drift gates', () => {
  it('Pass 2A.TS — SALE_RECEIPT_PAYLOAD_KEYS byte-mirrors PHP PAYLOAD_KEYS', () => {
    const phpKeys = readPhpValidatorPayloadKeys('SALE_RECEIPT');
    const tsKeys = [...SALE_RECEIPT_PAYLOAD_KEYS].sort();

    expect(tsKeys).toEqual([...phpKeys].sort());
    expect(tsKeys).toHaveLength(28);
  });

  it('SALE_RECEIPT_PAYLOAD_KEYS_V3 byte-mirrors the PHP named const', () => {
    const phpKeys = readPhpNamedConst('SALE_RECEIPT_PAYLOAD_KEYS_V3');
    const tsKeys = [...SALE_RECEIPT_PAYLOAD_KEYS_V3];

    expect([...tsKeys].sort()).toEqual([...phpKeys].sort());
    expect(tsKeys).toHaveLength(30);
  });

  it('SALE_RECEIPT_PAYLOAD_KEYS_V3 is declared lexicographically sorted', () => {
    // The canonical encoder sorts by code unit; a declaration that already
    // matches makes every future insertion reviewable at a glance.
    const tsKeys = [...SALE_RECEIPT_PAYLOAD_KEYS_V3];
    expect(tsKeys).toEqual([...tsKeys].sort());
    expect(tsKeys.indexOf('cash_rounding_adjustment')).toBe(tsKeys.indexOf('buyer') + 1);
    expect(tsKeys.indexOf('cashier_id')).toBe(tsKeys.indexOf('cash_rounding_denomination') + 1);
  });

  it('the v1/v2 SALE_RECEIPT key set is frozen at 28 keys', () => {
    expect([...SALE_RECEIPT_PAYLOAD_KEYS]).toHaveLength(28);
    expect([...SALE_RECEIPT_PAYLOAD_KEYS]).not.toContain('cash_rounding_adjustment');
    expect([...SALE_RECEIPT_PAYLOAD_KEYS]).not.toContain('cash_rounding_denomination');
  });

  it('V3 is a strict superset of the frozen v1/v2 key set', () => {
    const v3 = new Set<string>(SALE_RECEIPT_PAYLOAD_KEYS_V3);
    for (const key of SALE_RECEIPT_PAYLOAD_KEYS) {
      expect(v3.has(key)).toBe(true);
    }
    expect(v3.size - SALE_RECEIPT_PAYLOAD_KEYS.length).toBe(2);
  });

  it('M4 — SALE_RECEIPT_LINE_ITEM_KEYS_V2 byte-mirrors the PHP validator V2 line-item list', () => {
    const phpKeys = readPhpSaleReceiptLineItemKeysV2();
    const tsKeys = [...SALE_RECEIPT_LINE_ITEM_KEYS_V2].sort();

    expect(tsKeys).toEqual([...phpKeys].sort());
    expect(tsKeys).toHaveLength(16);
  });

  it('Phase 2.7 — ACCOUNT_PAYMENT_PAYLOAD_KEYS byte-mirrors PHP PAYLOAD_KEYS', () => {
    const phpKeys = readPhpValidatorPayloadKeys('ACCOUNT_PAYMENT');
    const tsKeys = [...ACCOUNT_PAYMENT_PAYLOAD_KEYS].sort();

    expect(tsKeys).toEqual([...phpKeys].sort());
    expect(tsKeys).toHaveLength(20);
  });

  it('Phase 3.1 R4 — ACCOUNT_CHARGE_PAYLOAD_KEYS byte-mirrors PHP DTO PAYLOAD_KEYS', () => {
    const phpKeys = readPhpAccountChargePayloadKeys();
    const tsKeys = [...ACCOUNT_CHARGE_PAYLOAD_KEYS].sort();

    expect(tsKeys).toEqual([...phpKeys].sort());
    expect(tsKeys).toHaveLength(28);
  });
});

function readPhpValidatorPayloadKeys(eventType: 'SALE_RECEIPT' | 'ACCOUNT_PAYMENT'): string[] {
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const fs = require('node:fs') as typeof import('node:fs');
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const path = require('node:path') as typeof import('node:path');
  const candidates = [
    path.resolve(__dirname, '../../../../../../apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php'),
    path.resolve(__dirname, '../../../../../api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php'),
  ];
  const phpPath = candidates.find((p) => fs.existsSync(p));
  if (!phpPath) {
    throw new Error(
      `FiscalPayloadConstraintValidator.php not found at any candidate path: ${candidates.join(', ')}`,
    );
  }
  const src = fs.readFileSync(phpPath, 'utf8');
  const match = src.match(new RegExp(`'${eventType}'\\s*=>\\s*\\[([\\s\\S]*?)\\]`));
  if (!match) {
    throw new Error(`Could not locate '${eventType}' => [...] in ${phpPath}`);
  }
  const body = match[1] ?? '';
  const keys = Array.from(body.matchAll(/'([a-z_][a-z0-9_]*)'/g)).map((m) => m[1] as string);
  if (keys.length === 0) {
    throw new Error(`No keys extracted for ${eventType} from ${phpPath}`);
  }
  return keys;
}

/**
 * Read a `public const <NAME> = [...]` list of scalar string keys out of the
 * PHP validator. The map-entry reader above cannot parse a named const, and a
 * named const is exactly what v3 uses — v1/v2 events must keep rejecting the
 * cash-rounding keys as `payload_extra_field` forever.
 */
function readPhpNamedConst(constName: string): string[] {
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const fs = require('node:fs') as typeof import('node:fs');
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const path = require('node:path') as typeof import('node:path');
  const candidates = [
    path.resolve(__dirname, '../../../../../../apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php'),
    path.resolve(__dirname, '../../../../../api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php'),
  ];
  const phpPath = candidates.find((p) => fs.existsSync(p));
  if (!phpPath) {
    throw new Error(`FiscalPayloadConstraintValidator.php not found at: ${candidates.join(', ')}`);
  }
  const src = fs.readFileSync(phpPath, 'utf8');
  const match = src.match(new RegExp(`public const ${constName} = \\[([\\s\\S]*?)\\];`));
  if (!match) {
    throw new Error(`Could not locate ${constName} in ${phpPath}`);
  }
  const keys = Array.from((match[1] ?? '').matchAll(/'([a-z_][a-z0-9_]*)'/g)).map((m) => m[1] as string);
  if (keys.length === 0) {
    throw new Error(`No keys extracted from ${constName} in ${phpPath}`);
  }
  return keys;
}

function readPhpSaleReceiptLineItemKeysV2(): string[] {
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const fs = require('node:fs') as typeof import('node:fs');
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const path = require('node:path') as typeof import('node:path');
  const candidates = [
    path.resolve(__dirname, '../../../../../../apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php'),
    path.resolve(__dirname, '../../../../../api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php'),
  ];
  const phpPath = candidates.find((p) => fs.existsSync(p));
  if (!phpPath) {
    throw new Error(
      `FiscalPayloadConstraintValidator.php not found at any candidate path: ${candidates.join(', ')}`,
    );
  }
  const src = fs.readFileSync(phpPath, 'utf8');
  const match = src.match(/public const SALE_RECEIPT_LINE_ITEM_KEYS_V2 = \[([\s\S]*?)\];/);
  if (!match) {
    throw new Error(`Could not locate SALE_RECEIPT_LINE_ITEM_KEYS_V2 in ${phpPath}`);
  }
  const body = match[1] ?? '';
  const keys = Array.from(body.matchAll(/'([a-z_][a-z0-9_]*)'/g)).map((m) => m[1] as string);
  if (keys.length === 0) {
    throw new Error(`No keys extracted for SALE_RECEIPT_LINE_ITEM_KEYS_V2 from ${phpPath}`);
  }
  return keys;
}

function readPhpAccountChargePayloadKeys(): string[] {
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const fs = require('node:fs') as typeof import('node:fs');
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const path = require('node:path') as typeof import('node:path');
  const candidates = [
    path.resolve(__dirname, '../../../../../../apps/api/app/Modules/Fiscal/Domain/DTOs/AccountChargePayload.php'),
    path.resolve(__dirname, '../../../../../api/app/Modules/Fiscal/Domain/DTOs/AccountChargePayload.php'),
  ];
  const phpPath = candidates.find((p) => fs.existsSync(p));
  if (!phpPath) {
    throw new Error(`AccountChargePayload.php not found at any candidate path: ${candidates.join(', ')}`);
  }
  const src = fs.readFileSync(phpPath, 'utf8');
  const match = src.match(/public const PAYLOAD_KEYS = \[([\s\S]*?)\];/);
  if (!match) {
    throw new Error(`Could not locate AccountChargePayload::PAYLOAD_KEYS in ${phpPath}`);
  }
  const body = match[1] ?? '';
  const keys = Array.from(body.matchAll(/'([a-z_][a-z0-9_]*)'/g)).map((m) => m[1] as string);
  if (keys.length === 0) {
    throw new Error(`No keys extracted from AccountChargePayload::PAYLOAD_KEYS in ${phpPath}`);
  }
  return keys;
}
