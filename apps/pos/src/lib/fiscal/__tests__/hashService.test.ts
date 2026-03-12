import { describe, it, expect } from 'vitest';
import { computeFiscalHash, computeGenesisHash, type FiscalHashInput } from '../hashService';

describe('hashService', () => {
  describe('computeFiscalHash', () => {
    it('produces a 64-char hex SHA-256 hash', async () => {
      const input: FiscalHashInput = {
        previousHash: 'genesis-hash',
        receiptNumber: 'T001-2026-00000001',
        postedAt: '2026-03-12T10:00:00.000Z',
        total: '50.00',
        currency: 'EUR',
        vatBreakdown: [{ rate: '20', amount: '10.00' }],
        payments: [{ methodCode: 'CASH', amount: '50.00' }],
      };

      const hash = await computeFiscalHash(input);

      expect(hash).toMatch(/^[0-9a-f]{64}$/);
    });

    it('produces deterministic output for same input', async () => {
      const input: FiscalHashInput = {
        previousHash: 'abc123',
        receiptNumber: 'R-001',
        postedAt: '2026-01-01T00:00:00.000Z',
        total: '25.50',
        currency: 'EUR',
        vatBreakdown: [],
        payments: [{ methodCode: 'CASH', amount: '25.50' }],
      };

      const hash1 = await computeFiscalHash(input);
      const hash2 = await computeFiscalHash(input);

      expect(hash1).toBe(hash2);
    });

    it('produces different hash when previousHash differs', async () => {
      const base: FiscalHashInput = {
        previousHash: 'hash-a',
        receiptNumber: 'R-001',
        postedAt: '2026-01-01T00:00:00.000Z',
        total: '10.00',
        currency: 'EUR',
        vatBreakdown: [],
        payments: [{ methodCode: 'CASH', amount: '10.00' }],
      };

      const hash1 = await computeFiscalHash(base);
      const hash2 = await computeFiscalHash({ ...base, previousHash: 'hash-b' });

      expect(hash1).not.toBe(hash2);
    });

    it('produces different hash when total differs', async () => {
      const base: FiscalHashInput = {
        previousHash: 'same',
        receiptNumber: 'R-001',
        postedAt: '2026-01-01T00:00:00.000Z',
        total: '10.00',
        currency: 'EUR',
        vatBreakdown: [],
        payments: [{ methodCode: 'CASH', amount: '10.00' }],
      };

      const hash1 = await computeFiscalHash(base);
      const hash2 = await computeFiscalHash({ ...base, total: '20.00' });

      expect(hash1).not.toBe(hash2);
    });

    it('uses NO_VAT when vatBreakdown is empty', async () => {
      const input: FiscalHashInput = {
        previousHash: 'prev',
        receiptNumber: 'R-001',
        postedAt: '2026-01-01T00:00:00.000Z',
        total: '10.00',
        currency: 'EUR',
        vatBreakdown: [],
        payments: [{ methodCode: 'CASH', amount: '10.00' }],
      };

      // This should not throw
      const hash = await computeFiscalHash(input);
      expect(hash).toMatch(/^[0-9a-f]{64}$/);
    });

    it('uses NO_PAYMENT when payments is empty', async () => {
      const input: FiscalHashInput = {
        previousHash: 'prev',
        receiptNumber: 'R-001',
        postedAt: '2026-01-01T00:00:00.000Z',
        total: '10.00',
        currency: 'EUR',
        vatBreakdown: [{ rate: '20', amount: '2.00' }],
        payments: [],
      };

      const hash = await computeFiscalHash(input);
      expect(hash).toMatch(/^[0-9a-f]{64}$/);
    });

    it('sorts vatBreakdown entries for consistency', async () => {
      const inputA: FiscalHashInput = {
        previousHash: 'prev',
        receiptNumber: 'R-001',
        postedAt: '2026-01-01T00:00:00.000Z',
        total: '100.00',
        currency: 'EUR',
        vatBreakdown: [
          { rate: '20', amount: '16.00' },
          { rate: '10', amount: '4.00' },
        ],
        payments: [{ methodCode: 'CASH', amount: '100.00' }],
      };

      const inputB: FiscalHashInput = {
        ...inputA,
        vatBreakdown: [
          { rate: '10', amount: '4.00' },
          { rate: '20', amount: '16.00' },
        ],
      };

      const hashA = await computeFiscalHash(inputA);
      const hashB = await computeFiscalHash(inputB);

      expect(hashA).toBe(hashB);
    });

    it('sorts payment entries for consistency', async () => {
      const inputA: FiscalHashInput = {
        previousHash: 'prev',
        receiptNumber: 'R-001',
        postedAt: '2026-01-01T00:00:00.000Z',
        total: '100.00',
        currency: 'EUR',
        vatBreakdown: [],
        payments: [
          { methodCode: 'CASH', amount: '60.00' },
          { methodCode: 'CARD', amount: '40.00' },
        ],
      };

      const inputB: FiscalHashInput = {
        ...inputA,
        payments: [
          { methodCode: 'CARD', amount: '40.00' },
          { methodCode: 'CASH', amount: '60.00' },
        ],
      };

      const hashA = await computeFiscalHash(inputA);
      const hashB = await computeFiscalHash(inputB);

      expect(hashA).toBe(hashB);
    });

    it('chain integrity: hash depends on previous hash', async () => {
      const genesis = await computeGenesisHash('terminal-seed');

      const receipt1 = await computeFiscalHash({
        previousHash: genesis,
        receiptNumber: 'R-001',
        postedAt: '2026-01-01T10:00:00.000Z',
        total: '10.00',
        currency: 'EUR',
        vatBreakdown: [],
        payments: [{ methodCode: 'CASH', amount: '10.00' }],
      });

      const receipt2 = await computeFiscalHash({
        previousHash: receipt1,
        receiptNumber: 'R-002',
        postedAt: '2026-01-01T10:05:00.000Z',
        total: '20.00',
        currency: 'EUR',
        vatBreakdown: [],
        payments: [{ methodCode: 'CASH', amount: '20.00' }],
      });

      // All three should be unique
      expect(new Set([genesis, receipt1, receipt2]).size).toBe(3);
    });
  });

  describe('computeGenesisHash', () => {
    it('produces a 64-char hex hash', async () => {
      const hash = await computeGenesisHash('my-terminal-seed');
      expect(hash).toMatch(/^[0-9a-f]{64}$/);
    });

    it('is deterministic', async () => {
      const hash1 = await computeGenesisHash('seed');
      const hash2 = await computeGenesisHash('seed');
      expect(hash1).toBe(hash2);
    });

    it('different seeds produce different hashes', async () => {
      const hash1 = await computeGenesisHash('seed-a');
      const hash2 = await computeGenesisHash('seed-b');
      expect(hash1).not.toBe(hash2);
    });
  });
});
