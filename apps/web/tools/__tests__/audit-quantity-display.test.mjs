// @ts-check
import { describe, it, expect } from 'vitest';

import {
  scanCode,
  partitionViolationsByBaseline,
  violationBaselineKey,
} from '../audit-quantity-display.mjs';

/**
 * Guard 4 (UoM display precision) scanner coverage. Pins the detection
 * contract from spec 2026-07-20 §3.4.4 and the shrink-only baseline ratchet.
 */
describe('audit-quantity-display scanner', () => {
  describe('violation detection', () => {
    it('flags a raw member-rendered requested_qty', () => {
      const v = scanCode(`
        export function Row({ line }) {
          return <span>{line.requested_qty}</span>;
        }
      `, 'apps/web/src/features/replenishment/Row.tsx');
      expect(v).toHaveLength(1);
      expect(v[0].identifier).toBe('line.requested_qty');
    });

    it('flags a raw bare suggested_qty identifier', () => {
      const v = scanCode(`
        export function Row({ suggested_qty }) {
          return <span>{suggested_qty}</span>;
        }
      `, 'apps/web/src/features/replenishment/Row.tsx');
      expect(v).toHaveLength(1);
      expect(v[0].identifier).toBe('suggested_qty');
    });

    it('flags member-rendered quantity but NOT a bare quantity state var', () => {
      const member = scanCode(`
        export function Row({ line }) {
          return <span>{line.quantity}</span>;
        }
      `, 'apps/web/src/features/replenishment/Row.tsx');
      expect(member).toHaveLength(1);

      const bare = scanCode(`
        export function Row() {
          const [quantity] = useState('1');
          return <span>{quantity}</span>;
        }
      `, 'apps/web/src/features/replenishment/Row.tsx');
      expect(bare).toEqual([]);
    });

    it('flags a raw value attribute on a plain input but exempts <QuantityInput>', () => {
      const rawInput = scanCode(`
        export function Row({ line }) {
          return <input value={line.received_qty} />;
        }
      `, 'apps/web/src/features/purchases/Row.tsx');
      expect(rawInput).toHaveLength(1);

      const atom = scanCode(`
        export function Row({ line }) {
          return <QuantityInput value={line.received_qty} decimalPlaces={0} />;
        }
      `, 'apps/web/src/features/purchases/Row.tsx');
      expect(atom).toEqual([]);
    });

    it('exempts QuantityCell values while raw input and display sites remain violations', () => {
      const cell = scanCode(`
        export function Row({ line }) {
          return <QuantityCell value={line.quantity} decimalPlaces={0} onChange={() => {}} />;
        }
      `, 'apps/web/src/features/documents/Row.tsx');
      expect(cell).toEqual([]);

      const rawInput = scanCode(`
        export function Row({ line }) {
          return <input value={line.quantity} />;
        }
      `, 'apps/web/src/features/documents/Row.tsx');
      expect(rawInput).toHaveLength(1);

      const rawDisplay = scanCode(`
        export function Row({ line }) {
          return <span>{line.quantity}</span>;
        }
      `, 'apps/web/src/features/documents/Row.tsx');
      expect(rawDisplay).toHaveLength(1);
    });
  });

  describe('canonical formatQuantity wrap exempts (import resolution)', () => {
    it('exempts a web lib/decimal formatQuantity wrap (aliased import too)', () => {
      const v = scanCode(`
        import { formatQuantity } from '@/lib/decimal';
        export function Row({ line }) {
          return <span>{formatQuantity(line.requested_qty, 0)}</span>;
        }
      `, 'apps/web/src/features/replenishment/Row.tsx');
      expect(v).toEqual([]);

      const aliased = scanCode(`
        import { formatQuantity as toQty } from '../../../lib/decimal';
        export function Row({ line }) {
          return <span>{toQty(line.requested_qty, 0)}</span>;
        }
      `, 'apps/web/src/features/replenishment/Row.tsx');
      expect(aliased).toEqual([]);
    });

    it('exempts a pos lib/quantity formatQuantity wrap', () => {
      const v = scanCode(`
        import { formatQuantity } from '@/lib/quantity';
        export function Row({ product }) {
          return <span>{formatQuantity(product.suggested_qty, 0)}</span>;
        }
      `, 'apps/pos/src/components/Row.tsx');
      expect(v).toEqual([]);
    });

    it('does NOT exempt a formatQuantity imported from the wrong module for the app', () => {
      // web file importing from lib/quantity (pos canonical) is not canonical.
      const v = scanCode(`
        import { formatQuantity } from '@/lib/quantity';
        export function Row({ line }) {
          return <span>{formatQuantity(line.requested_qty, 0)}</span>;
        }
      `, 'apps/web/src/features/replenishment/Row.tsx');
      expect(v).toHaveLength(1);
    });

    it('does not flag the guard condition of a ternary whose branch is a canonical wrap', () => {
      const v = scanCode(`
        import { formatQuantity } from '@/lib/decimal';
        export function Row({ line }) {
          return <span>{line.requested_qty !== null ? formatQuantity(line.requested_qty, 0) : '-'}</span>;
        }
      `, 'apps/web/src/features/replenishment/Row.tsx');
      expect(v).toEqual([]);
    });
  });

  describe('excluded identifiers are ignored', () => {
    it('ignores EXCLUDE-prefixed terminals and *_count', () => {
      const v = scanCode(`
        export function Row({ line }) {
          return (
            <div>
              <span>{line.total_quantity}</span>
              <span>{line.available_qty}</span>
              <span>{line.reserved_quantity}</span>
              <span>{line.item_count}</span>
              <span>{line.min_qty}</span>
            </div>
          );
        }
      `, 'apps/web/src/features/replenishment/Row.tsx');
      expect(v).toEqual([]);
    });
  });

  describe('baseline ratchet', () => {
    const violation = {
      file: 'apps/web/src/features/legacy/Old.tsx',
      line: 12,
      identifier: 'line.requested_qty',
    };

    it('tolerates a baselined violation (not new)', () => {
      const baseline = new Set([violationBaselineKey(violation)]);
      const result = partitionViolationsByBaseline([violation], baseline);
      expect(result.baselined).toEqual([violation]);
      expect(result.newViolations).toEqual([]);
      expect(result.staleBaselineEntries).toEqual([]);
    });

    it('fails on a NEW violation not present in the baseline', () => {
      const baseline = new Set([]);
      const result = partitionViolationsByBaseline([violation], baseline);
      expect(result.newViolations).toEqual([violation]);
      expect(result.baselined).toEqual([]);
    });

    it('fails on a STALE baseline entry that no longer matches a violation', () => {
      const baseline = new Set(['apps/web/src/features/legacy/Old.tsx:line.gone_qty']);
      const result = partitionViolationsByBaseline([], baseline);
      expect(result.staleBaselineEntries).toEqual([
        'apps/web/src/features/legacy/Old.tsx:line.gone_qty',
      ]);
      expect(result.newViolations).toEqual([]);
    });

    it('key is line-number-free so edits do not churn the baseline', () => {
      expect(violationBaselineKey({ ...violation, line: 999 })).toBe(
        violationBaselineKey({ ...violation, line: 1 }),
      );
    });
  });
});
