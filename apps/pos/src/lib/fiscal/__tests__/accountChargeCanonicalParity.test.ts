import { describe, expect, it } from 'vitest';
import { FiscalEventCanonicalEncoder } from '../FiscalEventCanonicalEncoder';
import {
  goldenAccountChargeCanonicalBytes,
  goldenAccountChargePayload,
} from '../payloads/AccountChargePayload';

describe('ACCOUNT_CHARGE canonical parity', () => {
  it('encodes the golden ACCOUNT_CHARGE fixture to the locked canonical bytes', () => {
    const encoder = new FiscalEventCanonicalEncoder();

    expect(encoder.encode(goldenAccountChargePayload())).toBe(goldenAccountChargeCanonicalBytes);
  });
});
