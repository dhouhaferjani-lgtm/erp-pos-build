import { describe, it, expect } from 'vitest';
import { buildZReceiptData } from '../printing';

describe('buildZReceiptData', () => {
  it('includes cash_counts + manager_name + variance_reason in the payload when present', () => {
    const data = buildZReceiptData({
      companyName: 'Acme Shop',
      formattedZNumber: 'Z0042',
      dateTime: '2026-04-25T10:00:00Z',
      terminalName: 'T1',
      operatorName: 'Alice',
      currencySymbol: '€',
      wasReused: false,
      cashCounts: [
        { code: 'CASH', name: 'Cash', expected: '150.00', actual: '155.00', variance: '5.00', direction: 'over' },
      ],
      managerName: 'Jean',
      varianceReason: 'till miscount',
      varianceSeverity: 'warning',
      aggregateVariance: '5.00',
    });
    expect(data.cash_counts).toHaveLength(1);
    const firstCount = data.cash_counts?.[0];
    expect(firstCount).toBeDefined();
    expect(firstCount?.code).toBe('CASH');
    expect(data.manager_name).toBe('Jean');
    expect(data.variance_reason).toBe('till miscount');
    expect(data.variance_severity).toBe('warning');
  });
});
