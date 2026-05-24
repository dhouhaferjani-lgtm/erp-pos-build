import { describe, expect, it } from 'vitest';
import { FiscalEventCanonicalEncoder } from '../FiscalEventCanonicalEncoder';
import {
  goldenAccountPaymentCanonicalBytes,
  goldenAccountPaymentPayload,
} from '../payloads/AccountPaymentPayload';

describe('ACCOUNT_PAYMENT canonical parity', () => {
  it('encodes the golden ACCOUNT_PAYMENT fixture to the PHP-locked canonical bytes', () => {
    const encoder = new FiscalEventCanonicalEncoder();

    expect(encoder.encode(goldenAccountPaymentPayload())).toBe(goldenAccountPaymentCanonicalBytes);
  });
});
