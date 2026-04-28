import { describe, it, expect } from 'vitest';
import { computeCashCountSeverity } from '../cashCountValidation';

const settings = {
  cash_variance_over_soft: '1.0000',
  cash_variance_over_hard: '20.0000',
  cash_variance_under_soft: '1.0000',
  cash_variance_under_hard: '20.0000',
};

describe('computeCashCountSeverity (FE mirror of backend)', () => {
  it('balanced when aggregate signed sum is zero', () => {
    const r = computeCashCountSeverity(
      [
        { variance_amount: '5.0000' },   // over 5
        { variance_amount: '-5.0000' },  // under 5
      ],
      settings,
      4,
    );
    expect(r.severity).toBe('balanced');
    expect(r.aggregateAmount).toBe('0.0000');
    expect(r.direction).toBe('balanced');
  });

  it('warning when |aggregate| above soft and below hard', () => {
    const r = computeCashCountSeverity(
      [{ variance_amount: '3.0000' }, { variance_amount: '3.0000' }],
      settings,
      4,
    );
    expect(r.severity).toBe('warning');
    expect(r.direction).toBe('over');
  });

  it('critical when |aggregate| above hard', () => {
    const r = computeCashCountSeverity(
      [{ variance_amount: '-25.0000' }],
      settings,
      4,
    );
    expect(r.severity).toBe('critical');
    expect(r.direction).toBe('under');
    expect(r.aggregateAmount).toBe('-25.0000');
  });

  it('info when |aggregate| > 0 and <= soft', () => {
    const r = computeCashCountSeverity(
      [{ variance_amount: '0.5000' }],
      settings,
      4,
    );
    expect(r.severity).toBe('info');
  });
});
